<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Apt;
use SrvPanel\Agent\AptLock;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Packages;

/**
 * Aktualisierungen installieren — abgesetzt, nicht ausgeführt.
 *
 * ## Warum über `systemd-run`
 *
 * **Weil der Lauf das Panel enthalten kann.** Steht `srvpanel` in der Liste,
 * beendet systemd beim Neustart der Unit deren ganze Kontrollgruppe — ein
 * apt-Lauf darin würde zwischen dem Auspacken und dem Einrichten
 * abgeschnitten, und zurückbliebe ein halb installiertes System ohne jemanden,
 * der es zurücknimmt. Dieselbe Überlegung wie bei {@see PanelUpdate}, dort seit
 * P0, und Abnahmepunkt 5 aus `docs/81 §4` fragt genau danach.
 *
 * Der Lauf geht deshalb in eine transiente Unit, seine Ausgabe in
 * {@see self::LOG} — eine offene Verbindung überlebt ihn nicht, eine Datei
 * schon. Die Datei steht im Katalog von `SrvPanel\Agent\Logs`; damit ist
 * das Protokoll **auch nach dem Neustart** vollständig lesbar, und zwar über
 * denselben Weg wie jedes andere Protokoll des Servers.
 *
 * ## Warum ein Skript und nicht `apt-get` unmittelbar
 *
 * Weil ein Lauf, der nichts bewirkt hat, mit **0** endet und wie Erfolg
 * aussieht — gemessen am 26. August 2026 (`docs/81 §2.3g`, U1). Das ist M5 an
 * einer vierten Stelle. {@see self::RUNNER} zählt vorher und nachher, wie viele
 * Aktualisierungen offen sind, schreibt das Ergebnis als letzte Zeile des
 * Protokolls und endet ungleich 0, wenn sich nichts geändert hat.
 *
 * > **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist keine
 * > Prüfung — er ist eine Zeile, die aussieht wie eine.**
 *
 * ## Die Paketnamen kommen aus apt und nicht aus der Anfrage
 *
 * `docs/81 §5`: *„Kein Freitext erreicht apt."* Gemessen (U4): Ein Name, der
 * wie eine Option aussieht, wird von apt **als Option** geschluckt —
 * `--reinstall` als Paketname ergibt „0 upgraded" und rc=0, wortlos.
 *
 * Geprüft wird deshalb gegen die Liste, die diese Operation **selbst** gerade
 * gelesen hat, und nicht gegen ein Muster: Ein Muster müsste jede Schreibweise
 * erraten, eine Liste muss nur vorhanden sein. Und sie wird hier frisch
 * erhoben und nicht von der Seite übernommen — die Seite kann Minuten alt sein.
 *
 * > **Eine Positivliste aus der eigenen vorigen Antwort lässt nichts durch,
 * > was nicht schon dastand.**
 */
final class SystemPackagesUpgrade implements Op
{
    /**
     * Wohin die Ausgabe des Laufs geht.
     *
     * Eine Datei und keine Verbindung: Wer den Fortschritt sehen will, liest
     * das Protokoll. Ein Lauf, der das Panel neu startet, reisst jede offene
     * Verbindung ab — die Datei bleibt.
     */
    public const LOG = '/var/log/srvpanel/upgrade.log';

    /**
     * Das Skript, das in der Unit läuft.
     *
     * Derselbe Ort wie `cron-run` seit P6: `/usr/lib/srvpanel`. Ein Skript und
     * keine Zeichenkette in PHP, weil shellcheck über `packaging/bin` fährt und
     * über eine Zeichenkette in PHP nichts fährt.
     *
     * **Und diese Begründung stimmte einen Tag lang nicht.** Am 26. August 2026
     * fuhr die CI über drei Dateien mit Namen — `php`, `php-fpm`, `srvpanel` —,
     * und weder dieses Skript noch `cron-run` standen darunter. Beide waren
     * sauber; das war Glück und keine Zusage.
     *
     * > **Eine Begründung, die eine Tatsache behauptet, ist so lange richtig,
     * > bis jemand die Tatsache ändert — und niemand liest die Begründung
     * > dabei.**
     *
     * `ShellCheckReachTest` hält den Satz jetzt an der Tatsache, in beide
     * Richtungen.
     */
    public const RUNNER = '/usr/lib/srvpanel/apt-run';

