<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein Bedienelement, das abschaltbar ist, sieht abgeschaltet aus.
 *
 * ## Der Anlass
 *
 * **Befund 3 der Bilderrunde** (`docs/76`), gemeldet vom Betreiber mitten im
 * Lauf: „Das Kästchen ‚Als Platzhalter bestellen' lässt sich gerade gar nicht
 * anklicken."
 *
 * Dass es das nicht tat, war **richtig** — ein Platzhalter geht nur über
 * DNS-01, und dafür fehlten die Zugangsdaten. Der Fehler war die Anzeige:
 * `.toggle` setzte `cursor: pointer` unbedingt, die Beschriftung stand in
 * voller `--text-strong`-Farbe, und blass war allein das Kästchen, weil der
 * Browser es blass rendert.
 *
 * > **Ein Bedienelement, das nicht bedienbar ist und trotzdem den Zeigefinger
 * > zeigt, sagt dem Kunden, er habe falsch geklickt.**
 *
 * Gefunden hat es kein Messmittel: `dokument: 0`, `schiebt: []`, Gegenprobe
 * `200/200`, vier Lagen lang. Und nicht einmal ein Betrachter — Ansehen
 * genügte nicht, es musste jemand hingreifen.
 *
 * ## Warum es einen Wächter braucht und nicht nur eine Regel in `app.css`
 *
 * Die Lösung stand seit Monaten **in derselben Datei**, ein paar hundert
 * Zeilen weiter oben, mit eigener Begründung im Kommentar:
 *
 *     .field input:disabled { color: var(--text-muted); background: …;
 *                             border-style: dashed; cursor: default; }
 *
 * Sie war für das Feld aufgeschrieben und für den Schalter nicht.
 *
 * > **Eine Regel, die für ein Feld gilt, gilt nicht für den Schalter daneben,
 * > bloss weil sie dieselbe ist.**
 *
 * Genau derselbe Satz stand am selben Tag über `SettingsWriterReachTest`: Dort
 * war die Erreichbarkeitsregel für den Agenten aufgeschrieben und für
 * `Settings` nicht. Zweimal an einem Tag ist kein Zufall, sondern die Bauart
 * dieses Projekts.
 *
 * ## Was er prüft
 *
 * Er liest aus den Vorlagen, **welche Hüllen** ein abschaltbares Bedienelement
 * tragen — aufgezählt, nicht aufgeschrieben —, und verlangt für jede davon
 * eine Regel in `app.css`, die den abgeschalteten Zustand kennt.
 *
 * > **Ein Wächter über eine Liste, die jemand pflegt, prüft die Pflege.**
 *
 * ## Was er nicht prüft
 *
 * Ob die Regel **gut aussieht** — Kontrast, Farbwahl, Deutlichkeit. Und ob der
 * Grund für die Sperre danebensteht: Das ist die zweite Hälfte von Befund 3
 * und hängt daran, ob eine Vorlage ihn überhaupt hat. Sie steht als
 * `.obstacle` in `app.css` und wird hier nicht erzwungen — ein Schalter, der
 * aus einem offensichtlichen Grund aus ist, braucht keinen Satz.
 */
final class DisabledStateTest extends TestCase
{
    /**
     * Ab wie oft eine Hülle als Bauart dieses Gestaltungssystems zählt.
     *
     * **Unterhalb davon ist es kein Baustein, sondern ein Einzelfall**, und
     * eine Regel für einen Einzelfall ist Rauschen. Gemessen am 22. August
     * 2026: `field` 108x, `toggle` 14x, `label` 1x.
     */
    private const IDIOM = 5;

