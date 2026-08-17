<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Cron;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Ops\SftpKeyApply;

/**
 * Die Datei eines Abonnements unter `/etc/cron.d` — ganz, oder gar nicht.
 *
 * ## Was in dieser Datei steht, und was nicht
 *
 * ```
 * # Von srvpanel verwaltet. Änderungen werden überschrieben.
 * MAILTO=""
 * PATH=/usr/local/bin:/usr/bin:/bin
 * 15 3 * * *	p1001	/usr/lib/srvpanel/cron-run 1234
 * ```
 *
 * **Kein einziges Zeichen davon kommt vom Kunden.** Der Befehl liegt unter
 * `/etc/srvpanel/cron/1234.cmd`, und `cron-run` übergibt ihn als *Argument* an
 * die Shell. Der Grund ist gemessen und keine Vorsichtsmassnahme (`docs/60 §7`):
 * Ein Zeilenumbruch im Befehl macht aus einer Zeile zwei, und die zweite darf
 * sich ihr Benutzerfeld selbst aussuchen — `root`. Der Prüfkörper hat genau das
 * getan, und die erzeugte Datei gehörte root.
 *
 * ## Die vier Arten, auf die cron eine Datei liegen lässt
 *
 * Alle vier sind gemessen (`docs/60 §5`), und alle vier sind hier abgedeckt,
 * weil jede einzelne den **kompletten** Zeitplan des Kunden abschaltet:
 *
 * | Grund | was cron sagt | wo es hier steht |
 * |---|---|---|
 * | Name mit `.` oder `+` | **nichts** | {@see self::name()} |
 * | gruppen-/weltschreibbar | `INSECURE MODE` | {@see self::write()} |
 * | fremder Besitzer | `WRONG FILE OWNER` | {@see self::write()} |
 * | kaputte Zeile, fehlendes Zeilenende | `Syntax error` / `Missing newline` | {@see Schedule}, {@see self::render()} |
 *
 * Der erste ist der gefährlichste, und zwar nicht wegen seiner Wirkung, sondern
 * wegen ihres Fehlens:
 *
 * > **Eine Ablehnung ohne Meldung ist von einem Nichtvorhandensein nicht zu
 * > unterscheiden.**
 *
 * ## Kein Neuladen, und kein `cron -t`
 *
 * cron liest `/etc/cron.d` von selbst neu, wenn sich der Zeitstempel ändert —
 * gemessen dauert das bis zu 60 Sekunden (`docs/60 §4`). Es gibt kein Signal zu
 * schicken und nichts, was dabei schiefgehen könnte.
 *
 * Und es gibt **kein `cron -t`**: Eine Prüfung wie bei `sshd_config` ist nicht
 * zu haben. Die Prüfung ist deshalb unsere und läuft vor dem Schreiben, nicht
 * danach. Die gute Nachricht daneben — ebenfalls gemessen — ist, dass ein
 * Fehler hier den Dienst nicht mitnimmt: cron verwirft die Datei und bedient
 * alle anderen weiter. Das ist der Unterschied zum sshd aus `docs/59`, und er
 * ist der Grund, warum hier kein Rückrollweg gebaut wird.
 */
final class CronFile
{
    /** Wo die Dateien liegen. */
    public const DIR = '/etc/cron.d';

    /** Wo die Befehle liegen — je Job eine Datei, `root:root 0640`. */
    public const COMMAND_DIR = '/etc/srvpanel/cron';

    /** Der Wrapper, der den Befehl als Argument an die Shell gibt. */
    public const RUNNER = '/usr/lib/srvpanel/cron-run';

    /** Der Namensanfang, an dem srvpanel seine Dateien wiedererkennt. */
    public const PREFIX = 'srvpanel-';

