<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Zwei Klassen an einem Element entscheiden ihren Streit nicht durch die
 * Reihenfolge der Datei.
 *
 * ## Der Anlass
 *
 * **Befund 5 der Bilderrunde** (`docs/76`), gesehen auf `cloudsrv24` gegen
 * `v0.7.0-rc.5` — beim Nachsehen der Behebung von Befund 3. Der
 * Hinderungsgrund unter dem Kästchen „Als Platzhalter bestellen" stand in
 * derselben Farbe wie die Erklärung darüber, also genau so, wie **vor** der
 * Behebung.
 *
 * Die Regel dafür gab es:
 *
 *     .obstacle { color: var(--warn); }        /* Zeile 3647 *&#47;
 *     .hint     { color: var(--text-muted); }  /* Zeile 3784 *&#47;
 *
 * Beide haben die Spezifität 0,1,0, das Markup lautet `class="hint obstacle"`,
 * und bei gleicher Spezifität entscheidet die **Reihenfolge in der Datei**.
 * `.hint` steht 137 Zeilen weiter unten und gewinnt.
 *
 * > **Eine Klasse, die auf eine Regel zeigt, sagt nichts darüber, ob die Regel
 * > gilt.**
 *
 * `ClassReachTest` war dabei grün — er fragt, ob eine Klasse auf eine Regel
 * zeigt, die es gibt. Genau das war der Fall. Was fehlte, ist die Frage
 * dahinter.
 *
 * **Und der Weg dorthin ist lehrreich.** Der erste Wurf war `.toggle
 * .obstacle` (0,2,0) und hätte gewonnen; `StandaloneClassTest` hat ihn zu
 * Recht abgelehnt, weil eine Klasse, die es nur unter einem Vorfahren gibt,
 * ausserhalb davon nichts tut. Die Antwort darauf — `.obstacle` freistehend —
 * hat die Spezifität weggenommen, die sie brauchte.
 *
 * > **Eine Behebung, die einem Wächter ausweicht, kann dabei genau das
 * > verlieren, wofür sie da war.**
 *
 * ## Was er prüft
 *
 * Jedes Klassenpaar, das in einer Vorlage an **einem** Element steht: Setzen
 * beide dieselbe CSS-Eigenschaft mit **gleicher** Spezifität, entscheidet die
 * Reihenfolge — und die ist beim nächsten Aufräumen eine andere, ohne dass
 * jemand etwas merkt. Solche Paare gehören in {@see self::ORDERED} mit ihrem
 * gemessenen Ausgang und dem Grund, warum er ohne Folge ist.
 *
 * > **Eine Regel, die durch ihren Ort gewinnt, verliert beim nächsten Umzug —
 * > und sagt es nicht.**
 *
 * Aufgelöst wird so ein Streit durch Spezifität: `.hint.obstacle` ist 0,2,0
 * und gewinnt gegen `.hint`, gleich wo die beiden Regeln stehen.
 *
 * ## Was er nicht prüft
 *
 * Regeln mit Nachfahren, Elementen oder `@media` — er sieht nur den einfachen
 * Fall `.x { … }`, und genau der ist die Falle: Er sieht aus, als hinge nichts
 * davon ab. Und er weiss nichts über Vererbung: Eine Farbe, die von einem
 * Vorfahren kommt, verliert ohnehin gegen jede eigene Regel.
 */