    /**
     * Was installiert werden kann.
     *
     * `all` nimmt, was `apt-get dist-upgrade` täte — also genau die Zahl, die
     * auf der Seite steht, samt dem, was dort daneben als „würde entfernt"
     * ausgewiesen ist. `security` und `packages` gehen über Namen.
     *
     * **`security` ist kein Schalter von apt.** Gemessen (U6):
     * `apt-get -t <suite>` ist kein Sicherheitsfilter, sondern eine andere
     * Kandidatenwahl — hier 140 statt 142 Pakete, also weniger und nicht
     * anders. Die Liste entsteht deshalb hier, aus derselben Lesung wie die
     * Positivliste.
     *
     * @var list<string>
     */
    public const MODES = ['all', 'security', 'packages'];

    public static function name(): string
    {
        return 'system.packages.upgrade';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $mode = Guard::enum($args['mode'] ?? null, self::MODES, 'mode');

        /*
         * **Die Sperre zuerst, und mit einem Satz über den laufenden Vorgang**
         * — Abnahmepunkt 8. Ohne sie käme die Meldung von dpkg, und die nennt
         * eine Datei statt eines Vorgangs.
         */
        AptLock::ensureFree($context);

        $context->progress(10, 'Paketstand wird gelesen');

        $bekannt = $this->upgradable($context);
        $namen = self::names($mode, $args['packages'] ?? [], $bekannt);

        $unit = AptLock::UNIT_PREFIX.bin2hex(random_bytes(4));

        /*
         * **Wo dieser Lauf im Log anfängt.** `systemd-run` hängt mit
         * `StandardOutput=append:` an, die Datei sammelt also Läufe. Wer
         * hinterher die letzte Zeile liest, liest womöglich das Urteil des
         * vorigen — die Falle, die im Abnahmelauf eine Beobachtung gekostet
         * hat (`docs/86`, Beobachtung 17).
         *
         * > **Ein Urteil in einer Datei, die mehrere Läufe sammelt, gehört an
         * > die Stelle gebunden, an der der eigene Lauf begonnen hat.**
         *
         * Genommen **vor** dem Absetzen, denn danach schreibt der Lauf schon.
         */
        $versatz = is_file(self::LOG) ? (filesize(self::LOG) ?: 0) : 0;

        $context->progress(40, 'Lauf wird abgesetzt');

        $result = $context->runner->run('systemd-run', array_merge([
            '--unit='.$unit,
            '--collect',

            /*
             * **Die Beschreibung trägt, was der Unitname nicht trägt.**
             * {@see AptLock::UNIT_PREFIX} lautet `srvpanel-update-`, und die
             * Suche danach ist die Stelle, an der ein zweiter Lauf abgewiesen
             * wird. Ein eigener Präfix für das Installieren wäre ein zweiter
             * Name für dieselbe Frage — und die Suche fände ihn nicht.
             */
            '--description=Aktualisierungen installieren ('.$mode.')',
            '--property=StandardOutput=append:'.self::LOG,
            '--property=StandardError=append:'.self::LOG,
            '--setenv=DEBIAN_FRONTEND=noninteractive',
            self::RUNNER,
            $mode === 'all' ? 'all' : 'packages',
        ], $namen), 30);

        if (! $result->successful()) {
            throw AgentException::execFailed(
                'Der Lauf ließ sich nicht absetzen: '.$result->message(),
            );
        }

        $context->progress(100, 'läuft');

        return [
            /*
             * **Der Aufruf ist fertig, der Lauf ist es nicht.** Ohne diese
             * Marke ruft {@see \App\Jobs\RunAgentOperation} `succeed()`,
             * sobald der Agent zurückkehrt — und der Vorgang steht auf
             * `fertig`, während `apt-get` noch läuft (`docs/86 §5`).
             *
             * > **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt
             * > über den Ausgang dessen, was er abgesetzt hat, nichts.**
             *
             * Sie steht im **Ergebnis** und nicht in einer Liste im Panel: Das
             * Ergebnis ist der Vertrag zwischen Agent und Anwendung, eine
             * Liste wäre dessen zweite Fassung — und die zweite veraltet.
             */
            'dispatched' => true,
            'run' => 'upgrade',
            'log_offset' => $versatz,

            'unit' => $unit,
            'log' => self::LOG,
            'mode' => $mode,
            'packages' => $namen,

            // Woran der Lauf gemessen wird, wenn er fertig ist: Steht danach
            // dieselbe Zahl da, hat er nichts bewirkt — und {@see self::RUNNER}
            // schreibt genau das als letzte Zeile.
            'open_before' => count($bekannt),
        ];
    }

