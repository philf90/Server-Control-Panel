<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Wer clientseitig filtert und blättert, springt beim Filtern auf Seite 1.
 *
 * ## Der Fehler, den dieser Wächter fängt
 *
 * Eine Seite mit eigener Blätterung hält die Seitenzahl in einem `ref`. Ändert
 * sich der Filter, schrumpft die Liste — und die Seitenzahl bleibt stehen. Wer
 * auf Seite 5 von 8 steht und dann auf „nur Sicherheit" schaltet, sieht eine
 * **leere Tabelle**, obwohl 124 Treffer da sind.
 *
 * > **Eine Blätterung, die den Filter nicht mitbekommt, zeigt nichts und meldet
 * > nichts.**
 *
 * Das ist die Verwandte des Befundes aus `docs/84`: Eine Anzeige, die einen
 * Zustand von vorher zeigt, verleitet zu der Handlung, die ihn zurücknimmt —
 * hier zeigt sie gar nichts und lässt den Betreiber glauben, sein Filter passe
 * auf keine Zeile.
 *
 * ## Warum die Prüfung so und nicht über ein Wort
 *
 * Sie **zählt die Filter aus der Berechnung ab**, statt eine Liste zu pflegen.
 * Eine gepflegte Liste wäre die zweite Fassung dessen, was im Code steht — und
 * sie wäre in dem Moment falsch, in dem jemand einen vierten Filter dazubaut.
 * Genau dieser vierte ist der Fall, den es zu fangen gilt.
 *
 * > **Ein Wächter, der eine Liste führt, prüft die Liste und nicht den Code.**
 */
final class FilterResetTest extends TestCase
{
    /** Woran eine Seite mit eigener Blätterung erkannt wird. */
    private const PAGER = '/^const seite = ref\(1\)$/m';

    /** Die filternde Berechnung — ihr Rumpf nennt die Filter. */
    private const FILTER = '/^const gefiltert = computed\((?<body>.*?)^\}\)$/ms';

    /** Die zurücksetzende Beobachtung mitsamt ihrer Liste. */
    private const RESET = '/watch\(\[(?<refs>[^\]]*)\][^)]*\)\s*=>\s*\{(?<body>[^}]*)\}/s';

    /**
     * Was in einem Rumpf `.value` trägt und trotzdem kein Filter ist.
     *
     * `props` ist der Bestand, nicht die Auswahl; es kann sich nicht ändern,
     * ohne dass die ganze Seite neu kommt.
     *
     * @var list<string>
     */
    private const NOT_A_FILTER = ['props', 'seite'];

    /** @return array<string, string> */
    private function pagedPages(): array
    {
        $treffer = [];

        foreach ($this->vueFiles() as $pfad) {
            $quelle = (string) file_get_contents($pfad);

            if (preg_match(self::PAGER, $quelle) === 1) {
                $treffer[$pfad] = $quelle;
            }
        }

        return $treffer;
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
        );

        $dateien = [];

        foreach ($lauf as $pfad) {
            if (str_ends_with((string) $pfad, '.vue')) {
                $dateien[] = (string) $pfad;
            }
        }

        sort($dateien);

