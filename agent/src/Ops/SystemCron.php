<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Cron\CronFile;
use SrvPanel\Agent\CronState;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Result;

/**
 * Welche Zeitpläne dieser Server fährt (A6, `docs/111 §3.1`).
 *
 * Lesend, ohne ein einziges Argument von aussen — dieselbe Bauart wie
 * `system.time` und `system.ports`. Sie **schreibt nichts**: Was der Admin an
 * `/etc/crontab` ändern will, ändert er als root, und ein Editor dafür wäre
 * Freitext mit Systemrechten über einen Umweg (`docs/80 §A6`).
 *
 * ## Warum die Verzeichnisse gesucht und nicht gewusst werden
 *
 * `/etc/cron.*` wird abgesucht, statt fünf Namen hinzuschreiben. Eine feste
 * Liste wäre eine zweite Fassung dessen, was auf der Platte liegt — und die
 * zweite ist die, die veraltet. Gemessen sind sechs Einträge (`cron.d` und
 * fünf Verzeichnisse); ein Server mit einem sechsten Verzeichnis erscheint
 * damit von selbst, und `cron.yearly` verschwindet nicht, wenn es jemand
 * anlegt, ohne diese Datei anzufassen.
 *
 * ## Warum `is_executable('/usr/sbin/anacron')` und nicht `command -v`
 *
 * Weil die Zeile in `/etc/crontab` genau das fragt: `test -x /usr/sbin/anacron`
 * (gemessen, `docs/81 §2.3t` M1). Eine andere Frage — etwa nach einem Paket
 * oder nach `$PATH` — beantwortete etwas anderes als die, an der der Zeitplan
 * hängt.
 *
 * ## Was diese Antwort ausdrücklich **nicht** sagt
 *
 * Ob eine Zeile für cron gültig ist. Gemessen (M4): Ein `@`-Name, den cron
 * nicht kennt, nimmt mit `Syntax error, this crontab file will be ignored` die
 * **ganze Datei** mit — und zu sehen ist das nur, wenn man cron startet. Ein
 * Nachbau seines Parsers wäre dessen zweite Fassung, und die zweite ist die,
 * die veraltet. `docs/111 §8` führt es als Grenze.
 */
final class SystemCron implements Op
{
    /** Wo die `cron.*`-Einträge liegen. Ein Muster und keine Liste — siehe Klassenkopf. */
    private const PATTERN = '/etc/cron.*';

    /** Der Pfad, den `/etc/crontab` selbst prüft (M1). */
    private const ANACRON = '/usr/sbin/anacron';

    public static function name(): string
    {
        return 'system.cron';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        return CronState::read(
            $this->files(),
            $this->directories($context),
            is_executable(self::ANACRON),
        );
    }

    /**
     * `/etc/crontab` und die Dateien unter `/etc/cron.d`, Pfad => Inhalt.
     *
     * `null` heisst „nicht lesbar" und nicht „leer" — eine Datei mit falschen
     * Rechten sähe sonst aus wie eine ohne Zeilen, und das ist genau der
     * Zustand, den diese Ansicht sichtbar machen soll.
     *
     * @return array<string, ?string>
     */
    private function files(): array
    {
        $dateien = [CronState::CRONTAB => $this->contents(CronState::CRONTAB)];

        // `glob` lässt Namen mit führendem Punkt aus, und das ist hier
        // richtig: `cron-daemon-common` legt ein `.placeholder` ab, das cron
        // ohnehin nie liest.
        foreach (glob(CronFile::DIR.'/*') ?: [] as $pfad) {
            if (is_file($pfad)) {
                $dateien[$pfad] = $this->contents($pfad);
            }
        }

        return $dateien;
    }

    /**
     * Die `cron.*`-Verzeichnisse samt dem, was `run-parts` von ihnen ausführt.
     *
     * @return array<string, array{names: list<string>, test: ?Result}>
     */
    private function directories(Context $context): array
    {
        $verzeichnisse = [];

        foreach (glob(self::PATTERN) ?: [] as $pfad) {
            // `/etc/cron.d` ist kein Skriptverzeichnis, sondern die zweite
            // Tabellenform — es steht in {@see self::files()}.
            if (! is_dir($pfad) || $pfad === CronFile::DIR) {
                continue;
            }

            $namen = @scandir($pfad);

            $verzeichnisse[$pfad] = [
                'names' => $namen === false ? [] : array_values(array_diff($namen, ['.', '..'])),
                // `--test` führt nichts aus, es druckt, **was** liefe — und es
                // braucht dafür kein root (gemessen, M2b). Die Namensregeln
                // kennt es besser als jeder Nachbau.
                'test' => $this->test($context, $pfad),
            ];
        }

        return $verzeichnisse;
    }

    /**
     * `run-parts --test` fragen — oder `null`, wenn es dieses System nicht hat.
     *
     * **Gefangen wird ausschliesslich `NOT_FOUND`.** Ein Zeitablauf oder eine
     * abgewiesene Positivliste sind etwas anderes als „nicht installiert", und
     * sie hier mitzufangen hiesse, einen Fehler als Abwesenheit auszugeben —
     * derselbe Fehler, den `catch (Throwable) { return []; }` in P5b gemacht
     * hat: aus „nicht erreichbar" wurde „der Betreiber bietet es nicht an".
     *
     * Dass es fehlt, ist unwahrscheinlich — `/etc/crontab` ruft es selbst —,
     * und trotzdem darf es nicht die ganze Seite mitnehmen: Die Tabellen sind
     * dann weiterhin lesbar, und nur die Skriptlisten sind es nicht.
     */
    private function test(Context $context, string $path): ?Result
    {
        try {
            return $context->runner->run('run-parts', ['--test', $path], 10);
        } catch (AgentException $fehler) {
            if ($fehler->errorCode === AgentException::NOT_FOUND) {
                return null;
            }

            throw $fehler;
        }
    }

    private function contents(string $path): ?string
    {
        $inhalt = @file_get_contents($path);

        return $inhalt === false ? null : $inhalt;
    }
}