    /**
     * Was apt **jetzt** als aktualisierbar meldet — die Positivliste.
     *
     * @return array<string, bool> Name => ist ein Sicherheitsupdate
     */
    private function upgradable(Context $context): array
    {
        /*
         * **Über dieselbe transiente Unit wie der Lauf selbst**, und das ist
         * hier noch wichtiger als auf der Seite: Diese Liste ist die
         * Positivliste, gegen die {@see self::names()} prüft. Fragte sie
         * unmittelbar im Agenten, stünden darin phasenverzögerte Pakete, die
         * ein `dist-upgrade` gar nicht anfasst — und ein einzeln ausgewähltes
         * käme über `install <name>` trotzdem durch, weil ein ausdrücklicher
         * Name kein Phasing kennt.
         *
         * Damit hätten die beiden Knöpfe derselben Seite verschieden
         * entschieden, aus derselben Liste. (`docs/86`, Befund 6)
         */
        $laeufe = Apt::simulate($context, self::RUNNER);

        $liste = [];

        foreach (preg_split('/\R/', $laeufe['dist-upgrade']) ?: [] as $zeile) {
            $inst = Packages::inst($zeile);

            if ($inst !== null) {
                $liste[$inst['name']] = $inst['security'];
            }
        }

        return $liste;
    }

    /**
     * Die Namen für diesen Lauf — geprüft, nicht geglaubt.
     *
     * **Öffentlich und ohne {@see Context}, damit ein Wächter sie messen
     * kann.** Derselbe Schnitt wie bei {@see Packages::inst()} und
     * `SrvPanel\Agent\Apt::of()`: `Runner` und `Context` sind `final`,
     * es gibt also keine Attrappe, und ohne diese Naht wäre die Positivliste
     * nur über einen echten Server prüfbar — das heisst: gar nicht.
     *
     * > **Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test — und der
     * > Weg dahinter auch nicht.**
     *
     * `PackageNameTest` reicht hier die gemessenen Angriffe hinein:
     * `--reinstall`, `-y`, ein Name, den apt gar nicht kennt.
     *
     * @param  array<string, bool>  $bekannt  Name => ist ein Sicherheitsupdate
     * @return list<string>
     */
    public static function names(string $mode, mixed $angefragt, array $bekannt): array
    {
        if ($mode === 'all') {
            return [];
        }

        if ($mode === 'security') {
            return array_keys(array_filter($bekannt));
        }

        if (! is_array($angefragt) || $angefragt === []) {
            throw AgentException::badRequest('Es ist kein Paket ausgewählt.');
        }

        $namen = [];
        $fremd = [];

        foreach ($angefragt as $eintrag) {
            $name = is_string($eintrag) ? $eintrag : '';

            if (! array_key_exists($name, $bekannt)) {
                $fremd[] = $name;

                continue;
            }

            $namen[] = $name;
        }

        if ($fremd !== []) {
            /*
             * **Abgewiesen und nicht übergangen.** Ein Name, der in der Liste
             * nicht steht, ist entweder ein Angriff oder eine veraltete Seite —
             * und im zweiten Fall will der Betreiber es wissen, statt hinterher
             * ein Paket zu vermissen.
             */
            throw AgentException::badRequest(
                'Diese Pakete stehen nicht in der Liste der Aktualisierungen: '
                .implode(', ', array_map(
                    static fn (string $n): string => $n === '' ? '(leer)' : $n,
                    $fremd,
                )),
                ['packages' => $fremd],
            );
        }

        return array_values(array_unique($namen));
    }
}
