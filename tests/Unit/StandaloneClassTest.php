<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Eine Klasse, die es nur unter einem Vorfahren gibt, ist anderswo ein Wunsch.
 *
 * ## Der Fund
 *
 * `.quiet` stand in `app.css` als `td.quiet` und `td .quiet` — und sonst
 * nirgends. Fünf Stellen im Panel tragen die Klasse **ausserhalb** einer
 * Tabelle: der Satz „Gesucht unter …" auf der Suchseite, der Satz „Diese Datei
 * gehört nicht dem Abonnement …" im Editor, die Fortschrittszahl beim
 * Hochladen, der Trenner in den Krümeln und die symbolische Schreibweise neben
 * dem Oktalfeld. Alle fünf sollen leise sein; keine war es.
 *
 * Gemessen am 19. August 2026 gegen das gebaute Stylesheet: `<p class="quiet">`
 * hatte exakt die Farbe eines Absatzes ohne Klasse.
 *
 * > **Eine Klasse, die es nur als Nachfahrenregel gibt, ist ausserhalb ihres
 * > Vorfahren ein Wunsch.**
 *
 * ## Warum `ClassReachTest` das nicht sieht
 *
 * Er fragt, ob die Klasse in `app.css` **vorkommt**. Sie kam vor. Dass sie nur
 * unter einem Vorfahren vorkam, ist eine andere Frage — und die stellt dieser
 * Wächter.
 *
 * > **Zwei Fragen, die sich gleich anhören, prüfen verschiedene Dinge — und die
 * > ungestellte ist die, an der es scheitert.**
 *
 * ## Was er verlangt
 *
 * Für jede Klasse, die eine Vorlage benutzt und die `app.css` kennt, muss es
 * eine **freistehende** Regel geben: eine, deren erste Verbindung nur aus
 * Klassen besteht — kein Elementname, kein Vorfahr. Wo das absichtlich nicht so
 * ist, steht die Klasse in {@see self::CONTEXT_BOUND}, und der Eintrag nennt
 * den Zusammenhang, in dem sie allein gilt.
 */
final class StandaloneClassTest extends TestCase
{
    /**
     * Klassen, die absichtlich nur in einem Zusammenhang gelten.
     *
     * **Jeder Eintrag ist eine Behauptung**, und zwar diese: Dieser Baustein ist
     * kein eigener, sondern eine Abwandlung eines anderen. `.right` ohne Zelle
     * ist nichts, `.pairs` ohne Tabelle ist nichts. `.quiet` war das nie — es
     * ist leiser Text, und leiser Text gibt es überall.
     *
     * Wer hier etwas einträgt, sagt: Diese Klasse steht nie allein. Wer eine
     * Klasse hier vergisst, bekommt Rot; wer eine einträgt, die inzwischen eine
     * freistehende Regel hat, bekommt es auch (siehe die Sperrklinke unten).
     *
     * @var array<string,string>
     */
    private const CONTEXT_BOUND = [
        'aside' => 'die schmale Hälfte eines .split',
        'cell' => 'eine Zelle in .rows',
        'code' => 'ein textarea in einem .field',
        'multiline' => 'eine Zelle, die mehrere Zeilen trägt',
        'name' => 'die Namensspalte einer Zelle',
        'narrow' => 'ein .field in einer .field-row',
        'pairs' => 'eine Tabelle aus Bezeichnung und Wert',
        'right' => 'eine rechtsbündige Zelle',
        'short' => 'ein .code, dessen Inhalt eine bekannte Länge hat',
    ];

    /** Wo das Stylesheet steht. */
    private const STYLESHEET = 'resources/css/app.css';

    /**
     * Jede benutzte Klasse hat eine freistehende Regel — oder eine Begründung.
     */
    public function test_every_used_class_has_a_standalone_rule(): void
    {
        $freistehend = $this->standalone();
        $genannt = $this->mentioned();
        $offen = [];

        foreach ($this->usedInTemplates() as $klasse) {
            if (! isset($genannt[$klasse]) || isset($freistehend[$klasse])) {
                continue;
            }

            $offen[$klasse] = true;
        }

        foreach (array_keys($offen) as $klasse) {
            $this->assertArrayHasKey(
                $klasse,
                self::CONTEXT_BOUND,
                sprintf(
                    "`.%s` gibt es in app.css nur unter einem Vorfahren oder an einem Element.\n\n".
                    "Eine Vorlage benutzt sie trotzdem. Ausserhalb dieses Zusammenhangs tut sie\n".
                    "nichts — sichtbar wird das erst auf einem Bild, und dort sieht es aus wie\n".
                    "eine Absicht.\n\n".
                    'Entweder bekommt sie eine freistehende Regel, oder sie gehört mit ihrer '.
                    'Begründung in StandaloneClassTest::CONTEXT_BOUND.',
                    $klasse,
                ),
            );
        }

        /*
         * **Die Sperrklinke.** Ein Eintrag, der inzwischen eine freistehende
         * Regel hat, sieht aus wie eine bekannte Einschränkung und ist keine
         * mehr. Ohne diese Richtung wächst die Liste nie wieder nach unten.
         *
         * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl,
         * > die kleiner werden kann.**
         */
        foreach (self::CONTEXT_BOUND as $klasse => $wozu) {
            $this->assertArrayHasKey(
                $klasse,
                $offen,
                sprintf(
                    "`.%s` steht in CONTEXT_BOUND (%s) und braucht den Eintrag nicht mehr.\n\n".
                    'Entweder hat sie jetzt eine freistehende Regel — dann gehört die Zeile '.
                    'gelöscht — oder keine Vorlage benutzt sie mehr, und dann ist sie tot.',
                    $klasse,
                    $wozu,
                ),
            );
        }

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThanOrEqual(
            60,
            count($freistehend),
            'Es werden kaum freistehende Regeln gefunden — dann prüft dieser Wächter nichts.',
        );
    }

