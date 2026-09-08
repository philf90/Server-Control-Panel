<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Cron\CronFile;
use SrvPanel\Agent\Cron\CronName;

/**
 * Die Zeitpläne des Systems in einen Zustand, den eine Seite zeigen kann.
 *
 * Ein reiner Leser und deshalb eine eigene Klasse — dieselbe Bauart wie
 * {@see PortState} und {@see TimeState}. Die Prüfkörper stammen aus den
 * **gemessenen** Dateien und Ausgaben von `docs/81 §2.3t` und nicht aus
 * erfundenen.
 *
 * ## Drei Gegenstände und nicht einer
 *
 * `/etc/crontab` und die Dateien unter `/etc/cron.d` tragen ihren Zeitplan **in
 * der Zeile**, samt einem Benutzerfeld. Die `cron.*`-Verzeichnisse tragen
 * Skripte und **keinen eigenen Zeitplan** — der steht als Zeile in
 * `/etc/crontab` (M1). In einer gemeinsamen Tabelle bliebe bei den einen die
 * Zeitspalte leer und bei den anderen die Spalte für das Verzeichnis.
 *
 * ## Warum an Leerraum getrennt wird und nicht an Leerzeichen
 *
 * Gemessen: `/etc/crontab` setzt seine Felder mit **Tabulatoren**
 * (`17 *\t* * *\troot\tcd / && …`), `/etc/cron.d/php` mit **mehreren
 * Leerzeichen** (`09,39 *     * * *     root   [ -x … ]`). Beides ist gültig,
 * und ein Leser, der eines von beiden voraussetzt, liest die andere Datei
 * falsch — mit einem Benutzerfeld, das in Wahrheit ein Stück des Kommandos ist.
 *
 * ## Die `@`-Form ist keine Ausnahme, sondern eine zweite Gestalt
 *
 * Gemessen (M4, `cron -n -x load,pars,sch`): `/etc/cron.d` nimmt
 * `@reboot`, `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`,
 * `@midnight` und `@hourly` — **acht** Namen, gefolgt von Benutzer und
 * Kommando. Wer eine solche Zeile in fünf Zeitfelder zerlegt, zeigt `root` als
 * Tag des Monats an.
 *
 * Ob der Name einer ist, den cron kennt, entscheidet dieser Leser **nicht**:
 * Das wäre eine zweite Fassung von crons Parser, und die zweite ist die, die
 * veraltet. Die Gestalt `@name benutzer kommando` ist dieselbe, ob cron den
 * Namen kennt oder nicht.
 *
 * > **Ein unbekannter `@`-Name nimmt die ganze Datei mit** — gemessen:
 * > `ERROR (Syntax error, this crontab file will be ignored)`, und zwar für
 * > jede Zeile darin. A6 sieht das nicht, weil A6 cron nicht startet; es steht
 * > als Grenze in `docs/111 §8`.
 *
 * ## Warum ein Verzeichnis drei Zustände hat und nicht zwei
 *
 * `cron.yearly` gibt es als Verzeichnis und **in keiner Zeile** (M1) — ein
 * Skript dort läuft nie. Ist `/etc/crontab` dagegen gar nicht lesbar, ist über
 * den Zeitplan **nichts bekannt**, und das sähe ohne ein eigenes Feld genauso
 * aus wie `cron.yearly`.
 *
 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
 * > tun".**
 *
 * Deshalb trägt jedes Verzeichnis `known`, und erst darunter `schedule`.
 */
final class CronState
{
    /** Die Zustände, die dieser Leser aussprechen kann, wenn er nichts lesen konnte. */
    public const REASONS = ['unreadable'];

    /** Die systemweite Tabelle. Sie trägt auch die Zeitpläne der Verzeichnisse. */
    public const CRONTAB = '/etc/crontab';

