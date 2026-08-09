<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wer einen Befehl abdruckt, druckt einen ab, den es gibt.
 *
 * **Der Anlass ist ein ausgelieferter Fehler.** Auf der Datenbankseite stand
 * seit P5 in einer Meldung: „Aufgeräumt wird sie über `srvpanel db prune`."
 * Das Kommando `srvpanel:db` nimmt kein Argument — wer die Zeile abtippt,
 * bekommt „Too many arguments" und sonst nichts. Der Befehl heisst
 * `srvpanel db --prune`, und das steht drei Zeilen entfernt im Quelltext des
 * Kommandos richtig. Gefunden hat es niemand, weil eine Zeichenkette in einem
 * Vue-Template auf nichts zeigt, das ein Werkzeug prüft — **wörtlich das
 * Muster, an dem dieses Projekt sechsmal hängengeblieben ist** (CLAUDE.md).
 *
 * Deshalb hier zwei Richtungen:
 *
 * 1. **Jede Option, die neben einem `srvpanel`-Befehl steht, gibt es.** Gelesen
 *    wird die `$signature` des zugehörigen Kommandos — nicht eine Liste in
 *    diesem Test, denn die wäre die zweite Fassung, und die zweite ist die, die
 *    veraltet.
 * 2. **Ein Befehl, den die Oberfläche abdruckt, besteht nur aus Optionen.**
 *    Das ist die Richtung, die den Fund oben gefangen hätte: `prune` ohne
 *    Striche ist kein Fehler *in* einer Option, sondern ein Wort, das nach
 *    einer aussieht. Geprüft wird deshalb der ganze Befehl und nicht nur, was
 *    mit `--` beginnt.
 *
 * **Warum die Oberfläche strenger geprüft wird als der Quelltext.** In einem
 * Kommentar steht ein Befehl oft angerissen („wie bei `srvpanel tls --prune`");
 * in einer Meldung an den Betreiber steht er, damit er ihn nimmt. Was in einem
 * `class="ident"` steht, ist die Zeile zum Abtippen — und die muss ganz
 * stimmen.
 */
final class CommandReachTest extends TestCase
{
    /**
     * Optionen, die jedes Artisan-Kommando kennt, ohne sie zu deklarieren.
     *
     * Symfony hängt sie an jede Definition. Ein Ablauf, der
     * `srvpanel db --prune --no-interaction` abdruckt, ist richtig, und ohne
     * diese Liste stünde er hier als Fehler.
     *
     * @var list<string>
     */
    private const GLOBAL_OPTIONS = [
        'help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Unterkommandos, die der Aufrufer vorne ohne `srvpanel:` annimmt.
     *
     * Sie stehen im Aufrufer als `case`-Zweig und nicht in PHP — die Liste
     * abzuleiten hiesse, für jeden Aufruf erst PHP zu starten (siehe den Kopf
     * von `packaging/bin/srvpanel`). Also wird sie hier gelesen, wo sie steht.
     *
     * @return list<string>
     */
    private function subcommands(): array
    {
        $wrapper = (string) file_get_contents($this->root().'/packaging/bin/srvpanel');

        if (preg_match('/^\s*([a-z][a-z|-]*)\)$/m', $wrapper, $match) !== 1) {
            $this->fail('In packaging/bin/srvpanel steht kein case-Zweig mit den Unterkommandos mehr.');
        }

        return explode('|', $match[1]);
    }

    /**
     * Zu jedem Unterkommando die Optionen, die es deklariert.
     *
     * @return array<string, list<string>>
     */
    private function options(): array
    {
        $options = [];

        foreach (glob($this->root().'/app/Console/Commands/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match("/protected \\\$signature = '(srvpanel:[a-z-]+)(.*?)';/s", $source, $match) !== 1) {
                continue;
            }

            $sub = substr($match[1], strlen('srvpanel:'));

            preg_match_all('/\{--([a-z][a-z0-9-]*)/', $match[2], $namen);

            $options[$sub] = array_values(array_unique([...$namen[1], ...self::GLOBAL_OPTIONS]));
        }

        return $options;
    }

    /**
     * Alle Dateien, in denen ein abgedruckter Befehl stehen kann.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $files = [];

        foreach (['app', 'resources/js'] as $verzeichnis) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root().'/'.$verzeichnis, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'vue', 'ts'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen($this->root()) + 1);
    }

    /**
     * Erste Richtung: Was neben einem Befehl als Option steht, gibt es auch.
     */
    public function test_every_printed_option_exists(): void
    {
        $subcommands = $this->subcommands();
        $options = $this->options();

        $befunde = [];
        $gefunden = 0;

        foreach ($this->sources() as $file) {
            foreach (file($file) ?: [] as $nummer => $zeile) {
                // Nur was mindestens eine Option trägt: In Fliesstext steht
                // „srvpanel dns nennt ohne Angaben alle", und `nennt` ist keine
                // Angabe, die dieser Test beurteilen könnte.
                preg_match_all(
                    '/srvpanel ([a-z][a-z0-9-]*)((?:\s+--[a-z][a-z0-9-]*(?:=[^\s\'"`]*)?)+)/',
                    $zeile,
                    $treffer,
                    PREG_SET_ORDER,
                );

                foreach ($treffer as $treffer_eins) {
                    $gefunden++;
                    $sub = $treffer_eins[1];
                    $ort = sprintf('%s:%d', $this->relative($file), $nummer + 1);

                    if (! in_array($sub, $subcommands, true)) {
                        $befunde[] = sprintf('%s — „srvpanel %s" nimmt der Aufrufer nicht an.', $ort, $sub);

                        continue;
                    }

                    preg_match_all('/--([a-z][a-z0-9-]*)/', $treffer_eins[2], $namen);

                    foreach ($namen[1] as $name) {
                        if (! in_array($name, $options[$sub] ?? [], true)) {
                            $befunde[] = sprintf(
                                '%s — „srvpanel %s" kennt keine Option --%s.',
                                $ort,
                                $sub,
                                $name,
                            );
                        }
                    }
                }
            }
        }

        /*
         * **Die Untergrenze zählt, wo die Regel stehen darf, und nicht, wo sie
         * stehen soll.** Sie belegt, dass der Ausdruck oben überhaupt greift —
         * findet er nichts, ist dieser Test grün, ohne etwas geprüft zu haben.
         * Sie zählt über `app/` **und** `resources/js/` zusammen: Zieht ein
         * Befehl von einer Meldung im Steuerungscode in ein Template um, soll
         * dieser Test nicht rot werden. Genau daran sind in diesem Projekt
         * schon drei Wächter beim Aufräumen zerbrochen.
         */
        $this->assertGreaterThan(
            8,
            $gefunden,
            'Es werden kaum Befehle gefunden — dann prüft dieser Test nichts.',
        );

        $this->assertSame([], $befunde, sprintf(
            "Diese Befehle stehen abgedruckt und laufen so nicht:\n  %s\n\n".
            "Geprüft wird gegen die \$signature des Kommandos und gegen den case-Zweig in\n".
            'packaging/bin/srvpanel — nicht gegen eine Liste in diesem Test.',
            implode("\n  ", $befunde),
        ));
    }

    /**
     * Zweite Richtung: Was die Oberfläche zum Abtippen hinstellt, stimmt ganz.
     *
     * **Ein Wort ohne Striche ist der Fehler, den die erste Richtung nicht
     * sieht.** `srvpanel db prune` trägt keine Option, an der sie ansetzen
     * könnte — und ist trotzdem falsch.
     */
    public function test_a_command_printed_in_the_interface_consists_of_options_only(): void
    {
        $subcommands = $this->subcommands();
        $options = $this->options();

        $befunde = [];
        $kennungen = 0;

        foreach ($this->sources() as $file) {
            if (! str_ends_with($file, '.vue')) {
                continue;
            }

            $quelle = (string) file_get_contents($file);

            // Alles, was als Kennung ausgezeichnet ist — das ist in „Kontor"
            // die einzige Auszeichnung für „hier steht etwas, das man abtippt"
            // (app.css, `.ident`).
            preg_match_all('/class="[^"]*\bident\b[^"]*"[^>]*>([^<{]+)</', $quelle, $treffer, PREG_SET_ORDER);

            foreach ($treffer as $treffer_eins) {
                $kennungen++;
                $text = trim($treffer_eins[1]);

                if (preg_match('/^(?:sudo )?srvpanel\s+(.*)$/', $text, $teile) !== 1) {
                    continue;
                }

                $tokens = preg_split('/\s+/', trim($teile[1])) ?: [];
                $sub = array_shift($tokens);
                $ort = $this->relative($file);

                if (! in_array((string) $sub, $subcommands, true)) {
                    $befunde[] = sprintf('%s — „%s": %s nimmt der Aufrufer nicht an.', $ort, $text, (string) $sub);

                    continue;
                }

                foreach ($tokens as $token) {
                    if (preg_match('/^--([a-z][a-z0-9-]*)(?:=.*)?$/', $token, $name) !== 1) {
                        $befunde[] = sprintf(
                            '%s — „%s": „%s" ist keine Option. srvpanel %s nimmt keine Argumente.',
                            $ort,
                            $text,
                            $token,
                            (string) $sub,
                        );

                        continue;
                    }

                    if (! in_array($name[1], $options[(string) $sub] ?? [], true)) {
                        $befunde[] = sprintf('%s — „%s": --%s gibt es nicht.', $ort, $text, $name[1]);
                    }
                }
            }
        }

        /*
         * **Gezählt werden die Kennungen und nicht die Befehle.** Ein Panel,
         * das gerade keinen Befehl abdruckt, ist ein zulässiger Zustand — eine
         * Oberfläche ohne jede Kennung ist keiner, und dann greift der Ausdruck
         * oben nicht mehr. Die Untergrenze belegt also das Werkzeug und
         * verlangt nicht, dass es etwas zu tun gibt.
         */
        $this->assertGreaterThan(
            20,
            $kennungen,
            'Es werden kaum Kennungen gefunden — dann läuft der Ausdruck ins Leere.',
        );

        $this->assertSame([], $befunde, sprintf(
            "Diese Befehle stehen in der Oberfläche und laufen so nicht:\n  %s\n\n".
            'Wer sie abtippt, bekommt eine Fehlermeldung von Symfony und sonst nichts.',
            implode("\n  ", $befunde),
        ));
    }
}
