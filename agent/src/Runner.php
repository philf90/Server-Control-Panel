<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/**
 * Die einzige Stelle, an der der Agent ein Programm startet.
 *
 * Vier Zusagen, die hier eingelöst werden und nirgends sonst:
 *
 * 1. **Positivliste mit absoluten Pfaden.** Aufrufer nennen einen logischen
 *    Namen („systemctl"), nie einen Pfad. Der Pfad wird nicht über $PATH
 *    gesucht — ein manipuliertes PATH-Element wäre bei einem Prozess als root
 *    der direkte Weg zu fremdem Code.
 * 2. **Keine Shell.** Argumente gehen als Array an proc_open. Es gibt keinen
 *    Punkt in diesem Programm, an dem aus Benutzereingaben eine
 *    Kommandozeile zusammengesetzt wird.
 * 3. **Feste Umgebung.** LC_ALL=C hält die Ausgabe in dem Format, das die
 *    Parser kennen. Auf einem deutsch eingestellten Server scheiterte das
 *    Auswerten sonst — und zwar still.
 * 4. **Gedeckelte Ausgabe und Zeitlimit.** Ein Programm mit endloser Ausgabe
 *    kann den Speicher des Agenten nicht füllen, ein hängendes nicht seine
 *    Prozesstabelle.
 */
final class Runner
{
    public const AUSGABE_MAX = 4 * 1024 * 1024;

    /** @var array<string,string> logischer Name => absoluter Pfad */
    private const PROGRAMME = [
        'systemctl' => '/usr/bin/systemctl',
        'journalctl' => '/usr/bin/journalctl',
        'nginx' => '/usr/sbin/nginx',
        'sshd' => '/usr/sbin/sshd',
        'php-fpm' => '/usr/sbin/php-fpm',
        'named-checkzone' => '/usr/bin/named-checkzone',
        'useradd' => '/usr/sbin/useradd',
        'userdel' => '/usr/sbin/userdel',
        'usermod' => '/usr/sbin/usermod',
        'groupadd' => '/usr/sbin/groupadd',
        'setquota' => '/usr/sbin/setquota',
        'repquota' => '/usr/sbin/repquota',
        'apt-get' => '/usr/bin/apt-get',
        'mysql' => '/usr/bin/mysql',
        'mysqldump' => '/usr/bin/mysqldump',
        'certbot' => '/usr/bin/certbot',
    ];

    private const UMGEBUNG = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
        'LC_ALL' => 'C',
        'LANG' => 'C',
        'HOME' => '/root',
        'TERM' => 'dumb',
    ];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Führt ein Programm aus der Positivliste aus.
     *
     * @param  list<string>  $argumente
     * @param  null|callable(string,string):void  $mitlesen  Erhält Ausgabezeilen, sobald sie anfallen
     */
    public function run(
        string $programm,
        array $argumente,
        int $zeitlimit = 60,
        ?callable $mitlesen = null,
        ?string $eingabe = null,
    ): Ergebnis {
        $pfad = self::PROGRAMME[$programm] ?? null;

        if ($pfad === null) {
            throw AgentException::denied(sprintf('Programm %s steht nicht auf der Positivliste.', $programm));
        }

        if (! is_executable($pfad)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                sprintf('%s ist auf diesem System nicht installiert.', $programm),
                ['pfad' => $pfad],
            );
        }

        foreach ($argumente as $i => $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw AgentException::badRequest(sprintf('Argument %d ist keine gültige Zeichenkette.', $i));
            }
        }

        $befehl = array_merge([$pfad], array_values($argumente));
        $beschreibung = [
            0 => $eingabe === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $begonnen = microtime(true);
        $rohre = [];
        $prozess = proc_open($befehl, $beschreibung, $rohre, '/', self::UMGEBUNG);

        if (! is_resource($prozess)) {
            throw AgentException::execFailed(sprintf('%s ließ sich nicht starten.', $programm));
        }

        if ($eingabe !== null) {
            fwrite($rohre[0], $eingabe);
            fclose($rohre[0]);
        }

        stream_set_blocking($rohre[1], false);
        stream_set_blocking($rohre[2], false);

        $ausgabe = ['stdout' => '', 'stderr' => ''];
        $gekuerzt = false;
        $frist = $begonnen + $zeitlimit;
        $abgelaufen = false;

        while (true) {
            $lesen = array_filter([1 => $rohre[1], 2 => $rohre[2]], static fn ($r) => is_resource($r) && ! feof($r));

            if ($lesen === []) {
                break;
            }

            if (microtime(true) >= $frist) {
                $abgelaufen = true;
                break;
            }

            $schreiben = null;
            $sonst = null;
            $bereit = @stream_select($lesen, $schreiben, $sonst, 0, 200000);

            if ($bereit === false) {
                break;
            }

            foreach ($lesen as $nummer => $rohr) {
                $stueck = fread($rohr, 65536);
                if ($stueck === false || $stueck === '') {
                    continue;
                }

                $kanal = $nummer === 1 ? 'stdout' : 'stderr';

                if (strlen($ausgabe[$kanal]) < self::AUSGABE_MAX) {
                    $ausgabe[$kanal] .= $stueck;
                    if (strlen($ausgabe[$kanal]) > self::AUSGABE_MAX) {
                        $ausgabe[$kanal] = substr($ausgabe[$kanal], 0, self::AUSGABE_MAX);
                        $gekuerzt = true;
                    }
                } else {
                    $gekuerzt = true;
                }

                if ($mitlesen !== null) {
                    foreach (explode("\n", rtrim($stueck, "\n")) as $zeile) {
                        $mitlesen($kanal, $zeile);
                    }
                }
            }
        }

        if ($abgelaufen) {
            proc_terminate($prozess, SIGTERM);
            usleep(300000);
            $stand = proc_get_status($prozess);
            if ($stand['running']) {
                proc_terminate($prozess, SIGKILL);
            }
        }

        foreach ([$rohre[1], $rohre[2]] as $rohr) {
            if (is_resource($rohr)) {
                fclose($rohr);
            }
        }

        $code = proc_close($prozess);
        $dauer = microtime(true) - $begonnen;

        $this->journal->befehl($befehl, $abgelaufen ? null : $code, $dauer);

        if ($abgelaufen) {
            throw new AgentException(
                AgentException::TIMEOUT,
                sprintf('%s hat das Zeitlimit von %d s überschritten.', $programm, $zeitlimit),
                ['programm' => $programm],
            );
        }

        return new Ergebnis($code, $ausgabe['stdout'], $ausgabe['stderr'], $gekuerzt, $dauer);
    }

    /** @return array<string,string> */
    public static function programme(): array
    {
        return self::PROGRAMME;
    }
}
