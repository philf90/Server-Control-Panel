<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Über die Zeitzone einer Anzeige entscheidet der Server und nicht der Browser.
 *
 * ## Der Anlass
 *
 * Auf der Domainseite standen zwei Zeitstempel aus zwei Quellen: Die
 * Vorgangsliste rendert über `Clock::display()`, also in der **eingestellten**
 * Anzeigezone; der DNS-Abgleich schickte ISO-8601 an den Browser und liess dort
 * `new Date().toLocaleString()` rechnen — also in der Zone des **Browsers**.
 *
 * Gefunden in der Zwischenabnahme von P7 (`docs/74`, Befund 3), und nur, weil
 * jemand die beiden Zahlen nebeneinander gelesen hat. Aufgefallen wäre es
 * niemandem: Auf dem Messrechner waren beide Zonen dieselbe.
 *
 * > **Zwei Zeitangaben auf einer Seite, die in verschiedenen Zonen rechnen,
 * > sind schlimmer als eine falsche: Man kann sie miteinander vergleichen.**
 *
 * `docs/40` hat `App\Support\Time\Clock` als *die eine Stelle* gebaut, die aus
 * UTC eine Anzeige macht. Eine zweite Stelle im Browser ist genau die zweite
 * Fassung, die dieses Projekt immer wieder einholt — und die zweite ist die,
 * die veraltet.
 *
 * ## Warum eine gezählte Zahl und keine Ausnahmeliste
 *
 * Beim Bau dieses Wächters standen **fünf** Fundstellen im Bestand. Sie alle
 * mitzuändern hiesse, das sichtbare Format auf vier weiteren Seiten zu
 * verstellen — bei den Zertifikaten von `20.11.2026` auf `2026-11-20 …` —, und
 * dafür gibt es keine Messung und keinen Auftrag.
 *
 * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
 * > kleiner werden kann.**
 *
 * Wer eine davon behebt, trägt die Zahl hier herunter. Wer eine neue anlegt,
 * bekommt Rot.
 */
final class DisplayTimeZoneTest extends TestCase
{
    /**
     * Was heute noch im Browser rechnet — je Datei die Anzahl.
     *
     * **Zwei Sorten, und die erste ist die schlimmere.**
     *
     * `Databases/Show.vue` formatiert `size_measured_at`, einen Zeitstempel aus
     * **unserer eigenen Datenbank**. Er gehört über `Clock`, genau wie der des
     * DNS-Abgleichs — es ist derselbe Befund, nur ungemeldet.
     *
     * Die anderen vier formatieren Sekunden seit 1970, die vom Agenten kommen:
     * die Gültigkeit eines Zertifikats (`Domains/Show.vue`, `Settings/Tls.vue`),
     * die Änderungszeit einer Datei (`Files/Index.vue`) und den Ablauf eines
     * Zugangs (`Components/DnsCredentials.vue`). Auch die gehören über `Clock`;
     * ihr Umbau ändert aber das sichtbare Format und braucht deshalb seine
     * eigene Runde mit eigenen Aufnahmen.
     *
     * @var array<string, int>
     */
    private const COUNTED = [
        'resources/js/Components/DnsCredentials.vue' => 1,
        'resources/js/Pages/Databases/Show.vue' => 1,
        'resources/js/Pages/Domains/Show.vue' => 1,
        'resources/js/Pages/Files/Index.vue' => 1,
        'resources/js/Pages/Settings/Tls.vue' => 1,
    ];

    /**
     * Jede Stelle, an der eine gespeicherte Zeit im Browser zu Text wird.
     *
     * **Zeilenweise und nicht über einen Ausdruck über die ganze Datei.** Was
     * gesucht wird, ist `new Date(…)` **und** ein `toLocale…` in derselben
     * Zeile; eine Zahl, die `toLocaleString` für Tausendertrennzeichen benutzt
     * (`bytes.ts`, `Overview.vue`), ist keine Zeit und darf nicht mitgezählt
     * werden.
     *
     * @return array<string, int>
     */
    private function sites(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $gefunden = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if (! $datei->isFile() || ! in_array($datei->getExtension(), ['vue', 'ts'], true)) {
                continue;
            }

            $pfad = str_replace($wurzel.'/', '', $datei->getPathname());

            foreach (explode("\n", (string) file_get_contents($datei->getPathname())) as $zeile) {
                if (str_contains($zeile, 'new Date(') && preg_match('/\.toLocale\w+\s*\(/', $zeile) === 1) {
                    $gefunden[$pfad] = ($gefunden[$pfad] ?? 0) + 1;
                }
            }
        }

        ksort($gefunden);

        return $gefunden;
    }

    /**
     * Keine neue Stelle entscheidet die Zeitzone im Browser.
     */
    public function test_no_new_place_decides_the_time_zone_in_the_browser(): void
    {
        $zuviel = [];

        foreach ($this->sites() as $pfad => $anzahl) {
            $erlaubt = self::COUNTED[$pfad] ?? 0;

            if ($anzahl > $erlaubt) {
                $zuviel[] = sprintf('%s: %d statt %d', $pfad, $anzahl, $erlaubt);
            }
        }

        $this->assertSame([], $zuviel, implode("\n", [
            'Hier wird eine gespeicherte Zeit im Browser zu Text:',
            ...$zuviel,
            '',
            'Damit entscheidet die Zone des Betrachters und nicht die eingestellte',
            'Anzeigezone. Steht auf derselben Seite ein Zeitstempel aus',
            'Clock::display(), rechnen zwei Angaben nebeneinander in zwei Zonen —',
            'und niemand sieht es, solange beide zufaellig dieselbe sind.',
            '',
            'Der Weg: den Wert serverseitig durch App\\Support\\Time\\Clock schicken',
            'und im Template nur noch drucken.',
        ]));
    }

    /**
     * Und keine gezählte Stelle überlebt ihre Behebung.
     *
     * **Das ist zugleich die Gegenprobe zum Ausdruck.** Trifft er nichts mehr,
     * findet dieser Fall null Stellen, wo fünf stehen — und wird rot, statt
     * stillschweigend „alles in Ordnung" zu melden.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_every_counted_place_still_exists(): void
    {
        $gefunden = $this->sites();
        $tot = [];

        foreach (self::COUNTED as $pfad => $anzahl) {
            $ist = $gefunden[$pfad] ?? 0;

            if ($ist < $anzahl) {
                $tot[] = sprintf('%s: %d statt %d', $pfad, $ist, $anzahl);
            }
        }

        $this->assertSame([], $tot, implode("\n", [
            'Diese Stellen sind gezaehlt und stehen nicht mehr da:',
            ...$tot,
            '',
            'Entweder ist eine behoben — dann gehoert die Zahl hier heruntergesetzt,',
            'damit die Liste nicht laenger behauptet, als sie deckt. Oder der',
            'Ausdruck trifft nicht mehr, und dann misst dieser Waechter nichts.',
        ]));
    }
}
