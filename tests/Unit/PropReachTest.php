<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutMarkupComments;

/**
 * Jedes Attribut an einer eigenen Komponente ist eine Eigenschaft, die sie
 * deklariert.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Gefunden beim Beheben eines Befundes aus dem Abnahmelauf von „Platte voll"
 * (`docs/137 §7`, 27. September 2026): Die Abonnementseite gab ihrem Balken
 * `breit`, und die Eigenschaft heisst `wide`. Vue legt einen Namen, den eine
 * Komponente nicht kennt, **wortlos** als Attribut an ihr Wurzelelement.
 * Gemessen in Chromium an derselben Komponente: mit `breit` 150 px und
 * `breit=""` am Rumpf, mit `wide` 553 px und die Klasse `wide` am Balken.
 *
 * Die Suche danach hat zwei weitere gefunden, beide an `Section`: `weit` auf
 * der Übersicht und `voll` auf der Vorgangsseite. **Alle drei sind die
 * deutschen Namen von Eigenschaften, die seit der Umstellung auf englische
 * Bezeichner anders heissen** — der Kopf von `Section.vue` nannte sie bis
 * dahin selbst noch `weit` und `voll`.
 *
 * > **Eine Umbenennung, die der Übersetzer nicht prüft, lässt ihre alten Namen
 * > an jedem Aufrufer stehen — und dort sehen sie aus wie eine Angabe.**
 *
 * Das ist die Fehlerklasse aus dem Kopf von `CLAUDE.md` — eine Zeichenkette,
 * die auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den
 * Bezug prüft. `vue-tsc` kann es mit `checkUnknownProps`; er meldet dann aber
 * auch jedes `rel` und `aria-current` an Inertias `Link`, und dort gehört das
 * Attribut an das `<a>` darunter. Die eigenen Komponenten reichen nichts
 * durch — keine liest `$attrs`, keine setzt `inheritAttrs` —, und für sie gilt
 * die Regel ohne Ausnahme.
 *
 * ## Was er liest
 *
 * Eine eigene Komponente ist jede `.vue`, die eine andere einbindet. Ihre
 * Eigenschaften kommen aus ihrem `defineProps<{ … }>` und nicht aus einer
 * Liste in diesem Test; gelesen werden die Schlüssel auf der obersten Ebene
 * der Typangabe, denn `can: { update: boolean }` hat eine Eigenschaft und
 * nicht zwei. `v-model` zählt als `modelValue`, `v-model:x` als `x`.
 *
 * Die Marke wird mit einem Leser gelesen, der Anführungszeichen zählt —
 * `:tight="usage.percent >= 90"` steht vor `breit`, und ein Ausdruck bis zum
 * nächsten `>` hörte dort auf.
 *
 * > **Ein Ausdruck, der ein Tag bis zum nächsten `>` liest, liest ein halbes
 * > Tag, sobald eines im Attribut steht.**
 *
 * ## Was er nicht hält
 *
 * Ein `v-bind="objekt"` ohne Namen — es steht heute nirgends, und seine
 * Schlüssel entstehen erst zur Laufzeit. Und Ereignisse: `@x` gegen
 * `defineEmits` zu halten wäre eine zweite Regel.
 */
final class PropReachTest extends TestCase
{
    use WithoutMarkupComments;

    /**
     * Was Vue an jeder Komponente selbst verarbeitet — keine Eigenschaft und
     * kein Durchreichen.
     *
     * @var list<string>
     */
    private const VUE = ['key', 'ref', 'class', 'style', 'is'];

    /**
     * Die Zeile aus `Subscriptions/Show.vue`, wie sie bis zum 27. September
     * 2026 dastand — der Prüfkörper für den Leser.
     */
    private const BALKEN = '<Bar v-if="props.usage.percent !== null" :percent="props.usage.percent" '
        .':tight="props.usage.percent >= 90 && props.usage.percent <= 100" '
        .':over="props.usage.percent > 100" breit />';