    /**
     * Formen, die der Ein-Zustand nicht hat.
     *
     * **Warum Werte und nicht Eigenschaften.** Der erste Wurf zählte
     * *Eigenschaften* — `border`, `outline`, `appearance` — und war damit
     * zweimal zu schwach:
     *
     * - `appearance: none` ist keine Form, sondern die **Erlaubnis**, eine zu
     *   geben: Ohne sie zeichnet der Browser das Kästchen selbst. Der Eingriff,
     *   der den gestrichelten Rand entfernte, liess sie stehen — und der
     *   Wächter blieb grün.
     * - `border: 1px solid` ist eine Eigenschaft aus der Liste und sieht aus
     *   wie ein **bedienbares** Element.
     *
     * > **Ein Eingriff, der eine Regel entfernt und einen Rest stehen lässt,
     * > prüft den Rest.**
     *
     * Gefragt wird deshalb nach der Form selbst: gestrichelt, gepunktet,
     * doppelt, durchgestrichen. Keine davon trägt ein Bedienelement dieses
     * Panels im Ein-Zustand, und keine davon ist eine Farbe.
     *
     * **Warum überhaupt die Form.** Der Betreiber hat das Kästchen „Als
     * Platzhalter bestellen" ein zweites Mal gemeldet, nachdem Befund 3 behoben
     * war: „lässt sich zwar nicht klicken, hat aber immer noch nicht wirklich
     * deaktiviert gewirkt." Die Beschriftung war gedämpft, der Zeigefinger
     * fort — und das Kästchen selbst blieb dasselbe Quadrat mit einem blasseren
     * Rand.
     *
     * > **Weniger Kontrast liest sich als „unwichtig", nicht als „gesperrt".**
     *
     * Es ist ausserdem WCAG 1.4.1: Farbe darf nicht das einzige Mittel sein,
     * mit dem eine Auskunft transportiert wird.
     *
     * @var list<string>
     */
    private const FORMS = ['dashed', 'dotted', 'double', 'line-through'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Je Hülle: wie oft ein `<label>` sie trägt.
     *
     * **Warum `<label>` und nicht „irgendein Element mit `:disabled`".** Der
     * erste Wurf ging von jeder Zeile mit `:disabled` rückwärts und nahm die
     * nächste Klasse — und las damit `:class="{ … }"`-Ausdrücke mit. Im
     * Prüfstand standen `.props.profile.theme`, `.{`, `.}` und `.===`.
     *
     * > **Ein Ausdruck, der die Umgebung einer Zeile rät, rät auch bei
     * > gebundenen Attributen — und die sind kein Markup, sondern Code.**
     *
     * Ein `<label>` ist genau der Ort, um den es geht: Es zeigt Text **neben**
     * einem Bedienelement. Ist das Element aus, bleibt der Text stehen — und
     * er ist es, den der Kunde liest.
     *
     * **Die Basisklasse und nicht alle.** `class="field inline"` ist ein
     * `field` mit einer Abwandlung; die Abwandlung braucht keinen eigenen
     * Aus-Zustand.
     *
     * @return array<string, int>
     */
    private function wrappers(): array
    {
        $gefunden = [];

        foreach ($this->templates() as $vorlage) {
            foreach (preg_split('/(?=<label\\b)/', $vorlage) ?: [] as $stueck) {
                if (preg_match('/^<label\\b[^>]*\\bclass="([^"]+)"/s', $stueck, $treffer) !== 1) {
                    continue;
                }

                $basis = preg_split('/\\s+/', trim($treffer[1]))[0] ?? '';

                if ($basis === '') {
                    continue;
                }

                $gefunden[$basis] = ($gefunden[$basis] ?? 0) + 1;
            }
        }

        ksort($gefunden);

        return array_filter($gefunden, static fn (int $n): bool => $n >= self::IDIOM);
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

            $vorlagen[] = (string) preg_replace_callback(
                '/<!--.*?-->/s',
                static fn (array $t): string => str_repeat(' ', strlen($t[0])),
                $treffer[1],
            );
        }

        return $vorlagen;
    }

    private function stylesheet(): string
    {
        return (string) file_get_contents($this->root().'/resources/css/app.css');
    }

