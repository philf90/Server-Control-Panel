<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Jedes Shellskript der Paketierung kommt bei shellcheck vorbei — und umgekehrt.
 *
 * **Der Anlass ist eine Begründung, die nicht stimmte.**
 * `SrvPanel\Agent\Ops\SystemPackagesUpgrade` schreibt im Kopf, warum der
 * apt-Lauf in einem Skript steht und nicht in einer Zeichenkette in PHP:
 *
 * > *„weil shellcheck über dieses Verzeichnis fährt und über eine Zeichenkette
 * > in PHP nichts fährt."*
 *
 * Gemessen am 26. August 2026 fuhr die CI über **drei Dateien mit Namen** —
 * `php`, `php-fpm`, `srvpanel` — und nicht über das Verzeichnis. `apt-run` war
 * einen Tag alt und ungeprüft, `cron-run` seit P6. Beide sind sauber; das ist
 * Glück und keine Zusage.
 *
 * > **Eine Begründung, die eine Tatsache behauptet, ist so lange richtig, bis
 * > jemand die Tatsache ändert — und niemand liest die Begründung dabei.**
 *
 * Das ist wortwörtlich das Muster aus CLAUDE.md: eine Zeichenkette, die auf
 * etwas verweist, ohne dass ein Werkzeug den Bezug prüft. Diesmal in einem
 * Dokumentationsblock, der die Bauart einer Operation rechtfertigt.
 *
 * **Geprüft werden beide Richtungen**, aus demselben Grund wie bei
 * `PackagingTest` und dem Wrapper: Die eine allein lässt einen toten Eintrag
 * stehen. Benennt jemand `packaging/scripts/` um und trägt den neuen Ort nach,
 * ist die erste Richtung wieder grün — und `packaging/scripts/*.sh` deckt von
 * da an nichts mehr, ohne dass es auffällt.
 */
final class ShellCheckReachTest extends TestCase
{
    /**
     * Wie viele Skripte hier mindestens stehen müssen.
     *
     * **Die Untergrenze ist der Prüfkörper.** Findet der Sucher unten nichts —
     * weil das Verzeichnis umzieht oder die Erkennung am Shebang bricht —, wäre
     * die Prüfung ohne sie wortlos grün, und zwar für alles.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     *
     * Am 26. August 2026 waren es achtzehn.
     */
    private const MINDESTENS = 15;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Zeilen des shellcheck-Schrittes aus `ci.yml`.
     *
     * @return list<string>
     */
    private function invocations(): array
    {
        $ci = (string) file_get_contents($this->root().'/.github/workflows/ci.yml');

        $zeilen = [];

        foreach (preg_split('/\R/', $ci) ?: [] as $zeile) {
            $zeile = trim($zeile);

            // Ein Kommentar nennt Pfade, ohne sie zu prüfen — er zählt nicht mit.
            if (str_starts_with($zeile, '#')) {
                continue;
            }

            if (preg_match('/^shellcheck\s+(?<rest>.+)$/D', $zeile, $treffer) === 1) {
                $zeilen[] = $treffer['rest'];
            }
        }

        return $zeilen;
    }

    /**
     * Was der Schritt tatsächlich abdeckt — Globs aufgelöst.
     *
     * @return list<string> Pfade relativ zur Wurzel
     */
    private function covered(): array
    {
        $pfade = [];

        foreach ($this->invocations() as $rest) {
            foreach (preg_split('/\s+/', $rest) ?: [] as $wort) {
                if ($wort === '' || str_starts_with($wort, '-')) {
                    continue;
                }

                // `-e SC1091` — die Kennung hinter der Fahne ist kein Pfad.
                if (preg_match('/^SC\d+/D', $wort) === 1) {
                    continue;
                }

                foreach (glob($this->root().'/'.$wort) ?: [] as $treffer) {
                    if (is_file($treffer)) {
                        $pfade[] = substr($treffer, strlen($this->root()) + 1);
                    }
                }
            }
        }

        return array_values(array_unique($pfade));
    }