final class SpecificityTest extends TestCase
{
    /**
     * Paare, deren Ausgang die Reihenfolge entscheidet — angesehen und in
     * Ordnung.
     *
     * **Jeder Eintrag ist eine Behauptung**, und zwar diese: Der Ausgang ist
     * gemessen, er ist der gewollte, und wenn die Datei umgeräumt wird, ändert
     * er sich — dann steht diese Zeile im Weg und erinnert daran.
     *
     * @var array<string,string>
     */
    private const ORDERED = [
        'breadcrumb+ident font-size' => 'Der Pfad einer Protokolldatei über dem Seitentitel '
            .'(`Domains/Logs.vue`). `.breadcrumb` gewinnt mit `--text-small`; das ist die Grösse, die '
            .'eine Krume hat, und `.ident` steuert dort die Schrift und den Umbruch bei. Ein Umzug '
            .'machte die Krume auf `--text-table` gross — sichtbar, aber kein Schaden.',
        'ident+path-line font-size' => 'Der Pfad über dem Editor (`Files/Edit.vue`). Dasselbe wie bei '
            .'der Krume: `.path-line` gewinnt mit `--text-small`.',
        'ident+path-line overflow-wrap' => 'Beide setzen `anywhere` — derselbe Wert, also hat die '
            .'Reihenfolge hier keine Wirkung. Der Eintrag steht trotzdem da: Ändert einer der beiden '
            .'seinen Wert, ist es plötzlich eine Entscheidung.',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Je Klasse: welche Eigenschaft sie setzt und an welcher Stelle der Datei.
     *
     * **Nur Regeln der Form `.x { … }`.** Alles mit Nachfahre, Element oder
     * Attribut hat eine andere Spezifität und entscheidet den Streit ohne die
     * Reihenfolge. Verschachteltes bleibt draussen: `@media` steht in dieser
     * Datei als eigener Block, und die Regeln darin tragen ihre Bedingung.
     *
     * @return array<string, array<string, int>>
     */
    private function rules(): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($this->root().'/resources/css/app.css'));
        $regeln = [];

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $treffer, PREG_SET_ORDER);

        foreach ($treffer as $index => [, $selektoren, $rumpf]) {
            foreach (explode(',', $selektoren) as $selektor) {
                $selektor = trim($selektor);

                if (preg_match('/^\.[\w-]+$/', $selektor) !== 1) {
                    continue;
                }

                foreach (explode(';', $rumpf) as $angabe) {
                    if (! str_contains($angabe, ':')) {
                        continue;
                    }

                    $eigenschaft = trim(explode(':', $angabe, 2)[0]);

                    if ($eigenschaft === '' || str_starts_with($eigenschaft, '--')) {
                        continue;
                    }

                    $regeln[substr($selektor, 1)][$eigenschaft] = $index;
                }
            }
        }

        return $regeln;
    }

    /**
     * Jedes Klassenpaar, das in einer Vorlage an einem Element steht.
     *
     * @return list<array{string, string}>
     */
    private function pairs(): array
    {
        $paare = [];

        foreach ($this->templates() as $vorlage) {
            preg_match_all('/(?<![:\w-])class="([^"]*)"/', $vorlage, $treffer);

            foreach ($treffer[1] as $attribut) {
                $namen = array_values(array_unique(array_filter(
                    preg_split('/\s+/', trim($attribut)) ?: [],
                    static fn (string $k): bool => preg_match('/^[\w-]+$/', $k) === 1,
                )));

                sort($namen);

                foreach ($namen as $i => $a) {
                    foreach (array_slice($namen, $i + 1) as $b) {
                        $paare[$a.'+'.$b] = [$a, $b];
                    }
                }
            }
        }

        return array_values($paare);
    }

    /**
     * Der `<template>`-Teil jeder `.vue`-Datei, Kommentare durch Leerzeichen
     * ersetzt.
     *
     * @return list<string>
     */
    private function templates(): array
    {
        $vorlagen = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'vue') {
                continue;
            }

            $quelle = (string) file_get_contents($datei->getPathname());

            if (preg_match('/^<template>$(.*?)^<\/template>$/ms', $quelle, $treffer) !== 1) {
                continue;
            }

            $vorlagen[] = (string) preg_replace('/<!--.*?-->/s', ' ', $treffer[1]);
        }

        return $vorlagen;
    }

    /**
     * Jeder Streit, den die Reihenfolge entscheidet — als `a+b eigenschaft`.
     *
     * @return array<string, string>
     */
    private function decidedByOrder(): array
    {
        $regeln = $this->rules();
        $funde = [];

        foreach ($this->pairs() as [$a, $b]) {
            if (! isset($regeln[$a], $regeln[$b])) {
                continue;
            }

            foreach (array_intersect(array_keys($regeln[$a]), array_keys($regeln[$b])) as $eigenschaft) {
                $gewinner = $regeln[$a][$eigenschaft] > $regeln[$b][$eigenschaft] ? $a : $b;

                $funde[sprintf('%s+%s %s', $a, $b, $eigenschaft)] = $gewinner;
            }
        }

        ksort($funde);

        return $funde;
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet der Ausdruck keine Regeln oder keine Paare, prüft der Fall
     * darunter nichts und ist grün, ohne etwas gesehen zu haben.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_are_rules_and_pairs_to_check(): void
    {
        $this->assertGreaterThanOrEqual(
            60,
            count($this->rules()),
            'Der Ausdruck findet kaum noch Regeln der Form `.x { … }` in app.css — er trifft nicht mehr.',
        );

        $this->assertGreaterThanOrEqual(
            20,
            count($this->pairs()),
            'Der Ausdruck findet kaum noch Klassenpaare in den Vorlagen — er trifft nicht mehr.',
        );
    }

    /**
     * **Und die Gegenprobe zum Vergleich selbst.**
     *
     * Der Fall darüber sichert, dass Regeln und Paare gefunden werden. Er sagt
     * nichts darüber, ob der Vergleich einen Streit überhaupt erkennt — und
     * ohne das wäre die Regel darunter für immer grün.
     *
     * Der Prüfkörper ist `.hint` gegen `.obstacle`: Beide setzen `color`, und
     * genau daran ist Befund 5 entstanden. Er steht heute als `.hint.obstacle`
     * da, also mit zwei Klassen — der Vergleich darf ihn nicht mehr als Streit
     * führen, aber die **einzelnen** Regeln muss er noch sehen.
     */
    public function test_the_comparison_sees_a_conflict(): void
    {
        $regeln = $this->rules();

        $this->assertArrayHasKey('hint', $regeln, 'Die Regel `.hint` wird nicht mehr gelesen.');
        $this->assertArrayHasKey('color', $regeln['hint'], '`.hint` setzt keine Farbe mehr — dann ist der Prüfkörper weg.');

        $this->assertArrayNotHasKey(
            'obstacle',
            $regeln,
            '`.obstacle` steht wieder als freistehende Regel da. Damit hat sie dieselbe Spezifität wie '
            .'`.hint`, und der Hinderungsgrund ist wieder so blass wie die Erklärung darüber (Befund 5).',
        );
    }

    /** Kein Streit zweier Klassen hängt an der Reihenfolge — oder er steht in der Liste. */
    public function test_no_pair_is_decided_by_source_order(): void
    {
        $funde = $this->decidedByOrder();
        $offen = [];

        foreach ($funde as $paar => $gewinner) {
            if (! isset(self::ORDERED[$paar])) {
                $offen[] = sprintf('%s — `.%s` gewinnt, nur durch die Reihenfolge', $paar, $gewinner);
            }
        }

        $this->assertSame([], $offen, implode("\n", [
            'Diese Klassen stehen an einem Element und setzen dieselbe Eigenschaft mit',
            'derselben Spezifitaet:',
            ...$offen,
            '',
            'Entschieden wird das von der Reihenfolge in app.css. Beim naechsten',
            'Aufraeumen ist sie eine andere, und die Anzeige aendert sich, ohne dass',
            'ein Test rot wird — genau so ist Befund 5 entstanden.',
            '',
            'Der Weg: die gewollte Regel als Verbund schreiben (`.hint.obstacle` statt',
            '`.obstacle`) — zwei Klassen sind 0,2,0 und gewinnen unabhaengig vom Ort.',
            'Oder das Paar mit seinem gemessenen Ausgang in SpecificityTest::ORDERED',
            'eintragen.',
        ]));

        /*
         * **Die Sperrklinke.** Ein Eintrag, dessen Streit es nicht mehr gibt,
         * ist eine Erlaubnis für nichts — und er verdeckt, dass der Ausdruck
         * ihn vielleicht bloss nicht mehr findet.
         */
        foreach (array_keys(self::ORDERED) as $bekannt) {
            $this->assertArrayHasKey(
                $bekannt,
                $funde,
                sprintf(
                    '`%s` steht in SpecificityTest::ORDERED und ist kein Streit mehr. Entweder ist er '
                    .'aufgelöst — dann gehört die Zeile gelöscht — oder der Ausdruck findet ihn nicht '
                    .'mehr, und dann ist der Wächter kaputt.',
                    $bekannt,
                ),
            );
        }
    }
}
