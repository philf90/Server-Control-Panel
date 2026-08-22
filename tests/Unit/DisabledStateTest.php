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
        /*
         * **Der ganze Selektor, nicht eine Zeile.** Der erste Wurf verlangte
         * die Klasse, `disabled` und die öffnende Klammer auf **einer** Zeile
         * — und fand damit `.field input:disabled` nicht, weil dessen Selektor
         * über fünf Zeilen läuft und die Klammer erst hinter
         * `.with-unit input:disabled` steht.
         *
         * > **Ein Ausdruck, der eine Zeile liest, findet keinen Selektor, der
         * > über fünf geht.**
         */
        foreach ($this->selectors() as $selektor) {
            if (str_contains($selektor, '.'.$klasse) && str_contains($selektor, 'disabled')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jeder Selektor in `app.css` — alles zwischen `}` und der nächsten `{`.
     *
     * @return list<string>
     */
    private function selectors(): array
    {
        $ohne = (string) preg_replace('~/\\*.*?\\*/~s', ' ', $this->stylesheet());
        $selektoren = [];

        foreach (preg_split('/[{}]/', $ohne) ?: [] as $index => $stueck) {
            if ($index % 2 === 0) {
                $selektoren[] = trim($stueck);
            }
        }

        return $selektoren;
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
}