    /**
     * Und `.quiet` ist der Fall, für den es diesen Wächter gibt.
     *
     * **Ein eigener Fall neben der allgemeinen Regel**, weil die allgemeine
     * Regel ihn nur so lange fängt, wie jemand `.quiet` überhaupt benutzt.
     * Verschwände die letzte Verwendung, fiele die Klasse aus der Prüfung —
     * und käme später ohne freistehende Regel zurück.
     */
    public function test_quiet_is_not_bound_to_a_table(): void
    {
        $this->assertArrayHasKey(
            'quiet',
            $this->standalone(),
            'Leiser Text gilt wieder nur in einer Tabelle. Fünf Stellen im Panel tragen die '.
            'Klasse ausserhalb einer Zelle, und dort wäre sie wirkungslos.',
        );

        $this->assertArrayNotHasKey(
            'quiet',
            self::CONTEXT_BOUND,
            '`.quiet` steht in CONTEXT_BOUND. Leiser Text ist kein Zubehör einer Tabelle.',
        );
    }

    /**
     * Das Stylesheet ohne Kommentare — Fliesstext nennt Klassen, die keine Regel sind.
     */
    private function stylesheet(): string
    {
        $roh = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::STYLESHEET);

        return (string) preg_replace('#/\*.*?\*/#s', ' ', $roh);
    }

    /**
     * Jeder Selektor der Datei, einzeln.
     *
     * **Das Komma trennt nur ausserhalb von Klammern.** `explode(',', …)` riss
     * eine `:is(…)`-Liste auseinander, und aus
     * `:is(.field, .quiet, .section-note) + .button-row` wurde unter anderem das
     * Bruchstück `.quiet` — ein Selektor, den es nicht gibt und der aussieht wie
     * eine freistehende Regel. Genau daran ist der Eingriff des Bruchskripts am
     * 20. August wirkungslos geblieben: `.quiet` blieb freistehend, obwohl seine
     * einzige echte Regel zu `td.quiet` geworden war.
     *
     * > **Ein Trennzeichen, das innerhalb einer Klammer trennt, erfindet
     * > Selektoren.**
     *
     * @return list<string>
     */
    private function selectors(): array
    {
        preg_match_all('/([^{}]+)\{/', $this->stylesheet(), $treffer);

        $selektoren = [];

        foreach ($treffer[1] as $kopf) {
            $kopf = trim($kopf);

            if ($kopf === '' || str_starts_with($kopf, '@')) {
                continue;
            }

            foreach ($this->splitOutsideParentheses($kopf) as $teil) {
                $selektoren[] = $teil;
            }
        }

        return $selektoren;
    }

    /**
     * Eine Selektorliste am Komma trennen, aber nicht innerhalb von Klammern.
     *
     * @return list<string>
     */
    private function splitOutsideParentheses(string $kopf): array
    {
        $stuecke = [];
        $akku = '';
        $tiefe = 0;

        foreach (str_split($kopf) as $zeichen) {
            if ($zeichen === '(') {
                $tiefe++;
            } elseif ($zeichen === ')') {
                $tiefe--;
            }

            if ($zeichen === ',' && $tiefe === 0) {
                $stuecke[] = $akku;
                $akku = '';

                continue;
            }

            $akku .= $zeichen;
        }

        $stuecke[] = $akku;

        return array_values(array_filter(array_map('trim', $stuecke), static fn (string $t): bool => $t !== ''));
    }

    /**
     * Klassen mit einer freistehenden Regel.
     *
     * **Freistehend heisst: Die erste Verbindung des Selektors besteht nur aus
     * Klassen, und was folgt, liegt in ihrem eigenen Baum.** `td.quiet` ist es
     * nicht — dort muss das Element eine Zelle sein. `td .quiet` erst recht
     * nicht. `.notice` ist es, `.rows td` auch, und `.bar.over > i` ebenfalls:
     * Beide gestalten, was **unter** der Klasse steht, und das reist mit ihr.
     *
     * **Ein Geschwisterkombinator tut das nicht.** In `.quiet + .notice` wird
     * `.notice` gestaltet; `.quiet` ist nur die Bedingung dafür und bekommt
     * selbst nichts. Diese Zeile als Regel für `.quiet` zu zählen hat den
     * Eingriff des Bruchskripts am 20. August wirkungslos gemacht.
     *
     * > **Eine Regel, die den Nachbarn gestaltet, gestaltet nicht die Klasse.**
     *
     * @return array<string,true>
     */
    private function standalone(): array
    {
        $gefunden = [];

        foreach ($this->selectors() as $sel) {
            /*
             * **Nur die erste Verbindung zählt.** In `.split > .aside` steht
             * `.aside` zwar ohne Elementnamen da — aber eben hinter einem
             * Kombinator, und damit gilt es nur unter einem `.split`. Wer hier
             * jede Verbindung zählte, hielte jede Nachfahrenregel für eine
             * freistehende und fände genau den Fall nicht, für den es diesen
             * Wächter gibt.
             */
            [$erste, $kombinator] = $this->firstConnection($sel);

            if ($erste === '' || preg_match('/^[A-Za-z]/', $erste) === 1) {
                continue;
            }

            if ($kombinator === '+' || $kombinator === '~') {
                continue;
            }

            preg_match_all('/\.([-\w]+)/', $erste, $klassen);

            foreach ($klassen[1] as $klasse) {
                $gefunden[$klasse] = true;
            }
        }

        return $gefunden;
    }

    /**
     * Die erste Verbindung eines Selektors und der Kombinator dahinter.
     *
     * Geklammertes bleibt beisammen: In `:is(.field, .hint) + .button-row` ist
     * die erste Verbindung das ganze `:is(…)` und der Kombinator ein `+`. Wer
     * am ersten Leerzeichen schneidet, hört mitten in der Klammer auf.
     *
     * @return array{0: string, 1: string} die Verbindung und `>`, `+`, `~`,
     *                                     ein Leerzeichen oder `''`
     */
    private function firstConnection(string $sel): array
    {
        $sel = trim($sel);
        $laenge = strlen($sel);
        $tiefe = 0;
        $ende = $laenge;

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $sel[$i];

            if ($zeichen === '(') {
                $tiefe++;

                continue;
            }

            if ($zeichen === ')') {
                $tiefe--;

                continue;
            }

            if ($tiefe === 0 && ($zeichen === ' ' || $zeichen === '>' || $zeichen === '+' || $zeichen === '~')) {
                $ende = $i;

                break;
            }
        }

        $erste = substr($sel, 0, $ende);
        $rest = ltrim(substr($sel, $ende));

        if ($rest === '') {
            return [$erste, ''];
        }

        $kombinator = $rest[0];

        return [$erste, in_array($kombinator, ['>', '+', '~'], true) ? $kombinator : ' '];
    }

    /**
     * Klassen, die `app.css` überhaupt nennt.
     *
     * @return array<string,true>
     */
    private function mentioned(): array
    {
        $gefunden = [];

        foreach ($this->selectors() as $sel) {
            preg_match_all('/\.([-\w]+)/', $sel, $klassen);

            foreach ($klassen[1] as $klasse) {
                $gefunden[$klasse] = true;
            }
        }

        return $gefunden;
    }

    /**
     * Jede Klasse, die eine Vorlage vergibt.
     *
     * Gelesen wird nur das `<template>` einer Datei: Was im Skriptteil steht,
     * ist eine Zeichenkette und kein Markup.
     *
     * @return list<string>
     */
    private function usedInTemplates(): array
    {
        $gefunden = [];
        $wurzel = dirname(__DIR__, 2).'/resources/js';

        foreach ($this->vueFiles($wurzel) as $datei) {
            $quelle = (string) file_get_contents($datei);

            if (preg_match('#<template>(.*)</template>#s', $quelle, $block) !== 1) {
                continue;
            }

            preg_match_all('/class="([^"]*)"/', $block[1], $treffer);

            foreach ($treffer[1] as $liste) {
                foreach (preg_split('/\s+/', trim($liste)) ?: [] as $klasse) {
                    if (preg_match('/^[-\w]+$/', $klasse) === 1) {
                        $gefunden[$klasse] = true;
                    }
                }
            }
        }

        return array_keys($gefunden);
    }

    /**
     * Alle `.vue`-Dateien unterhalb eines Verzeichnisses.
     *
     * @return list<string>
     */
    private function vueFiles(string $wurzel): array
    {
        $dateien = [];

        foreach ((array) scandir($wurzel) as $eintrag) {
            if (! is_string($eintrag) || $eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $pfad = $wurzel.'/'.$eintrag;

            if (is_dir($pfad)) {
                $dateien = array_merge($dateien, $this->vueFiles($pfad));

                continue;
            }

            if (str_ends_with($eintrag, '.vue')) {
                $dateien[] = $pfad;
            }
        }

        return $dateien;
    }
}
