<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use PHPUnit\Framework\TestCase;

/**
 * Keine Seite benutzt den Namen einer geteilten Eigenschaft.
 *
 * **Der Anlass ist der Abnahmelauf von A14 am 6. September 2026.** Auf
 * `/announcements` zeigte der Streifen ganz oben **alle** Zeilen der
 * Verwaltung statt der sichtbaren — mit abgelaufenen und wartenden darunter.
 * `AnnouncementController::index()` gab seine Liste unter dem Schlüssel
 * `announcements` heraus, und genau so heisst der geteilte. Eine
 * Seiten-Eigenschaft überschreibt eine geteilte.
 *
 * > **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf genau
 * > dieser Seite fort.**
 *
 * ## Warum es kein Fehler war, sondern ein Widerspruch
 *
 * Die Zeilen der Verwaltung tragen dieselben Felder, die der Streifen braucht
 * — `id`, `badge`, `rank`, `body`. Es gab keine Ausnahme, keine leere Seite,
 * nur den falschen Inhalt. Aufgefallen ist es, weil die Tabelle darunter zwei
 * Zeilen `wartet` und `abgelaufen` nannte und beide trotzdem oben standen.
 *
 * > **Zwei Anzeigen derselben Sache auf einer Seite, die einander
 * > widersprechen, sind der einzige Weg, diesen Fehler zu sehen.**
 *
 * ## Denselben Satz hat A9 schon bezahlt
 *
 * Die geteilte Fähigkeitsablage heisst `abilities` und nicht `can`, weil `can`
 * vergeben war: neun Seiten schicken eine eigene über ihr Objekt. Damals wurde
 * der Name geändert und **keine Regel daraus**.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * ## Und der zweite Fall war älter als A14
 *
 * Der erste Lauf dieses Wächters hat **drei** Kollisionen gefunden, nicht eine:
 * `Accounts/Form` gab das bearbeitete Konto als `account` heraus — den Namen,
 * unter dem `PanelLayout` das **angemeldete** Konto liest. Sichtbar wurde es in
 * der Fusszeile der Seitenleiste; gefährlich ist, was danebenliegt: Dieselbe
 * Ablage schaltet über `is_admin` die ganze Navigation um. Dass sie nicht
 * umsprang, lag allein daran, dass das Feld in der Seitenlast fehlt und
 * `undefined === false` falsch ergibt.
 *
 * > **Ein Feld, dessen Fehlen den Schaden verhindert, ist keine Absicherung —
 * > es ist ein Zufall mit Ablaufdatum.**
 *
 * ## Was dieser Wächter nicht kann
 *
 * Er liest die **oberste Ebene** der Aufrufe von `Inertia::render` als Text.
 * Eine Eigenschaft, die aus einem Ausdruck entsteht — `...$felder` etwa —,
 * sieht er nicht; sie stünde auch nicht als Zeichenkette da. Und über
 * `Inertia::share` an anderer Stelle sagt er nichts: Die geteilten Namen holt
 * er aus {@see HandleInertiaRequests::share()}, weil das die eine Stelle ist,
 * die sie setzt.
 */
final class SharedPropTest extends TestCase
{
    /**
     * Wieviele Eigenschaften mindestens zusammenkommen müssen.
     *
     * Gemessen am 6. September 2026: 173 auf oberster Ebene. Die Untergrenze
     * liegt deutlich darunter — sie soll nicht die Zahl festschreiben, sondern
     * den Fall abfangen, dass der Ausdruck über `Inertia::render` ins Leere
     * läuft. Dann meldet dieser Wächter nichts und sieht aus wie erfüllt.
     */
    private const AT_LEAST = 120;

    /**
     * Wie viele geteilte Namen mindestens zusammenkommen müssen.
     *
     * Gemessen am 21. September 2026: **elf** (`source`, `account`,
     * `abilities`, `impersonation`, `announcements`, `pendingUpdates`,
     * `pendingFindings`, `maintenanceBand`, `brand`, `passwordPolicy`,
     * `flash`). Die Grenze liegt darunter, weil sie nicht die Zahl
     * festschreiben soll, sondern den Fall abfangen, dass der Leser das
     * falsche Array erwischt.
     */
    private const SHARED_AT_LEAST = 8;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Namen, die `share()` auf oberster Ebene vergibt.
     *
     * @return list<string>
     */
    private function sharedKeys(): array
    {
        $quelle = (string) file_get_contents(
            $this->root().'/app/Http/Middleware/HandleInertiaRequests.php',
        );

        self::assertSame(
            1,
            preg_match('/public function share\(Request \$request\): array.*?\n    \}/s', $quelle, $rumpf),
            'share() ist nicht mehr lesbar — dieser Wächter kennt dann keine geteilten Namen.',
        );

        /*
         * **Die Rückgabe der Methode und nicht irgendein `return` in ihr.**
         *
         * Hier stand `strpos($rumpf, 'return [') ?: 0`. Die oberste Rückgabe
         * lautet `return array_merge(parent::share($request), [` — also
         * **nicht** `return [`; `strpos` gab `false`, das `?: 0` machte daraus
         * die Null, und `arrayAfter` nahm die erste `[` des Rumpfes. Das war
         * zufällig die richtige.
         *
         * > **Ein Rückfall, der zufällig das Richtige tut, ist von einer
         * > Absicht nicht zu unterscheiden — bis jemand die Bedingung
         * > herstellt, unter der er greift.**
         *
         * Hergestellt hat sie B6 am 21. September 2026: Der Verschluss für
         * `brand` trägt ein eigenes `return [`, und ab da las dieser Wächter
         * dessen drei Schlüssel — `name`, `footer`, `logo` — als die geteilten
         * Namen. Gemeldet hat es nicht er selbst, sondern ein Eingriff des
         * Bruchskripts, der nicht mehr biss.
         *
         * Gesucht wird deshalb die Rückgabe auf der **obersten Ebene der
         * Methode**, und die erkennt man an ihrer Einrückung: acht Leerzeichen.
         * Ein `return` in einem Verschluss steht tiefer.
         */
        self::assertSame(
            1,
            preg_match('/^        return .*$/m', $rumpf[0], $rueckgabe, PREG_OFFSET_CAPTURE),
            'share() hat keine Rückgabe auf oberster Ebene — dieser Wächter liest dann irgendein Array.',
        );

        return $this->topLevelKeys($this->arrayAfter($rumpf[0], (int) $rueckgabe[0][1]));
    }