    /**
     * Zuweisungen, die cron als Umgebung liest und nicht als Zeitplan.
     *
     * Gemessen an `/etc/crontab` (`SHELL=/bin/sh`) und an den Dateien, die
     * dieses Panel selbst schreibt ({@see CronFile}: `MAILTO=""`, `PATH=…`).
     * Sie stehen zwischen den Zeilen und sähen ohne diese Unterscheidung aus
     * wie ein Zeitplan mit vier Feldern.
     */
    private const ENVIRONMENT = '/\A([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\z/D';

    /**
     * Der Zustand der Zeitpläne.
     *
     * Alles Lesen von der Platte macht der Aufrufer ({@see Ops\SystemCron});
     * hier wird nur gelesen, was er mitbringt. Das ist dieselbe Naht wie bei
     * {@see PortState}, und sie ist der Grund, dass die Wächter diesen Leser
     * ohne eine einzige Datei messen können.
     *
     * @param  array<string, ?string>  $files  Pfad => Inhalt, `null` wenn nicht lesbar
     * @param  array<string, array{names: list<string>, test: ?Result}>  $directories  Pfad => Inhalt und `run-parts --test`
     * @param  bool  $anacron  Ob das Programm auf dieser Maschine liegt
     * @return array{
     *     readable: bool,
     *     reason: ?string,
     *     anacron: bool,
     *     tables: list<array{path: string, owned: bool, readable: bool, entries: list<array{schedule: string, user: string, command: string}>, env: array<string, string>}>,
     *     directories: list<array{name: string, path: string, known: bool, schedule: ?string, conditional: ?string, readable: bool, scripts: list<string>, ignored: list<array{name: string, reason: string}>}>
     * }
     */
    public static function read(array $files, array $directories, bool $anacron): array
    {
        $tabellen = [];

        foreach ($files as $pfad => $inhalt) {
            $tabellen[] = self::table($pfad, $inhalt);
        }

        // Der Zeitplan eines Verzeichnisses steht in `/etc/crontab` und
        // nirgends sonst. Ist die Datei nicht lesbar, ist er unbekannt — und
        // das ist etwas anderes als „es gibt keinen".
        $bekannt = array_key_exists(self::CRONTAB, $files) && $files[self::CRONTAB] !== null;
        $zeilen = $bekannt ? self::table(self::CRONTAB, $files[self::CRONTAB])['entries'] : [];

        $verzeichnisse = [];

        foreach ($directories as $pfad => $inhalt) {
            $verzeichnisse[] = self::directory($pfad, $inhalt['names'], $inhalt['test'], $bekannt, $zeilen);
        }

        $gelesen = array_filter($tabellen, static fn (array $t): bool => $t['readable']);

        return [
            // Nicht lesbar heisst hier: keine einzige Tabelle. Eine einzelne
            // Datei, die fehlschlägt, trägt ihr `readable` selbst — sonst
            // verschwände der Rest hinter dem einen Fehlschlag.
            'readable' => $gelesen !== [],
            'reason' => $gelesen === [] ? 'unreadable' : null,
            'anacron' => $anacron,
            'tables' => $tabellen,
            'directories' => $verzeichnisse,
        ];
    }

    /**
     * Eine Datei mit Benutzerfeld — `/etc/crontab` oder eine aus `/etc/cron.d`.
     *
     * @return array{path: string, owned: bool, readable: bool, entries: list<array{schedule: string, user: string, command: string}>, env: array<string, string>}
     */
    private static function table(string $path, ?string $contents): array
    {
        $tabelle = [
            'path' => $path,
            'owned' => self::owned($path),
            'readable' => $contents !== null,
            'entries' => [],
            'env' => [],
        ];

        if ($contents === null) {
            return $tabelle;
        }

        foreach (explode("\n", $contents) as $zeile) {
            $zeile = trim($zeile);

            if ($zeile === '' || str_starts_with($zeile, '#')) {
                continue;
            }

            if (preg_match(self::ENVIRONMENT, $zeile, $treffer) === 1) {
                $tabelle['env'][$treffer[1]] = trim($treffer[2], '"\'');

                continue;
            }

            $eintrag = self::entry($zeile);

            if ($eintrag !== null) {
                $tabelle['entries'][] = $eintrag;
            }
        }

        return $tabelle;
    }