    /**
     * Der Kopf jeder Datei.
     *
     * `MAILTO=""` schliesst cron-eigene Post aus. Es ist dabei **nicht** die
     * Massnahme, für die man es halten könnte: Gemessen (`docs/60 §10`) geht die
     * Ausgabe ohne MTA ohnehin nirgendwohin, und einen MTA hat ein frisch
     * aufgesetzter Server nicht. Die Aufzeichnung durch `cron-run` ist deshalb
     * nicht die bequemere Art, an die Ausgabe zu kommen, sondern die einzige.
     *
     * `PATH` steht hier, weil cron sonst einen sehr kurzen setzt und der Kunde
     * einen Befehl schreibt, der in seiner Anmeldeumgebung läuft und hier nicht.
     * Gemessen hat der Job sieben Umgebungsvariablen.
     *
     * **`CRON_TZ` steht ausdrücklich nicht hier.** Gemessen (`docs/60 §11`):
     * Dieser cron kennt es nicht und reicht es als gewöhnliche
     * Umgebungsvariable an den Job durch. Es verschöbe also nicht den Zeitplan,
     * sondern nur die Uhr, auf die der Job selbst sieht — von zwei Wirkungen die
     * verwirrendere.
     */
    private const HEADER = [
        '# Von srvpanel verwaltet. Änderungen werden beim nächsten Speichern überschrieben.',
        'MAILTO=""',
        'PATH=/usr/local/bin:/usr/bin:/bin',
    ];

    /**
     * Der Dateiname eines Abonnements — geprüft gegen das, was cron liest.
     *
     * **Das ist ein Wächter und keine Fussnote.** Systembenutzer heissen `p`
     * plus Ziffern, also trifft der Name heute keine der beiden Fallen. Verlassen
     * tut sich darauf trotzdem etwas — und eine Ablehnung wegen des Namens ist
     * die einzige der vier, die cron **stumm** ausführt. Sie sähe im Panel aus
     * wie „läuft".
     *
     * @throws AgentException wenn cron den Namen nicht läse
     */
    public static function name(string $user): string
    {
        /*
         * cron nimmt in /etc/cron.d nur Namen aus Buchstaben, Ziffern,
         * Unterstrich und Bindestrich. Gemessen: `srvpanel.punkt` und
         * `srvpanel+plus` werden übergangen, `srvpanel_unterstrich` nicht — und
         * keiner der beiden Fälle steht im Protokoll.
         *
         * **Geprüft wird der Benutzer und nicht der zusammengesetzte Name**, und
         * das ist der Fund des eigenen Wächters beim ersten Lauf: Ein leerer
         * Benutzer ergibt `srvpanel-`, und das ist ein Dateiname, den cron
         * anstandslos liest. Die Prüfung wäre durchgegangen und hätte eine Datei
         * ohne Abonnement erlaubt.
         *
         * > **Ein Muster über das Ergebnis einer Verkettung sagt nichts über
         * > ihre Teile — der Anfang erfüllt es schon allein.**
         *
         * Ob der Name die Form eines Systembenutzers hat, ist eine **andere**
         * Frage, und die beantwortet
         * {@see \SrvPanel\Agent\Ops\SubscriptionProvision::systemUser()} für das
         * ganze Projekt. Hier steht nur, was cron von einem Dateinamen verlangt.
         */
        if (preg_match('/\A[A-Za-z0-9_-]+$/D', $user) !== 1) {
            throw AgentException::badRequest(
                'Aus diesem Systembenutzer entstünde ein Dateiname, den cron übergeht.',
                ['user' => $user, 'name' => self::PREFIX.$user],
            );
        }

        return self::PREFIX.$user;
    }

    /** Der volle Pfad der Datei eines Abonnements. */
    public static function path(string $user): string
    {
        return self::DIR.'/'.self::name($user);
    }

    /**
     * Die Befehlsdatei eines Jobs — mit dem Systembenutzer im Namen.
     *
     * **Der Name trägt den Mandanten, und das ist keine Kosmetik.** Hiesse die
     * Datei nur `1234.cmd`, gäbe es zwei Löcher: Der Weg zurück müsste raten,
     * welche Nummern zu welchem Abonnement gehören — ein pausierter Job steht in
     * keiner Cron-Datei und wäre damit unauffindbar —, und ein Fehler in der
     * Nummernvergabe liesse ein Abonnement die Befehlsdatei eines anderen
     * überschreiben. So ist beides eine Eigenschaft des Dateinamens statt einer
     * Sorgfalt beim Aufrufen.
     *
     * `cron-run` braucht die Nummer deshalb nicht allein zu deuten: Es kennt
     * seinen eigenen Benutzer ohnehin, weil es als er läuft.
     */
    public static function commandPath(string $user, int $job): string
    {
        return self::COMMAND_DIR.'/'.self::name($user).'-'.$job.'.cmd';
    }

