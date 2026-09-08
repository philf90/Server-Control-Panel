<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\CronState;
use SrvPanel\Agent\Result;

/**
 * Die Naht zu `run-parts` — A6, `docs/111 §5`.
 *
 * ## Die Regel, die dieser Wächter hält
 *
 * **`ignored` ist die Differenz zwischen dem Verzeichnisinhalt und
 * `run-parts --test`** und keine eigene Prüfung. Gemessen (`docs/81 §2.3t` M2)
 * übergeht `run-parts` sechs von zwölf Prüfkörpern wortlos — `backup.sh`,
 * `e.f`, `logrotate.dpkg-new`, `alt~`, `mit leerzeichen`, `nicht-ausfuehrbar`.
 *
 * > **Ein Skript, das nicht läuft, sieht im Verzeichnis genauso aus wie eines,
 * > das läuft.**
 *
 * Ein Nachbau dieser Regeln wäre ihre zweite Fassung, und die zweite ist die,
 * die veraltet — neben einem Werkzeug, das die Wahrheit kennt.
 *
 * ## Gemessen wird an der Wirkung
 *
 * Die Prüfkörper sind die **gemessene Ausgabe** von `run-parts --test`, nicht
 * eine erfundene: volle Pfade, einer je Zeile, in der Reihenfolge des
 * Werkzeugs. Wer sie mit `scandir` vergleicht, ohne `basename` zu nehmen,
 * meldet jedes Skript als übergangen — und der Fehler sähe aus wie ein Befund
 * über den Server.
 *
 * **Dass der Leser die Namensregeln nirgends nachbaut, steht in
 * {@see CronNameRuleTest} und nicht hier.** Der erste Wurf trug den Ausdruck an
 * beiden Stellen — und zwei Wächter über dieselbe Regel sind zwei Fassungen
 * davon, genau der Fehler, gegen den die Regel selbst geschrieben ist.
 */
final class RunPartsSeamTest extends TestCase
{
    /** Was in `/etc/cron.daily` lag (M2) — zwölf Namen und ein `.placeholder`. */
    private const NAMES = [
        'backup', 'sicherung-taeglich', '00-erstes', 'UPPER', 'a_b', 'c-d',
        'backup.sh', 'e.f', 'logrotate.dpkg-new', 'alt~', 'mit leerzeichen',
        'nicht-ausfuehrbar', '.placeholder',
    ];

    /** Und das ist, was `run-parts --test` dazu gedruckt hat — volle Pfade. */
    private const RUNS = "/etc/cron.daily/00-erstes\n"
        ."/etc/cron.daily/UPPER\n"
        ."/etc/cron.daily/a_b\n"
        ."/etc/cron.daily/backup\n"
        ."/etc/cron.daily/c-d\n"
        ."/etc/cron.daily/sicherung-taeglich\n";

    /**
     * Was `run-parts` ausführt, steht als Skript da — und nur das.
     */
    public function test_only_what_run_parts_names_is_a_script(): void
    {
        $verzeichnis = $this->verzeichnis(self::NAMES, self::RUNS);

        $this->assertSame(
            ['00-erstes', 'UPPER', 'a_b', 'backup', 'c-d', 'sicherung-taeglich'],
            $verzeichnis['scripts'],
            'Die Skriptliste kommt nicht aus der Ausgabe von run-parts.',
        );
    }

    /**
     * Und der Rest ist übergangen — die Differenz und nicht eine zweite Prüfung.
     */
    public function test_the_rest_is_the_difference(): void
    {
        $verzeichnis = $this->verzeichnis(self::NAMES, self::RUNS);

        $this->assertSame(
            ['backup.sh', 'e.f', 'logrotate.dpkg-new', 'alt~', 'mit leerzeichen', 'nicht-ausfuehrbar'],
            array_column($verzeichnis['ignored'], 'name'),
            'Übergangen ist nicht die Differenz aus Verzeichnis und run-parts.',
        );
    }