    /**
     * Eine Zeile in ihre drei Auskünfte — Zeitplan, Benutzer, Kommando.
     *
     * @return array{schedule: string, user: string, command: string}|null
     */
    private static function entry(string $line): ?array
    {
        // Die `@`-Form trägt ihren Zeitplan in **einem** Feld (M4), die
        // gewöhnliche in fünf. Zerlegt wird deshalb verschieden weit, und der
        // Rest bleibt in jedem Fall ungeteilt: Ein Kommando darf Leerzeichen
        // enthalten, und `/etc/cron.d/php` tut es (`[ -x … ] && if …`).
        $felder = str_starts_with($line, '@') ? 3 : 7;
        $teile = preg_split('/\s+/', $line, $felder);

        if ($teile === false || count($teile) < $felder) {
            return null;
        }

        $kommando = array_pop($teile);
        $benutzer = array_pop($teile);

        return [
            'schedule' => implode(' ', $teile),
            'user' => $benutzer,
            'command' => $kommando,
        ];
    }

    /**
     * Ein `cron.*`-Verzeichnis samt seinem Zeitplan aus `/etc/crontab`.
     *
     * @param  list<string>  $names  Was im Verzeichnis liegt
     * @param  ?Result  $test  Die Antwort von `run-parts --test`, oder `null`
     * @param  bool  $known  Ob `/etc/crontab` gelesen werden konnte
     * @param  list<array{schedule: string, user: string, command: string}>  $entries
     * @return array{name: string, path: string, known: bool, schedule: ?string, conditional: ?string, readable: bool, scripts: list<string>, ignored: list<array{name: string, reason: string}>}
     */
    private static function directory(string $path, array $names, ?Result $test, bool $known, array $entries): array
    {
        $zeile = $known ? self::scheduleFor($path, $entries) : null;

        // `run-parts --test` gibt **volle Pfade** aus (gemessen) und nicht
        // Namen. Wer sie gegen das Ergebnis von `scandir` hält, vergleicht
        // zwei verschiedene Dinge und meldet jedes Skript als übergangen.
        $laufen = $test !== null && $test->successful()
            ? array_map(static fn (string $l): string => basename(trim($l)), $test->lines())
            : [];

        $uebergangen = [];

        if ($test !== null && $test->successful()) {
            foreach ($names as $name) {
                // Eine **versteckte** Datei ist kein übergangenes Skript,
                // sondern nie eines gewesen. Gemessen: `cron-daemon-common`
                // legt in jedes dieser Verzeichnisse ein `.placeholder`, das
                // im eigenen Inhalt „DO NOT EDIT OR REMOVE" sagt — ohne diese
                // Zeile meldete der Bereich „Übergangen" auf **jedem** heilen
                // Server fünf Funde, und ein Bereich, der immer etwas meldet,
                // wird von dem überlesen, für den es ihn gibt.
                //
                // Das ist nicht dasselbe wie ein Punkt **im** Namen:
                // `backup.sh` ist ein Skript, das jemand abgelegt hat und das
                // nicht läuft, und genau dafür steht der Bereich.
                if (str_starts_with($name, '.')) {
                    continue;
                }

                if (! in_array($name, $laufen, true)) {
                    $uebergangen[] = ['name' => $name, 'reason' => self::why($path.'/'.$name)];
                }
            }
        }

        return [
            'name' => basename($path),
            'path' => $path,
            'known' => $known,
            'schedule' => $zeile['schedule'] ?? null,
            'conditional' => $zeile['conditional'] ?? null,
            'readable' => $test !== null && $test->successful(),
            'scripts' => array_values($laufen),
            'ignored' => $uebergangen,
        ];
    }

