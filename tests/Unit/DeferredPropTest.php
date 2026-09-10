<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Was nachgereicht wird, hat auf seiner Seite einen Zustand „noch unterwegs".
 *
 * ## Der Anlass
 *
 * `/updates` fragt beim Rendern `system.packages.list`, und das ist ein echtes
 * `apt-get -s upgrade` — gemessen 3033 ms, warm wie kalt (`docs/904 §1`). Seit
 * dem 10. September 2026 reicht der Controller das nach. Damit hat die Seite
 * einen Zustand mehr als vorher, und der ist der gefährliche: `undefined`
 * blendet in JavaScript aus, statt zu scheitern.
 *
 * > **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
 * > behauptet etwas, das sie nicht weiss.**
 *
 * Ohne Zweig sähe „der Agent hat nicht geantwortet" genauso aus wie „es dauert
 * noch drei Sekunden" — und im schlimmsten Fall stünde drei Sekunden lang „Es
 * steht keine Aktualisierung an" auf einem Server, der 142 offene hat.
 *
 * ## Was er nicht kann
 *
 * Er sagt nicht, ob der Zweig **gut** aussieht — ob dort ein Platzhalter steht
 * oder eine leere Fläche, entscheidet die Bilderrunde und kein Ausdruck über
 * Quelltext. Er sagt auch nicht, ob der richtige Aufruf nachgereicht wurde:
 * Welcher Aufruf teuer ist, weiss nur eine Messung auf einem Server.
 *
 * ## Warum nicht `<Deferred>`
 *
 * Weil die Komponente aus `@inertiajs/vue3` das Nachladen nicht auslöst —
 * gemessen im Bündel tut das der Router
 * (`page.set()` → `loadDeferredProps` → `doReload({ only: … })`). Sie wählt
 * nur zwischen zwei Slots. Ein Wächter, der sie verlangte, prüfte das
 * Werkzeug statt der Eigenschaft; deshalb steht hier der Zustand und nicht
 * der Komponentenname. Wer sie benutzt, erfüllt diese Regel genauso — sein
 * `<Deferred :data="…">` nennt die Namen, und der Ausdruck unten liest beide
 * Formen.
 */