    /**
     * Eine versteckte Datei ist kein übergangenes Skript.
     *
     * **Gemessen:** `cron-daemon-common` legt in jedes `cron.*`-Verzeichnis ein
     * `.placeholder`, dessen eigener Inhalt „DO NOT EDIT OR REMOVE" sagt. Ohne
     * diese Grenze meldete der Bereich „Übergangen" auf **jedem heilen Server**
     * fünf Funde.
     *
     * > **Ein Wächter, der zu viel meldet, wird abgeschaltet — und zwar von
     * > dem, der ihn gebaut hat.**
     *
     * Und das ist **nicht** dasselbe wie ein Punkt *im* Namen: `backup.sh` ist
     * ein Skript, das jemand abgelegt hat und das nicht läuft. Die Prüfung
     * steht deshalb in beide Richtungen in einem Fall.
     */
    public function test_a_hidden_file_is_not_a_skipped_script(): void
    {
        $namen = array_column($this->verzeichnis(self::NAMES, self::RUNS)['ignored'], 'name');

        $this->assertNotContains('.placeholder', $namen, 'Die versteckte Datei steht als übergangenes Skript da.');
        $this->assertContains('backup.sh', $namen, 'Ein Punkt im Namen ist mit der versteckten Datei weggefallen.');
    }

    /**
     * Der Grund wird zugeordnet und nicht entschieden.
     *
     * Die **Entscheidung** kommt von `run-parts`; hier steht nur, was einem
     * Menschen dazu zu sagen ist. Ein Name, den die Zuordnung nicht kennt,
     * fällt auf `unknown` — und das heisst weiter „läuft nicht".
     */
    public function test_every_skipped_name_carries_a_reason(): void
    {
        $gruende = [];

        foreach ($this->verzeichnis(self::NAMES, self::RUNS)['ignored'] as $eintrag) {
            $gruende[$eintrag['name']] = $eintrag['reason'];
        }

        $this->assertSame('dot', $gruende['backup.sh']);
        $this->assertSame('dot', $gruende['logrotate.dpkg-new']);
        $this->assertSame('character', $gruende['alt~']);
        $this->assertSame('character', $gruende['mit leerzeichen']);

        // Der Name ist zulässig; hier entscheidet das Ausführbit — und weil
        // die Datei in diesem Lauf gar nicht existiert, ist sie es nicht.
        $this->assertSame('not-executable', $gruende['nicht-ausfuehrbar']);
    }

    /**
     * Ein Verzeichnis ist kein Skript, und `is_executable` sagt bei ihm Ja.
     *
     * Gemessen: `run-parts --test` übergeht ein Unterverzeichnis wortlos. Ohne
     * die Reihenfolge im Leser — Verzeichnis vor Ausführbit — stünde dort
     * `unknown`.
     */
    public function test_a_directory_is_named_as_one(): void
    {
        /*
         * **Dieser Fall braucht echte Dateien, und das ist kein Umstand,
         * sondern die Sache selbst.** `directory` und `not-executable`
         * unterscheiden sich nicht in der Nutzlast, sondern auf der Platte —
         * der Agent läuft dort, und der Leser fragt sie. Der erste Wurf dieses
         * Falls zeigte gegen ein Verzeichnis, das es nicht gab, und bekam
         * `not-executable`: eine Beschriftung, die stimmte, für einen Grund,
         * den der Prüfkörper nicht hergestellt hatte.
         *
         * > **Ein Prüfkörper, der den Zustand nicht herstellt, misst einen
         * > anderen — und sein Ergebnis sieht aus wie eines.**
         */
        $wurzel = sys_get_temp_dir().'/srvpanel-runparts-'.bin2hex(random_bytes(6));

        mkdir($wurzel.'/einverzeichnis', 0o755, true);
        file_put_contents($wurzel.'/laeuft', "#!/bin/sh\ntrue\n");
        chmod($wurzel.'/laeuft', 0o755);
        file_put_contents($wurzel.'/ohne-bit', "#!/bin/sh\ntrue\n");
        chmod($wurzel.'/ohne-bit', 0o644);

        try {
            $verzeichnis = $this->verzeichnis(
                ['laeuft', 'einverzeichnis', 'ohne-bit'],
                $wurzel."/laeuft\n",
                $wurzel,
            );

            $gruende = array_column($verzeichnis['ignored'], 'reason', 'name');

            $this->assertSame('directory', $gruende['einverzeichnis'], 'Ein Unterverzeichnis wird nicht als solches benannt.');

            // Die Gegenrichtung im selben Fall: Ein Verzeichnis trägt ein
            // Ausführbit, und ohne die Reihenfolge im Leser stünde bei beiden
            // dasselbe.
            $this->assertSame('not-executable', $gruende['ohne-bit']);
            $this->assertSame(['laeuft'], $verzeichnis['scripts']);
        } finally {
            @unlink($wurzel.'/laeuft');
            @unlink($wurzel.'/ohne-bit');
            @rmdir($wurzel.'/einverzeichnis');
            @rmdir($wurzel);
        }

        $this->assertDirectoryDoesNotExist($wurzel, 'Der Prüfstand ist nicht abgeräumt.');
    }