    /**
     * Der Inhalt eines Klammerpaars ab der ersten `[` hinter `$von`.
     */
    private function arrayAfter(string $text, int $von): string
    {
        $i = strpos($text, '[', $von);

        if ($i === false) {
            return '';
        }

        $tiefe = 0;

        for ($j = $i; $j < strlen($text); $j++) {
            $c = $text[$j];

            if ($c === '[' || $c === '(') {
                $tiefe++;
            } elseif ($c === ']' || $c === ')') {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($text, $i + 1, $j - $i - 1);
                }
            }
        }

        return '';
    }

    /**
     * Die Schlüssel eines Arrays, die **nicht** verschachtelt stehen.
     *
     * Ohne die Tiefe wäre jedes `'name' =>` in einem inneren Array ein
     * Treffer, und der Wächter meldete Kollisionen, die keine sind: Der erste
     * grobe Griff nannte fünf Namen, von denen zwei in verschachtelten
     * Strukturen standen.
     *
     * > **Ein Ausdruck ohne Klammertiefe liest ein Array flach — und zwei
     * > Ebenen, die dasselbe Wort benutzen, sind dann derselbe Schlüssel.**
     *
     * @return list<string>
     */
    private function topLevelKeys(string $block): array
    {
        /*
         * **Auch camelCase.** Hier stand `[a-z_][a-z0-9_]*`, und damit waren
         * vier der elf geteilten Namen für diesen Wächter nie da:
         * `pendingUpdates`, `pendingFindings`, `maintenanceBand` und
         * `passwordPolicy`. Eine Seite, die eine davon überschreibt, kam
         * durch — und die Zahl daneben sah gesund aus.
         *
         * > **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die
         * > Gewohnheit und nicht die Regel.**
         */
        preg_match_all("/'([a-zA-Z_][a-zA-Z0-9_]*)'\s*=>/", $block, $treffer, PREG_OFFSET_CAPTURE);

        $namen = [];

        foreach ($treffer[1] as [$name, $wo]) {
            $davor = substr($block, 0, $wo);
            $tiefe = substr_count($davor, '[') + substr_count($davor, '(')
                - substr_count($davor, ']') - substr_count($davor, ')');

            if ($tiefe === 0) {
                $namen[] = $name;
            }
        }

        return array_values(array_unique($namen));
    }

    public function test_no_page_prop_takes_the_name_of_a_shared_one(): void
    {
        $geteilt = $this->sharedKeys();

        /*
         * **Die Untergrenze zählt und nennt.**
         *
         * „Nicht leer" hat den Befund vom 21. September nicht gesehen: Der
         * Leser fand drei Namen, nur die falschen.
         *
         * > **Eine Untergrenze „nicht leer" ist von einer richtigen Liste nicht
         * > zu unterscheiden, sobald eine falsche Liste auch nicht leer ist.**
         *
         * `flash` steht dabei als Kanarienvogel und nicht als Liste: Es ist die
         * **letzte** geteilte Eigenschaft und gibt es seit P2. Wer ein Array
         * weiter oben liest, hat es nicht dabei.
         */
        self::assertGreaterThanOrEqual(
            self::SHARED_AT_LEAST,
            count($geteilt),
            sprintf(
                'Nur %d geteilte Namen gefunden (%s). Der Leser greift dann ein anderes Array '
                .'als das, was share() zurückgibt.',
                count($geteilt),
                implode(', ', $geteilt),
            ),
        );

        self::assertContains(
            'flash',
            $geteilt,
            'Unter den geteilten Namen fehlt `flash` — der Leser hat ein anderes Array erwischt.',
        );

        $befunde = [];
        $geprueft = 0;

        foreach (glob($this->root().'/app/Http/Controllers/{,*/}*.php', GLOB_BRACE) ?: [] as $datei) {
            $quelle = (string) file_get_contents($datei);

            preg_match_all("/Inertia::render\(\s*'([^']+)'\s*,\s*\[/", $quelle, $seiten, PREG_OFFSET_CAPTURE);

            foreach ($seiten[0] as $i => [, $wo]) {
                $block = $this->arrayAfter($quelle, $wo);

                foreach ($this->topLevelKeys($block) as $name) {
                    $geprueft++;

                    if (! in_array($name, $geteilt, true)) {
                        continue;
                    }

                    $befunde[] = sprintf(
                        '%s → %s gibt „%s" heraus; so heisst auch die geteilte Eigenschaft. '
                        .'Auf dieser Seite ist die geteilte damit fort.',
                        basename($datei),
                        $seiten[1][$i][0],
                        $name,
                    );
                }
            }
        }

        self::assertSame([], $befunde, implode("\n", $befunde));

        self::assertGreaterThanOrEqual(
            self::AT_LEAST,
            $geprueft,
            sprintf(
                'Nur %d Eigenschaften gefunden. Entweder trifft der Ausdruck Inertia::render nicht mehr, '
                .'oder die Seiten werden anders gebaut — geprüft hat dieser Wächter dann nichts.',
                $geprueft,
            ),
        );
    }
}