final class DeferredPropTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    /** Wo die Controller liegen. */
    private const CONTROLLERS = 'app/Http/Controllers';

    /** Wo die Seiten liegen. */
    private const PAGES = 'resources/js/Pages';

    /**
     * Jede Gruppe, die nachgereicht wird, hat auf ihrer Seite einen Zweig.
     *
     * **Je Gruppe und nicht je Eigenschaft**, und das ist gemessen und nicht
     * bequem: Inertia lädt eine Gruppe in **einer** Anfrage nach
     * (`loadDeferredProps` läuft über `Object.values(deferred)`). `packages`
     * und `packagesError` kommen also zusammen an, und ein Zweig auf einen von
     * beiden deckt beide. Ein Wächter je Eigenschaft verlangte einen zweiten
     * Zweig, der nie eine andere Antwort gäbe als der erste.
     */
    public function test_every_deferred_group_has_a_pending_branch(): void
    {
        $fehler = [];
        $gruppen = 0;

        foreach ($this->deferred() as $seite => $nachGruppe) {
            $vorlage = $this->page($seite);

            if ($vorlage === null) {
                // Dass es die Datei gibt, hält InertiaPagesTest — zwei
                // Fassungen derselben Regel wären eine zu viel.
                continue;
            }

            foreach ($nachGruppe as $gruppe => $namen) {
                $gruppen++;

                $getroffen = array_filter(
                    $namen,
                    fn (string $name): bool => $this->hasPendingBranch($vorlage, $name),
                );

                if ($getroffen === []) {
                    $fehler[] = sprintf(
                        '%s: die Gruppe „%s" (%s) kommt nachgereicht und hat keinen Zweig für „noch unterwegs".',
                        $seite,
                        $gruppe,
                        implode(', ', $namen),
                    );
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $gruppen,
            'Es wird nichts mehr nachgereicht — oder der Ausdruck findet `Inertia::defer(` nicht mehr.',
        );

        $this->assertSame([], $fehler, sprintf(
            "Diese Seiten zeigen einen Ladezustand als wäre er ein Ergebnis:\n\n  %s\n\n"
            .'In JavaScript ist `undefined` falsch: Ohne Zweig sieht „kommt noch" aus wie '
            .'„ist nichts da" — und der beruhigende Fall ist der häufigere.',
            implode("\n  ", $fehler),
        ));
    }

    /**
     * Und was nachgereicht wird, ist als fehlend deklariert.
     *
     * **Sonst lügt der Typ.** `packages: {...} | null` sagt „einer von zwei
     * Werten", und beim ersten Rendern steht dort ein dritter. `vue-tsc` fände
     * den Zweig aus dem Test oben dann als unerreichbar — und der Nächste
     * löschte ihn mit gutem Gewissen.
     */
    public function test_every_deferred_prop_is_declared_optional(): void
    {
        $fehler = [];
        $geprueft = 0;

        foreach ($this->deferred() as $seite => $nachGruppe) {
            $vorlage = $this->page($seite);

            if ($vorlage === null) {
                continue;
            }

            foreach ($nachGruppe as $namen) {
                foreach ($namen as $name) {
                    $geprueft++;

                    if (preg_match('/^\s*'.preg_quote($name, '/').'\?\s*:/m', $vorlage) !== 1) {
                        $fehler[] = sprintf(
                            '%s: „%s" wird nachgereicht, ist aber nicht als „%s?:" deklariert.',
                            $seite,
                            $name,
                            $name,
                        );
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $geprueft, 'Es wird nichts mehr nachgereicht — der Ausdruck greift nicht mehr.');

        $this->assertSame([], $fehler, sprintf(
            "Diese Eigenschaften behaupten einen Typ, den sie beim ersten Rendern nicht haben:\n\n  %s",
            implode("\n  ", $fehler),
        ));
    }

    /**
     * Die Gegenrichtung: kein Zweig ohne nachgereichte Eigenschaft.
     *
     * **Hier entsteht ein toter Eintrag wirklich.** Wer das Nachreichen
     * zurücknimmt, ändert den Controller — und der Zweig auf der Seite bleibt
     * stehen. Er wird dann nie gezeigt, sieht aber im Quelltext aus wie ein
     * abgedeckter Zustand, und der Nächste verlässt sich darauf.
     */
    public function test_no_pending_branch_without_a_deferred_prop(): void
    {
        $deferred = $this->deferred();
        $fehler = [];
        $zweige = 0;

        foreach ($this->pages() as $seite => $vorlage) {
            preg_match_all('/props\.([A-Za-z_][A-Za-z_0-9]*)\s*===\s*undefined/', $vorlage, $treffer);

            foreach (array_unique($treffer[1]) as $name) {
                $zweige++;

                $bekannt = [];

                foreach ($deferred[$seite] ?? [] as $namen) {
                    $bekannt = array_merge($bekannt, $namen);
                }

                if (! in_array($name, $bekannt, true)) {
                    $fehler[] = sprintf(
                        '%s: prüft „props.%s === undefined", aber der Controller reicht „%s" nicht nach.',
                        $seite,
                        $name,
                        $name,
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $zweige, 'Kein einziger Zweig gefunden — der Ausdruck greift nicht mehr.');

        $this->assertSame([], $fehler, sprintf(
            "Diese Zweige werden nie gezeigt und sehen im Quelltext aus wie ein abgedeckter Zustand:\n\n  %s",
            implode("\n  ", $fehler),
        ));
    }

    /**
     * Welche Seite reicht was in welcher Gruppe nach.
     *
     * **Gelesen wird der Rumpf zwischen zwei `Inertia::render(`** und nicht
     * die ganze Datei: Ein Controller kann mehrere Seiten rendern, und ein
     * `defer` der einen gehörte sonst auch der anderen.
     *
     * @return array<string, array<string, list<string>>> Seite → Gruppe → Namen
     */
    private function deferred(): array
    {
        $ergebnis = [];

        foreach ($this->files(self::CONTROLLERS, 'php') as $pfad) {
            $quelle = $this->withoutComments((string) file_get_contents($pfad));

            preg_match_all(
                "/Inertia::render\(\s*'([^']+)'/",
                $quelle,
                $renders,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
            );

            foreach ($renders as $i => $render) {
                $seite = (string) $render[1][0];
                $von = (int) $render[0][1];
                $bis = isset($renders[$i + 1]) ? (int) $renders[$i + 1][0][1] : strlen($quelle);
                $rumpf = substr($quelle, $von, $bis - $von);

                /*
                 * Der Gruppenname ist das zweite Argument von `defer()` und
                 * fehlt meistens; ohne ihn gilt `'default'`. Die Vorgabe steht
                 * hier ausgeschrieben, weil ein `null` als Gruppenname sich
                 * später wie „keine Gruppe" läse.
                 */
                preg_match_all(
                    "/'([A-Za-z_][A-Za-z_0-9]*)'\s*=>\s*Inertia::defer\((?:[^()]|\([^()]*\))*?(?:,\s*'([^']+)')?\s*\)/",
                    $rumpf,
                    $treffer,
                    PREG_SET_ORDER,
                );

                foreach ($treffer as $eins) {
                    $gruppe = ($eins[2] ?? '') !== '' ? $eins[2] : 'default';
                    $ergebnis[$seite][$gruppe][] = $eins[1];
                }
            }
        }

        return $ergebnis;
    }

    /**
     * Alle Seiten, ohne ihre Kommentare.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        $ergebnis = [];

        foreach ($this->files(self::PAGES, 'vue') as $pfad) {
            $name = substr($pfad, strlen($this->root().'/'.self::PAGES.'/'), -4);
            $ergebnis[$name] = $this->withoutMarkupComments((string) file_get_contents($pfad));
        }

        return $ergebnis;
    }

    /** Eine Seite, ohne ihre Kommentare — oder `null`, wenn es sie nicht gibt. */
    private function page(string $seite): ?string
    {
        $pfad = $this->root().'/'.self::PAGES.'/'.$seite.'.vue';

        return is_file($pfad)
            ? $this->withoutMarkupComments((string) file_get_contents($pfad))
            : null;
    }

    /**
     * Trägt die Seite einen Zweig für „diese Eigenschaft ist noch nicht da"?
     *
     * Zwei Formen zählen: der Vergleich mit `undefined` und ein `<Deferred>`,
     * das den Namen nennt. Die zweite gibt es hier heute nirgends — sie steht
     * trotzdem da, weil sie dieselbe Regel erfüllt und ein Wächter, der die
     * gewohnte Schreibweise kennt, die Gewohnheit prüft und nicht die Regel.
     */
    private function hasPendingBranch(string $vorlage, string $name): bool
    {
        if (preg_match('/props\.'.preg_quote($name, '/').'\s*===\s*undefined/', $vorlage) === 1) {
            return true;
        }

        preg_match_all('/<Deferred[^>]*\bdata\s*=\s*"([^"]*)"/', $vorlage, $treffer);

        foreach ($treffer[1] as $daten) {
            if (preg_match("/\b".preg_quote($name, '/')."\b/", $daten) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alle Dateien einer Endung unter einem Verzeichnis.
     *
     * @return list<string>
     */
    private function files(string $unter, string $endung): array
    {
        $gefunden = [];

        /** @var iterable<\SplFileInfo> $lauf */
        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root().'/'.$unter, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($lauf as $datei) {
            if ($datei->isFile() && $datei->getExtension() === $endung) {
                $gefunden[] = $datei->getPathname();
            }
        }

        sort($gefunden);

        return $gefunden;
    }

    /** Die Wurzel des Repositorys. */
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