    /** Das Muster, unter dem die Befehlsdateien eines Abonnements liegen. */
    public static function commandGlob(string $user): string
    {
        return self::COMMAND_DIR.'/'.self::name($user).'-*.cmd';
    }

    /**
     * Den Inhalt der Datei bauen — aus geprüften Zeitplänen und Nummern.
     *
     * **Der abschliessende Zeilenumbruch ist Pflicht und kein Stil.** Gemessen
     * (`docs/60 §9`): Fehlt er, verwirft cron die ganze Datei mit
     * `Missing newline before EOF`. Das ist derselbe Totalausfall wie eine
     * kaputte Zeile, nur an einer Stelle, an die niemand denkt.
     *
     * @param  list<array{id: int, schedule: array{minute: string, hour: string, day_of_month: string, month: string, day_of_week: string}}>  $jobs
     */
    public static function render(string $user, array $jobs): string
    {
        $lines = self::HEADER;

        foreach ($jobs as $job) {
            $lines[] = implode("\t", [
                Schedule::line($job['schedule']),
                $user,
                self::RUNNER.' '.$job['id'],
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Die Datei schreiben — atomar, mit den Rechten, die cron verlangt.
     *
     * Ersetzt wird über `rename`, nicht über `file_put_contents`: Das kürzt
     * zuerst und schreibt dann, und dazwischen liegt ein Zeitpunkt, an dem cron
     * eine halbe Datei liest. Dieselbe Überlegung wie in
     * {@see ManagedBlock::put()} — nur werden Rechte und
     * Eigentümer hier **gesetzt** statt von der vorhandenen Datei abgenommen:
     * Diese Datei gehört uns ganz, und was cron von ihr verlangt, ist gemessen
     * und nicht geerbt.
     *
     * @throws AgentException
     */
    public static function write(string $user, string $content): string
    {
        $path = self::path($user);
        $temporary = $path.'.srvpanel.'.getmypid();

        if (@file_put_contents($temporary, $content) === false) {
            throw AgentException::execFailed(
                sprintf('%s liess sich nicht schreiben.', basename($temporary)),
                ['path' => $temporary],
            );
        }

        try {
            /*
             * Erst die Rechte, dann das Umbenennen. Andersherum läge die Datei
             * einen Augenblick lang mit den Rechten da, die `file_put_contents`
             * ihr gegeben hat — und `INSECURE MODE` heisst für cron nicht
             * „später nochmal", sondern „diese Datei nicht".
             */
            if (! @chmod($temporary, 0o644) || ! @chown($temporary, 'root') || ! @chgrp($temporary, 'root')) {
                throw AgentException::execFailed(
                    'Die Rechte der Cron-Datei liessen sich nicht setzen.',
                    ['path' => $temporary],
                );
            }

            if (! @rename($temporary, $path)) {
                throw AgentException::execFailed(
                    sprintf('%s liess sich nicht ersetzen.', basename($path)),
                    ['path' => $path],
                );
            }
        } catch (AgentException $error) {
            @unlink($temporary);

            throw $error;
        }

        return $path;
    }

    /**
     * Die Datei wegnehmen — für ein Abonnement ohne Jobs und für den Rückbau.
     *
     * **Entfernt statt geleert.** Eine Datei mit nur einem Kopf und ohne Zeile
     * sähe aus wie „Zeitsteuerung eingerichtet, keine Jobs" und ist dasselbe wie
     * „keine Zeitsteuerung"; zwei Zustände für eine Sache sind einer zu viel.
     * Dieselbe Entscheidung wie bei `authorized_keys` in
     * {@see SftpKeyApply}.
     *
     * @return bool ob es etwas zu entfernen gab
     */
    public static function remove(string $user): bool
    {
        $path = self::path($user);

        if (! is_file($path)) {
            return false;
        }

        if (! @unlink($path)) {
            throw AgentException::execFailed(
                sprintf('%s liess sich nicht entfernen.', basename($path)),
                ['path' => $path],
            );
        }

        return true;
    }
}