    /**
     * Kennt `app.css` einen abgeschalteten Zustand für diese Hülle?
     *
     * Gesucht wird ein Selektor, der die Klasse **und** `disabled` nennt —
     * `.toggle:has(input:disabled)` genauso wie `.field input:disabled`. Wie
     * die Regel aussieht, ist ihre Sache.
     */
    private function styled(string $klasse): bool
    {
        return $this->disabledRules($klasse) !== [];
    }

    /**
     * Die Eigenschaften, die `app.css` für den Aus-Zustand dieser Hülle setzt.
     *
     * **Der ganze Selektor, nicht eine Zeile.** Der erste Wurf verlangte die
     * Klasse, `disabled` und die öffnende Klammer auf **einer** Zeile — und
     * fand damit `.field input:disabled` nicht, weil dessen Selektor über fünf
     * Zeilen läuft und die Klammer erst hinter `.with-unit input:disabled`
     * steht.
     *
     * > **Ein Ausdruck, der eine Zeile liest, findet keinen Selektor, der über
     * > fünf geht.**
     *
     * @return list<string> die gesetzten Angaben, `name: wert`
     */
    private function disabledRules(string $klasse): array
    {
        $eigenschaften = [];

        foreach ($this->blocks() as [$selektor, $rumpf]) {
            if (! str_contains($selektor, '.'.$klasse) || ! str_contains($selektor, 'disabled')) {
                continue;
            }

            foreach (explode(';', $rumpf) as $angabe) {
                if (! str_contains($angabe, ':')) {
                    continue;
                }

                $name = trim(explode(':', $angabe, 2)[0]);

                if ($name !== '' && ! str_starts_with($name, '--')) {
                    $eigenschaften[] = trim((string) preg_replace('/\s+/', ' ', $angabe));
                }
            }
        }

        return array_values(array_unique($eigenschaften));
    }

    /**
     * Jede Regel in `app.css` als `[Selektor, Rumpf]`.
     *
     * @return list<array{string, string}>
     */
    private function blocks(): array
    {
        $ohne = (string) preg_replace('~/\\*.*?\\*/~s', ' ', $this->stylesheet());

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $ohne, $treffer, PREG_SET_ORDER);

        return array_map(
            static fn (array $t): array => [trim($t[1]), $t[2]],
            $treffer,
        );
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet der Ausdruck keine Hüllen, prüft der Fall darunter nichts und ist
     * grün, ohne etwas gesehen zu haben.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_are_wrappers_to_check(): void
    {
        $this->assertGreaterThanOrEqual(
            2,
            count($this->wrappers()),
            'Der Ausdruck findet fast keine Huelle mit einem abschaltbaren Bedienelement — er trifft nicht mehr.',
        );
    }

    /**
     * **Und die Gegenprobe zum Stylesheet-Leser.**
     *
     * Der Fall darüber sichert den Ausdruck über die Vorlagen. Er sagt nichts
     * darüber, ob {@see self::styled()} überhaupt etwas findet — ein Leser,
     * der immer `false` gibt, machte den Fall darunter für jede Hülle rot und
     * wäre damit auffällig; einer, der immer `true` gibt, wäre für immer grün.
     * Dieser Fall schliesst den zweiten aus.
     */
    public function test_the_stylesheet_reader_can_say_no(): void
    {
        $this->assertFalse(
            $this->styled('gibtesnicht'),
            'Der Leser findet einen abgeschalteten Zustand fuer eine Klasse, die es nicht gibt.',
        );

        $this->assertTrue(
            $this->styled('field'),
            'Der Leser findet den abgeschalteten Zustand von .field nicht, und den gibt es seit Monaten.',
        );
    }

