<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Jede Komponente wird von irgendetwas eingebunden.
 *
 * ## Der Fund, der diesen Wächter erzwungen hat
 *
 * Am 15. August 2026 sollte ein absichtlicher Bruch belegen, dass jemand merkt,
 * wenn `Confirmation.vue` aus `PanelLayout.vue` verschwindet — die Komponente
 * zeichnet die einzige Rückfrage dieses Panels, und ohne sie tut jeder
 * gefährliche Knopf wieder nichts (`docs/55`, Befund 16).
 *
 * **Kein einziger Wächter wurde rot.** `ClassNameTest` prüft, dass jede Regel in
 * `app.css` von einer Vorlage erreicht wird — und `.confirmation` steht in der
 * Vorlage der Komponente selbst. Die Kette „Regel → Vorlage" war vollständig,
 * während die Kette „Vorlage → Seite" gerissen war.
 *
 * > **Eine Komponente, die niemand einbindet, erfüllt jede Prüfung über ihren
 * > eigenen Inhalt.**
 *
 * Das ist derselbe Schnitt wie bei `PolicyReachTest` und `AgentOperationReachTest`:
 * eine Zeichenkette, die auf etwas zeigt, ohne dass ein Typ, ein Test oder ein
 * Werkzeug den Bezug prüft — nur zeigt hier ausnahmsweise **nichts** auf sie,
 * und genau das fällt nirgends auf.
 *
 * ## Warum das nicht bloss Aufräumen ist
 *
 * Eine unbenutzte Komponente ist für sich genommen harmlos. Gefährlich ist der
 * Weg dorthin: Sie war eingebunden, jemand hat die Zeile beim Umbauen verloren,
 * und die Funktion ist fort — lautlos, weil alles andere weiter dasteht. Genau
 * so ist `RememberPageUrl` aus `bootstrap/app.php` verschwunden, und genau so
 * hätte diese Rückfrage verschwinden können.
 */