    public function test_every_attribute_on_an_own_component_is_a_declared_prop(): void
    {
        $komponenten = $this->komponenten();
        $fehler = [];
        $gesehen = 0;
        $nachKomponente = [];

        foreach ($this->vorlagen() as $pfad => $quelle) {
            foreach ($this->verwendungen($pfad, $quelle, $komponenten) as [$ziel, $marke, $zeile, $attribute]) {
                $eigenschaften = $komponenten[$ziel];
                $name = basename($ziel, '.vue');
                $nachKomponente[$name] = ($nachKomponente[$name] ?? 0) + 1;

                foreach ($this->unbekannt($attribute, $eigenschaften) as $attribut) {
                    $fehler[] = sprintf(
                        '%s:%d <%s %s> — %s kennt: %s',
                        $pfad,
                        $zeile,
                        $marke,
                        $attribut,
                        $name,
                        $eigenschaften === [] ? 'nichts' : implode(', ', $eigenschaften),
                    );
                }

                $gesehen += count($attribute);
            }
        }

        // Untergrenzen: Ohne sie wäre eine leere Liste — keine Komponente
        // gefunden, keine Marke gelesen — ein Freispruch.
        $this->assertGreaterThanOrEqual(20, count($komponenten), 'Die eigenen Komponenten sind nicht gefunden worden.');
        $this->assertGreaterThanOrEqual(400, $gesehen, 'Die Attribute an den Komponenten sind nicht gelesen worden.');
        $this->assertGreaterThanOrEqual(2, $nachKomponente['Bar'] ?? 0, 'Die Balken, an denen der Befund stand, sind nicht gelesen worden.');
        $this->assertGreaterThanOrEqual(30, $nachKomponente['Section'] ?? 0, 'Die Bereiche sind nicht gelesen worden.');

        $this->assertSame([], $fehler, "Ein Name, den die Komponente nicht kennt, landet wortlos als Attribut an ihrem Rumpf:\n".implode("\n", $fehler));
    }

    /**
     * Der Leser sieht über ein `>` im Attributwert hinweg — an genau der Zeile,
     * an der der Befund stand.
     */
    public function test_the_reader_sees_the_case_that_started_it(): void
    {
        $attribute = array_column($this->attribute(self::BALKEN, 4), 0);

        $this->assertSame(['v-if', ':percent', ':tight', ':over', 'breit'], $attribute);
        $this->assertSame(['breit'], $this->unbekannt($this->attribute(self::BALKEN, 4), ['percent', 'tight', 'over', 'wide']));
    }

    /**
     * Die Eigenschaften kommen aus der obersten Ebene der Typangabe.
     *
     * Ein Schlüssel eines verschachtelten Typs ist keine Eigenschaft der
     * Komponente, und ein Kommentar mit einem Doppelpunkt darin auch nicht.
     */
    public function test_the_props_come_from_the_top_level_of_the_type(): void
    {
        $quelle = <<<'VUE'
            <script setup lang="ts">
            const props = withDefaults(defineProps<{
              /** Der Anteil: in Prozent. */
              percent: number
              tight?: boolean
              can: { update: boolean; remove: boolean }
              onToggle: (path: string) => void
              kind: 'ok' | 'warn'
              rows: Array<{ id: number }>
            }>(), { tight: false })
            </script>
            VUE;

        $this->assertSame(
            ['percent', 'tight', 'can', 'onToggle', 'kind', 'rows'],
            $this->eigenschaften($this->withoutMarkupComments($quelle)),
        );

        /*
         * `defineModel` nennt seine Eigenschaft nicht — und `CodeField` hat es
         * **neben** einem `defineProps`. Der erste Prüfkörper hier trug nur
         * das `defineModel`; er lief über den frühen Rückweg des Lesers, und
         * der Bruch, der die Modelle am Ende fallen liess, blieb grün. Beide
         * Formen stehen deshalb da.
         */
        $this->assertSame(
            ['label', 'modelValue', 'open'],
            $this->eigenschaften("const props = defineProps<{ label: string }>()\nconst value = defineModel<string>({ required: true })\nconst open = defineModel<boolean>('open')\n"),
        );
        $this->assertSame(
            ['modelValue'],
            $this->eigenschaften("const value = defineModel<string>({ required: true })\n"),
        );
    }

    /** Und der Leser trifft an den echten Dateien, was der Befund braucht. */
    public function test_the_reader_finds_the_props_of_the_real_components(): void
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js/Components';

        $bar = $this->eigenschaften($this->withoutMarkupComments((string) file_get_contents($wurzel.'/Bar.vue')));
        $section = $this->eigenschaften($this->withoutMarkupComments((string) file_get_contents($wurzel.'/Section.vue')));