    /**
     * **Und die Gegenprobe zum Leser der Eigenschaften.**
     *
     * Er darf nicht alles für eine Form halten und nicht nichts. Der
     * Prüfkörper ist `.field`: Dort steht seit Monaten `border-style: dashed`
     * neben `color` und `background` — also beides, Form und Farbe.
     */
    public function test_the_reader_tells_shape_from_colour(): void
    {
        $feld = implode(' | ', $this->disabledRules('field'));

        $this->assertStringContainsString('border-style: dashed', $feld,
            'Der Leser findet `border-style: dashed` an `.field input:disabled` nicht.');
        $this->assertStringContainsString('color:', $feld,
            'Der Leser findet `color` an `.field input:disabled` nicht.');

        $this->assertSame(
            [],
            $this->disabledRules('gibtesnicht'),
            'Der Leser findet Eigenschaften für eine Klasse, die es nicht gibt.',
        );
    }

    /**
     * Jede Hülle mit einem abschaltbaren Bedienelement hat einen sichtbaren
     * Aus-Zustand.
     */
    public function test_every_wrapper_shows_that_it_is_off(): void
    {
        $ohne = [];

        foreach ($this->wrappers() as $klasse => $anzahl) {
            if (! $this->styled($klasse)) {
                $ohne[] = sprintf('.%s (%dx)', $klasse, $anzahl);
            }
        }

        $this->assertSame([], $ohne, implode("\n", [
            'Diese Huellen tragen ein abschaltbares Bedienelement und kennen keinen',
            'abgeschalteten Zustand:',
            ...$ohne,
            '',
            'Der Browser rendert das Eingabefeld selbst blass; die Beschriftung',
            'daneben bleibt in voller Farbe, und ein cursor: pointer an der Huelle',
            'verspricht weiter, dass ein Klick etwas tut.',
            '',
            'Gemessen am 22. August 2026: Der Betreiber, der dieses System gebaut',
            'hat, hat den Zustand nicht erkannt. Kein Messmittel konnte es finden —',
            'dokument 0, schiebt leer, Gegenprobe 200/200.',
            '',
            'Der Weg: eine Regel in app.css, die die Klasse und disabled nennt, so',
            'wie .field input:disabled es seit Monaten tut.',
        ]));
    }

    /**
     * Und er sagt es durch die **Form**, nicht nur durch die Farbe.
     *
     * **Das ist die zweite Hälfte von Befund 3, und sie hat einen zweiten Lauf
     * gebraucht.** Der Fall darüber war grün, während das Kästchen aussah wie
     * eines, das man drücken kann: Die Regel gab es, sie änderte die Farbe, und
     * das genügt nicht.
     *
     * > **Ein Wächter, der fragt, ob es eine Regel gibt, sagt nichts darüber,
     * > ob man sie sieht.**
     */
    public function test_every_off_state_is_said_by_shape(): void
    {
        $nurFarbe = [];

        foreach ($this->wrappers() as $klasse => $anzahl) {
            $gesetzt = $this->disabledRules($klasse);

            if ($gesetzt === []) {
                continue;
            }

            foreach (self::FORMS as $form) {
                if (str_contains(implode(' | ', $gesetzt), $form)) {
                    continue 2;
                }
            }

            $nurFarbe[] = sprintf('.%s (%dx) — setzt nur: %s', $klasse, $anzahl, implode('; ', $gesetzt));
        }

        $this->assertSame([], $nurFarbe, implode("\n", [
            'Diese Huellen sagen ihren Aus-Zustand nur ueber die Farbe:',
            ...$nurFarbe,
            '',
            'Weniger Kontrast liest sich als „unwichtig", nicht als „gesperrt" — der',
            'Betreiber hat das Kaestchen „Als Platzhalter bestellen" genau deshalb ein',
            'zweites Mal gemeldet, nachdem es behoben war.',
            '',
            'Es ist ausserdem WCAG 1.4.1: Farbe darf nicht das einzige Mittel sein.',
            '',
            'Der Weg: eine der Formen aus DisabledStateTest::FORMS, so wie',
            '`.field input:disabled` es seit Monaten mit `border-style: dashed` tut.',
        ]));
    }
}