    /**
     * Jede Datei mit Shell-Shebang unter `packaging/`.
     *
     * Gefragt wird der **Shebang** und nicht die Endung: `packaging/bin/php`,
     * `bin/srvpanel`, `bin/apt-run` und `bin/cron-run` tragen keine, und genau
     * die vier waren der Fall, um den es hier geht.
     *
     * @return list<string> Pfade relativ zur Wurzel
     */
    private function scripts(): array
    {
        $gefunden = [];

        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->root().'/packaging',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        /** @var \SplFileInfo $eintrag */
        foreach ($lauf as $eintrag) {
            if (! $eintrag->isFile()) {
                continue;
            }

            $handle = fopen($eintrag->getPathname(), 'rb');

            if ($handle === false) {
                continue;
            }

            $kopf = (string) fgets($handle, 128);
            fclose($handle);

            if (preg_match('%^#!\s*\S*/(?:env\s+)?(?:ba|da|k)?sh\b%D', $kopf) === 1) {
                $gefunden[] = substr($eintrag->getPathname(), strlen($this->root()) + 1);
            }
        }

        sort($gefunden);

        return $gefunden;
    }

    /**
     * Richtung 1: Kein Shellskript der Paketierung entgeht der CI.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): den Glob
     * `packaging/bin/*` in `ci.yml` wieder durch die drei Namen ersetzen.
     */
    public function test_every_shell_script_of_the_packaging_is_checked(): void
    {
        $skripte = $this->scripts();

        $this->assertGreaterThanOrEqual(self::MINDESTENS, count($skripte), implode(' ', [
            'Unter packaging/ stehen weniger Shellskripte als je gezählt wurden ('.count($skripte).').',
            'Entweder ist etwas umgezogen, oder die Erkennung am Shebang findet sie nicht mehr —',
            'in beiden Fällen misst dieser Wächter nichts.',
        ]));

        $gedeckt = $this->covered();
        $offen = array_values(array_diff($skripte, $gedeckt));

        $this->assertSame([], $offen, implode(' ', [
            'Diese Shellskripte der Paketierung fährt die CI nicht an:'."\n  ".implode("\n  ", $offen)."\n",
            'Ein Skript, das niemand prüft, ist genau das, was eine Zeichenkette in PHP auch wäre —',
            'und SystemPackagesUpgrade begründet seine Bauart damit, dass es hier anders ist.',
        ]));
    }

    /**
     * Richtung 2: Jeder Pfad im Schritt deckt auch etwas.
     *
     * **So entsteht ein toter Eintrag wirklich** — nicht beim Schreiben,
     * sondern beim Umbenennen: Der neue Ort wird nachgetragen, Richtung 1 ist
     * wieder grün, und der alte Eintrag bleibt liegen und deckt nichts.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): in `ci.yml` einen Pfad
     * hinzufügen, den es nicht gibt.
     */
    public function test_every_path_the_step_names_covers_something(): void
    {
        $aufrufe = $this->invocations();

        $this->assertNotSame([], $aufrufe, implode(' ', [
            'In ci.yml steht kein shellcheck-Aufruf mehr.',
            'Dann prüft dieser Wächter nichts — und die CI auch nicht.',
        ]));

        $leer = [];

        foreach ($aufrufe as $rest) {
            foreach (preg_split('/\s+/', $rest) ?: [] as $wort) {
                if ($wort === '' || str_starts_with($wort, '-') || preg_match('/^SC\d+/D', $wort) === 1) {
                    continue;
                }

                $treffer = array_filter(glob($this->root().'/'.$wort) ?: [], 'is_file');

                if ($treffer === []) {
                    $leer[] = $wort;
                }
            }
        }

        $this->assertSame([], $leer, implode(' ', [
            'Diese Pfade nennt der shellcheck-Schritt, und sie decken keine Datei:'
                ."\n  ".implode("\n  ", $leer)."\n",
            'Meistens ist etwas umgezogen: Dann steht der neue Ort daneben, die andere Richtung',
            'ist wieder grün, und dieser Eintrag bleibt als Zusage liegen, die niemand einlöst.',
        ]));
    }
}