final class ComponentReachTest extends TestCase
{
    /**
     * Komponenten ohne Einbindung — mit Grund.
     *
     * Wer hier etwas einträgt, schreibt den Grund dazu; eine Liste ohne
     * Begründung je Eintrag wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    private const WITHOUT_USER = [];

    /**
     * Die Ausnahmeliste, mit ihrem Typ statt mit ihrem Inhalt.
     *
     * **Sie ist leer, und eine leere Konstante hat den Typ `array{}`.** PHPStan
     * meldet darauf „Offset string in isset() does not exist" und „Empty array
     * passed to foreach" — beides richtig für den heutigen Inhalt und falsch für
     * den Zweck: Die Liste steht da, damit jemand etwas einträgt.
     *
     * > **Ein Typ, der aus dem heutigen Inhalt geschlossen wird, verbietet den
     * > morgigen.**
     *
     * @return array<string, string>
     */
    private function withoutUser(): array
    {
        /** @var array<string, string> $liste */
        $liste = self::WITHOUT_USER;

        return $liste;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2).'/resources/js';
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $gefunden = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root(), FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $gefunden[] = $file->getPathname();
            }
        }

        sort($gefunden);

        return $gefunden;
    }

    /**
     * Alles, was irgendwo eingebunden wird — als blosser Dateiname.
     *
     * Der Pfad wird bewusst weggeworfen. Er unterscheidet sich je nach Tiefe
     * (`../Components/X.vue` gegen `../../Components/X.vue`), und ein Wächter,
     * der ihn vergleicht, meldet beim nächsten Verschieben eines Verzeichnisses
     * eine Handvoll Fehlalarme. Zwei Komponenten gleichen Namens gibt es hier
     * nicht; entstünde eine, wäre das ein eigenes Problem.
     *
     * @return list<string>
     */
    private function imported(): array
    {
        $namen = [];

        foreach ($this->vueFiles() as $datei) {
            $quelle = (string) file_get_contents($datei);

            if (preg_match_all("#from '[^']*/([A-Z][A-Za-z]*)\\.vue'#", $quelle, $treffer) > 0) {
                foreach ($treffer[1] as $name) {
                    $namen[$name] = true;
                }
            }
        }

        // Auch `app.ts` bindet ein — die Seiten kommen von dort, über
        // `resolvePageComponent`. Ohne diese Zeile wäre jede Seite „unbenutzt".
        $namen['*seiten*'] = true;

        return array_keys($namen);
    }

    /**
     * Wird diese Datei von einer Seite gerendert oder ist sie selbst eine?
     *
     * Seiten unter `Pages/` bindet Inertia über ihren Pfad ein; für sie ist
     * `InertiaPagesTest` zuständig. Hier geht es um alles darunter — Layouts und
     * Komponenten.
     */
    private function isPage(string $datei): bool
    {
        return str_starts_with($datei, 'Pages/');
    }

    public function test_every_component_is_used_somewhere(): void
    {
        $eingebunden = $this->imported();
        $verwaist = [];

        foreach ($this->vueFiles() as $datei) {
            $kurz = substr($datei, strlen($this->root()) + 1);

            if ($this->isPage($kurz) || isset($this->withoutUser()[$kurz])) {
                continue;
            }

            $name = basename($datei, '.vue');

            if (! in_array($name, $eingebunden, true)) {
                $verwaist[] = $kurz;
            }
        }

        $this->assertSame(
            [],
            $verwaist,
            sprintf(
                "Diese Bausteine bindet niemand ein:\n  %s\n\n".
                "Sie stehen im Repo, ihre Klassen erreichen jede Regel in `app.css`, ihre Vorlage ist\n".
                "gültig — und auf keiner Seite ist etwas davon zu sehen. Am gefährlichsten ist das\n".
                'nicht beim Anlegen, sondern beim Umbauen: Eine verlorene Einbindungszeile nimmt eine '.
                'Funktion mit, und alles andere bleibt stehen.',
                implode("\n  ", $verwaist),
            ),
        );
    }

    /**
     * Und die Suche findet auch Einbindungen.
     *
     * **Ohne diese Gegenprobe ist der Test darüber wertlos.** Fände der Ausdruck
     * gar nichts, wäre die Liste der Verwaisten die Liste **aller** Bausteine —
     * das fiele auf. Fände er zu viel, wäre sie leer, und das fiele nie auf.
     *
     * > **Eine Messung, die nie etwas anderes als Null liefern kann, ist
     * > keine.**
     */
    public function test_the_search_really_finds_imports(): void
    {
        $eingebunden = $this->imported();

        /*
         * **Die Untergrenze liegt deutlich unter dem Bestand** (18 Bausteine
         * plus die Marke für die Seiten, gemessen am 15. August 2026). Eine
         * Grenze knapp darunter wäre der Fehler, den dieses Projekt dreimal
         * gemacht hat: Sie meldet Rot beim Aufräumen und wird dann abgeschaltet.
         * Das Gewicht dieser Gegenprobe tragen die drei benannten Namen
         * darunter, nicht die Zahl.
         */
        $this->assertGreaterThanOrEqual(
            12,
            count($eingebunden),
            sprintf(
                'Es werden nur %d eingebundene Bausteine gefunden. Dann liest dieser Wächter die '.
                'Importzeilen nicht, und seine leere Liste bedeutet nichts.',
                count($eingebunden),
            ),
        );

        foreach (['PanelLayout', 'FormErrors', 'Confirmation'] as $muss) {
            $this->assertContains(
                $muss,
                $eingebunden,
                sprintf('`%s` wird von nichts eingebunden — oder der Ausdruck findet es nicht.', $muss),
            );
        }
    }

    /** @see ValidationLanguageTest::test_every_exemption_carries_a_reason */
    public function test_every_exemption_carries_a_reason(): void
    {
        foreach ($this->withoutUser() as $datei => $grund) {
            $this->assertNotSame(
                '',
                trim($grund),
                sprintf('`%s` steht ohne Grund in der Ausnahmeliste.', $datei),
            );
        }
    }
}