    /**
     * Antwortet `run-parts` nicht, ist die Skriptliste **unbekannt** und nicht leer.
     *
     * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
     * > tun".**
     *
     * Und es wird nichts als übergangen gemeldet: Wer nicht weiss, was läuft,
     * weiss auch nicht, was nicht läuft.
     */
    public function test_a_silent_run_parts_leaves_the_lists_unknown(): void
    {
        foreach ([null, new Result(1, '', 'run-parts: not a directory')] as $antwort) {
            $verzeichnis = $this->mit(self::NAMES, $antwort);

            $this->assertFalse($verzeichnis['readable'], 'Ein Verzeichnis ohne Antwort steht als lesbar da.');
            $this->assertSame([], $verzeichnis['scripts']);
            $this->assertSame([], $verzeichnis['ignored'], 'Ohne Antwort werden Dateien als übergangen gemeldet.');
        }
    }

    /**
     * Ohne diese Zahlen wären die Behauptungen oben auch bei einem Leser grün,
     * der nichts liest.
     */
    public function test_the_probes_produce_something(): void
    {
        $verzeichnis = $this->verzeichnis(self::NAMES, self::RUNS);

        $this->assertGreaterThan(0, count($verzeichnis['scripts']), 'Kein einziges Skript — dann prüft dieser Wächter nichts.');
        $this->assertGreaterThan(0, count($verzeichnis['ignored']), 'Kein einziger übergangener Name — dann prüft dieser Wächter nichts.');
    }

    /**
     * @param  list<string>  $namen
     * @return array{name: string, path: string, known: bool, schedule: ?string, conditional: ?string, readable: bool, scripts: list<string>, ignored: list<array{name: string, reason: string}>}
     */
    private function verzeichnis(array $namen, string $ausgabe, string $pfad = '/etc/cron.daily'): array
    {
        return $this->mit($namen, new Result(0, $ausgabe, ''), $pfad);
    }

    /**
     * @param  list<string>  $namen
     * @return array{name: string, path: string, known: bool, schedule: ?string, conditional: ?string, readable: bool, scripts: list<string>, ignored: list<array{name: string, reason: string}>}
     */
    private function mit(array $namen, ?Result $antwort, string $pfad = '/etc/cron.daily'): array
    {
        $zustand = CronState::read(
            [CronState::CRONTAB => "25 6\t* * *\troot\tcd / && run-parts --report /etc/cron.daily\n"],
            [$pfad => ['names' => $namen, 'test' => $antwort]],
            false,
        );

        return $zustand['directories'][0];
    }
}