        return $dateien;
    }

    /**
     * Die Namen der Filter, die eine Berechnung liest.
     *
     * @return list<string>
     */
    private function filtersIn(string $body): array
    {
        preg_match_all('/\b(?<name>[a-zA-Z_][a-zA-Z0-9_]*)\.value\b/', $body, $treffer);

        $namen = array_unique($treffer['name']);
        $namen = array_diff($namen, self::NOT_A_FILTER);

        // `sort()` schreibt die Schlüssel neu — danach ist es eine Liste, und
        // ein `array_values()` daneben wäre eine Zeile, die nichts tut.
        sort($namen);

        return $namen;
    }

    /** Jeder Filter, den die Berechnung liest, steht in der zurücksetzenden Beobachtung. */
    public function test_every_filter_resets_the_page(): void
    {
        $offen = [];
        $geprueft = 0;

        foreach ($this->pagedPages() as $pfad => $quelle) {
            if (preg_match(self::FILTER, $quelle, $berechnung) !== 1) {
                continue;
            }

            $filter = $this->filtersIn($berechnung['body']);

            // **Der Prüfkörper.** Findet der Ausdruck keinen einzigen Filter,
            // hat er nicht wenig gemessen — er hat gar nicht gemessen.
            $this->assertNotSame([], $filter, sprintf(
                "In %s liest die filternde Berechnung keinen einzigen `ref`.\n".
                'Entweder ist der Ausdruck ins Leere gelaufen, oder dort wird gar nicht gefiltert.',
                basename($pfad),
            ));

            $zuruecksetzend = [];

            foreach ($this->resets($quelle) as $refs) {
                foreach (preg_split('/\s*,\s*/', trim($refs)) ?: [] as $name) {
                    if ($name !== '') {
                        $zuruecksetzend[] = $name;
                    }
                }
            }

            $geprueft++;

            foreach ($filter as $name) {
                if (! in_array($name, $zuruecksetzend, true)) {
                    $offen[] = basename($pfad).' — '.$name;
                }
            }
        }

        $this->assertGreaterThan(0, $geprueft, 'Keine blätternde Seite gefunden — dieser Wächter misst nichts.');

        $this->assertSame([], $offen, sprintf(
            "Diese Filter setzen die Blätterung nicht zurück:\n  %s\n\n".
            'Wer auf Seite 5 steht und dann filtert, sieht eine leere Tabelle, obwohl Treffer da sind. '.
            'Der Filter gehört in die `watch`, die `seite.value = 1` setzt.',
            implode("\n  ", $offen),
        ));
    }

    /**
     * Die Ref-Listen jeder Beobachtung, die die Seitenzahl zurücksetzt.
     *
     * @return list<string>
     */
    private function resets(string $quelle): array
    {
        preg_match_all(self::RESET, $quelle, $treffer, PREG_SET_ORDER);

        $listen = [];

        foreach ($treffer as $eine) {
            if (str_contains($eine['body'], 'seite.value = 1')) {
                $listen[] = $eine['refs'];
            }
        }

        return $listen;
    }

    /**
     * Und die Beschriftung der Blätterung zählt das Gefilterte.
     *
     * Stünde dort die Gesamtzahl, behauptete sie einen Bestand, den die Liste
     * darunter nicht zeigt: „1–20 von 145" über zwanzig von 124 Treffern.
     *
     * > **Eine Zahl neben einer Liste, die etwas anderes zählt als die Liste,
     * > ist eine falsche Zahl und keine zusätzliche.**
     */
    public function test_the_pager_state_counts_what_the_table_shows(): void
    {
        $offen = [];
        $geprueft = 0;

        foreach ($this->pagedPages() as $pfad => $quelle) {
            if (preg_match('/^const stand = computed\((?<body>.*?)^\}\)$/ms', $quelle, $treffer) !== 1) {
                continue;
            }

            $geprueft++;

            if (! str_contains($treffer['body'], 'gefiltert.value')) {
                $offen[] = basename($pfad);
            }
        }

        $this->assertGreaterThan(0, $geprueft, 'Keine Blätterbeschriftung gefunden — dieser Wächter misst nichts.');

        $this->assertSame([], $offen, sprintf(
            "Diese Blätterbeschriftungen zählen etwas anderes als die Liste darunter:\n  %s",
            implode("\n  ", $offen),
        ));
    }

    /**
     * Zwei leere Zustände und nicht einer.
     *
     * „Der Server ist aktuell" und „auf diese Auswahl passt nichts" sind zwei
     * Auskünfte. Wer beide gleich anzeigt, meldet einen aktuellen Server für
     * eine Sucheingabe, die danebenging — dieselbe Klasse Fehler wie die fünf
     * Zustände aus `docs/48`.
     */
    public function test_a_filtered_list_tells_its_two_empty_states_apart(): void
    {
        $offen = [];
        $geprueft = 0;

        foreach ($this->pagedPages() as $pfad => $quelle) {
            if (! str_contains($quelle, 'const gefiltert = computed(')) {
                continue;
            }

            $geprueft++;

            // Einer der beiden hängt am Gesamtbestand, der andere am Filter.
            $amBestand = preg_match('/v-if="[^"]*\.length === 0"[^>]*class="empty"/', $quelle) === 1
                || preg_match('/class="empty"[^>]*v-if="[^"]*\.length === 0"/', $quelle) === 1;

            $amFilter = str_contains($quelle, 'v-if="gefiltert.length === 0"');

            if (! $amBestand || ! $amFilter) {
                $offen[] = basename($pfad).($amFilter ? ' — der Zustand am Bestand fehlt' : ' — der Zustand am Filter fehlt');
            }
        }

        $this->assertGreaterThan(0, $geprueft, 'Keine filternde Seite gefunden — dieser Wächter misst nichts.');

        $this->assertSame([], $offen, sprintf(
            "Diese Seiten zeigen für „nichts da\" und „nichts passt\" dieselbe Meldung:\n  %s",
            implode("\n  ", $offen),
        ));
    }
}