        $this->assertContains('percent', $bar ?? []);
        $this->assertContains('wide', $bar ?? []);
        $this->assertContains('wide', $section ?? []);
        $this->assertContains('full', $section ?? []);
    }

    /**
     * Die Attribute, die keine Eigenschaft sind.
     *
     * @param  list<array{0: string, 1: int}>  $attribute  Name wie geschrieben, Stelle in der Marke
     * @param  list<string>  $eigenschaften
     * @return list<string>
     */
    private function unbekannt(array $attribute, array $eigenschaften): array
    {
        $unbekannt = [];

        foreach ($attribute as [$attribut]) {
            $name = $this->eigenschaft($attribut);

            if ($name === null || in_array($name, self::VUE, true)) {
                continue;
            }

            $kamel = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));

            if (! in_array($kamel, $eigenschaften, true)) {
                $unbekannt[] = $attribut;
            }
        }

        return $unbekannt;
    }

    /**
     * Welche Eigenschaft ein Attribut setzt — `null` für Ereignisse, Plätze
     * und Anweisungen, die keine setzen.
     */
    private function eigenschaft(string $attribut): ?string
    {
        if ($attribut === 'v-model') {
            return 'modelValue';
        }

        if (str_starts_with($attribut, 'v-model:')) {
            return $this->ohneModifikatoren(substr($attribut, 8));
        }

        if (str_starts_with($attribut, 'v-bind:')) {
            return $this->ohneModifikatoren(substr($attribut, 7));
        }

        if (str_starts_with($attribut, ':')) {
            return $this->ohneModifikatoren(substr($attribut, 1));
        }

        if (str_starts_with($attribut, '@') || str_starts_with($attribut, '#') || str_starts_with($attribut, 'v-')) {
            return null;
        }

        return $attribut;
    }

    private function ohneModifikatoren(string $name): string
    {
        return explode('.', $name, 2)[0];
    }

    /**
     * Jede Verwendung einer eigenen Komponente in einer Vorlage.
     *
     * @param  array<string, list<string>>  $komponenten
     * @return list<array{0: string, 1: string, 2: int, 3: list<array{0: string, 1: int}>}> Ziel, Marke, Zeile, Attribute
     */
    private function verwendungen(string $pfad, string $quelle, array $komponenten): array
    {
        $ohne = $this->withoutMarkupComments($quelle);
        $vorlage = $this->vorlagenblock($ohne);

        if ($vorlage === null) {
            return [];
        }

        [$beginn, $block] = $vorlage;
        $verwendungen = [];

        foreach ($this->einbindungen($pfad, $ohne) as $marke => $ziel) {
            if (! array_key_exists($ziel, $komponenten)) {
                continue;
            }

            preg_match_all('/<'.preg_quote($marke, '/').'(?=[\s\/>])/', $block, $treffer, PREG_OFFSET_CAPTURE);

            foreach ($treffer[0] as [, $stelle]) {
                $von = $stelle + 1 + strlen($marke);
                $bis = $this->markenende($block, $von);
                $koerper = substr($block, $von, $bis - $von);

                $attribute = [];

                foreach ($this->attribute($koerper, 0) as [$name, $versatz]) {
                    $attribute[] = [$name, $versatz];
                }

                // Gemeldet wird die Zeile des ersten unbekannten Attributs —
                // bei mehrzeiligen Marken ist das die, die man ändert.
                $zeile = substr_count(substr($ohne, 0, $beginn + $stelle), "\n") + 1;

                foreach ($this->unbekannt($attribute, $komponenten[$ziel]) as $falsch) {
                    foreach ($attribute as [$name, $versatz]) {
                        if ($name === $falsch) {
                            $zeile = substr_count(substr($ohne, 0, $beginn + $von + $versatz), "\n") + 1;

                            break 2;
                        }
                    }
                }

                $verwendungen[] = [$ziel, $marke, $zeile, $attribute];
            }
        }

        return $verwendungen;
    }

    /**
     * Die Attributnamen einer Marke, mit ihrer Stelle.
     *
     * Die Werte in Anführungszeichen werden vorher ausgeblendet — in ihnen
     * stehen `>`, `=` und Leerzeichen, die keine Grenze sind.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function attribute(string $marke, int $ab): array
    {
        $koerper = substr($marke, $ab);
        $ende = $this->markenende($koerper, 0);
        $koerper = substr($koerper, 0, $ende);
        $leer = '';
        $quote = null;

        for ($i = 0, $n = strlen($koerper); $i < $n; $i++) {
            $c = $koerper[$i];

            if ($quote !== null) {
                $leer .= ' ';

                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $leer .= ' ';

                continue;
            }

            $leer .= $c;
        }

        preg_match_all('/(?<=^|\s)([:@#]?[A-Za-z][\w:.\-]*)(?==|\s|\/|$)/', $leer, $treffer, PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $t): array => [$t[0], $t[1] + $ab],
            $treffer[1],
        );
    }

    /**
     * Die Stelle des `>`, das die Marke schliesst — Anführungszeichen zählen.
     *
     * Dieselbe Fassung wie in {@see PasswordAutocompleteTest}.
     */
    private function markenende(string $quelle, int $von): int
    {
        $laenge = strlen($quelle);
        $quote = null;

        for ($i = $von; $i < $laenge; $i++) {
            $c = $quelle[$i];

            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;

                continue;
            }

            if ($c === '>') {
                return $i;
            }
        }

        return $laenge;
    }

    /**
     * Der Vorlagenblock — von `<template>` am Zeilenanfang bis zum letzten
     * `</template>` am Zeilenanfang, samt der Stelle, an der er beginnt.
     *
     * @return array{0: int, 1: string}|null
     */
    private function vorlagenblock(string $quelle): ?array
    {
        if (preg_match('/^<template>/m', $quelle, $anfang, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $beginn = $anfang[0][1];
        $schluss = strrpos($quelle, "\n</template>");

        return [$beginn, substr($quelle, $beginn, $schluss === false ? null : $schluss - $beginn)];
    }

    /**
     * Welche Marke auf welche Datei zeigt — aus den Einbindungen der Datei.
     *
     * @return array<string, string> Marke => Pfad der Komponente
     */
    private function einbindungen(string $pfad, string $quelle): array
    {
        preg_match_all("/^import\\s+([A-Z]\\w*)\\s+from\\s+'([^']+\\.vue)'/m", $quelle, $treffer, PREG_SET_ORDER);

        $wurzel = dirname(__DIR__, 2);
        $einbindungen = [];

        foreach ($treffer as [, $marke, $ziel]) {
            $voll = realpath(dirname($wurzel.'/'.$pfad).'/'.$ziel);

            if ($voll !== false) {
                $einbindungen[$marke] = 'resources/js'.substr($voll, strlen($wurzel.'/resources/js'));
            }
        }

        return $einbindungen;
    }

    /**
     * Jede Komponente, die irgendwo eingebunden wird, mit ihren Eigenschaften.
     *
     * @return array<string, list<string>>
     */
    private function komponenten(): array
    {
        $vorlagen = $this->vorlagen();
        $komponenten = [];

        foreach ($vorlagen as $pfad => $quelle) {
            foreach ($this->einbindungen($pfad, $this->withoutMarkupComments($quelle)) as $ziel) {
                if (array_key_exists($ziel, $komponenten) || ! array_key_exists($ziel, $vorlagen)) {
                    continue;
                }

                $eigenschaften = $this->eigenschaften($this->withoutMarkupComments($vorlagen[$ziel]));

                $this->assertNotNull(
                    $eigenschaften,
                    $ziel.' deklariert seine Eigenschaften anders als mit defineProps<{ … }> — dieser Wächter kann sie nicht lesen und misst dort nichts.',
                );

                $komponenten[$ziel] = $eigenschaften;
            }
        }

        return $komponenten;
    }

    /**
     * Die Eigenschaften einer Komponente: die Schlüssel auf der obersten Ebene
     * von `defineProps<{ … }>` und je `defineModel` eine dazu.
     *
     * **`defineModel` deklariert eine Eigenschaft, ohne sie zu nennen** —
     * `modelValue`, oder den Namen, den es als erstes Argument bekommt. Der
     * erste Lauf dieses Wächters kannte es nicht und meldete jedes `v-model`
     * an `CodeField` als Attribut, das es nicht gibt.
     *
     * Eine Komponente ohne beides hat keine Eigenschaften (`[]`); eine, die
     * `defineProps` anders schreibt, gibt `null` — und der Aufrufer macht
     * daraus Rot, statt dort zu schweigen.
     *
     * @return list<string>|null
     */
    private function eigenschaften(string $quelle): ?array
    {
        preg_match_all('/\bdefineModel(?:<[^()]*>)?\(\s*(?:\'([^\']+)\'|"([^"]+)")?/', $quelle, $modelle, PREG_SET_ORDER);

        $modell = array_map(
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : 'modelValue'),
            $modelle,
        );

        if (! str_contains($quelle, 'defineProps')) {
            return $modell;
        }

        $anfang = strpos($quelle, 'defineProps<{');

        if ($anfang === false) {
            return null;
        }

        $tiefe = 1;
        $oben = '';
        $quote = null;

        for ($i = $anfang + strlen('defineProps<{'), $n = strlen($quelle); $i < $n && $tiefe > 0; $i++) {
            $c = $quelle[$i];

            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }

                $oben .= ' ';

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $oben .= ' ';

                continue;
            }

            if (in_array($c, ['{', '(', '[', '<'], true)) {
                $tiefe++;
            } elseif (in_array($c, ['}', ')', ']'], true) || ($c === '>' && $quelle[$i - 1] !== '=')) {
                $tiefe--;
            }

            // Was tiefer steht, gehört nicht zur obersten Ebene.
            $oben .= $tiefe === 1 && ! in_array($c, ['{', '(', '[', '<'], true) ? $c : ' ';
        }

        preg_match_all('/(?:^|[\n;,])\s*([A-Za-z_$][\w$]*)\??\s*:/', $oben, $treffer);

        return [...$treffer[1], ...$modell];
    }

    /**
     * Jede `.vue` unter `resources/js`.
     *
     * @return array<string, string> Pfad => Inhalt
     */
    private function vorlagen(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $dateien['resources/js'.substr($datei->getPathname(), strlen($wurzel))] =
                    (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }
}