    /**
     * Die Zeile aus `/etc/crontab`, die dieses Verzeichnis startet.
     *
     * **Der Vorbehalt gehört zum Zeitpunkt und nicht daneben.** Drei der vier
     * gemessenen Zeilen tragen `test -x /usr/sbin/anacron ||` (M1): Mit anacron
     * tut cron für daily, weekly und monthly gar nichts, und die Zeitpunkte
     * stehen dann in `/etc/anacrontab`. Eine Anzeige „läuft um 6:25", die den
     * Vorbehalt verschweigt, ist auf jedem Server mit anacron falsch.
     *
     * Gesucht wird `anacron` im Kommando und nicht die gemessene Zeichenkette
     * `test -x /usr/sbin/anacron ||`: Der Pfad ist der von Debian und Ubuntu,
     * und eine Distribution, die ihn anders schreibt, bekäme mit der engen
     * Fassung wortlos den falschen Zeitpunkt statt gar keinen.
     *
     * @param  list<array{schedule: string, user: string, command: string}>  $entries
     * @return array{schedule: string, conditional: ?string}|null
     */
    private static function scheduleFor(string $path, array $entries): ?array
    {
        foreach ($entries as $eintrag) {
            if (! str_contains($eintrag['command'], $path)) {
                continue;
            }

            return [
                'schedule' => $eintrag['schedule'],
                'conditional' => str_contains($eintrag['command'], 'anacron') ? 'anacron' : null,
            ];
        }

        return null;
    }

    /**
     * Warum `run-parts` einen Namen übergeht.
     *
     * **Die Regeln werden nicht nachgebaut, sondern zugeordnet.** Dass ein
     * Skript nicht läuft, hat `run-parts --test` schon entschieden; hier steht
     * nur, was einem Menschen dazu zu sagen ist. Gemessen (M2) an zwölf
     * Prüfkörpern: Punkt im Namen (`backup.sh`, `logrotate.dpkg-new`), Tilde,
     * Leerzeichen, fehlendes Ausführbit — und gemessen ist auch, dass
     * `run-parts --test` ein Unterverzeichnis wortlos übergeht.
     *
     * **{@see CronName} entscheidet hier nichts.** Es ist crons Regel für
     * `/etc/cron.d`, und run-parts hat seine eigene; auf allen zwölf
     * Prüfkörpern fallen sie zusammen (beides `[A-Za-z0-9_-]+`), und liefen
     * sie einmal auseinander, bliebe die **Entscheidung** trotzdem die von
     * run-parts. Falsch werden könnte nur die Beschriftung, und die fällt dann
     * auf `unknown`.
     *
     * Der Rückfall ist `unknown` und nicht „läuft": Ein Grund, den diese
     * Zuordnung nicht kennt, ändert nichts daran, **dass** das Skript nicht
     * läuft.
     */
    private static function why(string $path): string
    {
        $name = basename($path);

        if (! CronName::readable($name)) {
            return CronName::reason($name) ?? 'unknown';
        }

        // Vor dem Ausführbit gefragt, weil ein Verzeichnis eines hat.
        if (@is_dir($path)) {
            return 'directory';
        }

        if (! @is_executable($path)) {
            return 'not-executable';
        }

        return 'unknown';
    }

    /**
     * Ob diese Datei dem Panel gehört.
     *
     * Das Präfix kommt aus {@see CronFile}, also von dort, wo die Dateien
     * geschrieben werden. Ein zweites `srvpanel-` hier wäre dieselbe Regel an
     * zwei Orten — derselbe Grund, aus dem `LocalHost::cronFiles()` es genauso
     * hält.
     */
    private static function owned(string $path): bool
    {
        return str_starts_with($path, CronFile::DIR.'/'.CronFile::PREFIX);
    }
}
