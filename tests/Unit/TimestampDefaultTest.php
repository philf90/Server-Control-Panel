<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Eine Spalte, deren Vorgabe die Datenbank selbst einsetzt, wird benannt.
 *
 * ## Der Fehler, den es dafür gebraucht hat
 *
 * `findings` bekam zwei `TIMESTAMP NOT NULL`. Gegen SQLite lief das durch — die
 * Testreihe war grün —, und auf einem echten Server tat MariaDB zweierlei, ohne
 * dass es irgendwo geschrieben stand (gemessen gegen 10.11.14, 2. September
 * 2026, mit `explicit_defaults_for_timestamp = 0`):
 *
 * - Die **erste** solche Spalte bekam
 *   `DEFAULT current_timestamp() ON UPDATE current_timestamp()`. Ein `UPDATE`,
 *   das nur `measured_at` fortschrieb, schob `first_seen_at` von
 *   `2026-09-01 03:00:00` auf `2026-09-02 21:16:21` — also auf die Wanduhr.
 *   Punkt 8 des Abnahmekriteriums von A10 hängt genau daran.
 * - Die **zweite** bekam `DEFAULT '0000-00-00 00:00:00'`, und das weist
 *   mindestens eine Zielplattform mit `1067 Invalid default value` ab. Das ist
 *   der laute Teil, an dem die CI den Fehler gefunden hat.
 *
 * > **Eine Vorgabe, die die Datenbank selbst einsetzt, steht in keiner
 * > Migration — und ein Test gegen SQLite sieht sie nie.**
 *
 * ## Die Regel
 *
 * Eine `timestamp()`-Spalte ohne `nullable()` trägt entweder ein
 * `useCurrent()` — dann ist die Vorgabe ausdrücklich gewollt und steht da — oder
 * sie ist keine `timestamp()`, sondern eine `dateTime()`.
 *
 * ## Was er nicht kann
 *
 * Er liest Text und nicht das Schema. Eine Spalte, die über rohes SQL entsteht,
 * sieht er nicht; dafür gibt es hier keine Stelle. Und er sagt nichts über
 * MySQL-Fassungen, deren Vorgabe wieder anders ist — die Regel ist so gewählt,
 * dass sie unabhängig davon trägt.
 */
final class TimestampDefaultTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Spalten, die eine `timestamp()` bleiben dürfen — mit Grund.
     *
     * **Beide sind die einzige nicht-nullbare `timestamp` ihrer Tabelle**, also
     * vom lauten Teil (`1067`) nicht betroffen, und beide werden nie
     * fortgeschrieben, ohne dass der Wert dabei selbst gesetzt wird: Ein
     * ausdrücklich übergebener Wert schlägt das `ON UPDATE`.
     *
     * Sie stehen hier und nicht in einer Migration, weil die Tabellen auf
     * laufenden Servern liegen: Ein `ALTER` dafür gehört in einen eigenen
     * Schritt und nicht in den, der A10 baut.
     *
     * > **Eine Ausnahme, die nur sagt „geht schon", ist keine — sie sagt, was
     * > sie voraussetzt.** Fällt eine dieser Voraussetzungen (eine Zeile wird
     * > angefasst, ohne den Zeitstempel mitzuschreiben), ist der Eintrag falsch
     * > und die Spalte gehört umgestellt.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        'cron_runs.started_at' => 'Wird eingefügt und nie fortgeschrieben — kein UPDATE auf cron_runs im Panel.',
        'domain_dns_checks.checked_at' => 'Jedes updateOrCreate in Dns::check() setzt checked_at selbst mit.',
    ];

    public function test_a_column_the_database_fills_in_is_named(): void
    {
        $gefunden = 0;
        $verstoesse = [];

        foreach ($this->migrations() as $pfad => $quelltext) {
            foreach ($this->timestamps($quelltext) as [$spalte, $zeile]) {
                $gefunden++;

                $schluessel = $this->tabelle($quelltext, $zeile).'.'.$spalte;

                if (isset($this->exempt()[$schluessel])) {
                    continue;
                }

                $verstoesse[] = sprintf('%s (%s)', $schluessel, basename($pfad));
            }
        }

        // **Die Untergrenze zählt, wo die Regel stehen darf.** Ohne sie stünde
        // dieser Wächter nach einem Umbau auf null und meldete Grün für eine
        // Ordnung, die er nie gesehen hat.
        $this->assertGreaterThanOrEqual(2, $gefunden, 'Der Ausdruck findet keine einzige nicht-nullbare timestamp-Spalte — dann misst er nichts.');

        $this->assertSame([], $verstoesse, implode("\n", [
            'Diese Spalten sind `timestamp()` ohne `nullable()` und ohne `useCurrent()`.',
            'MariaDB setzt dort selbst eine Vorgabe ein: die erste je Tabelle bekommt',
            '`ON UPDATE current_timestamp()`, jede weitere `DEFAULT \'0000-00-00\'`.',
            'Entweder `dateTime()` — oder `useCurrent()`, wenn die Vorgabe gewollt ist.',
            'Betroffen: '.implode(', ', $verstoesse),
        ]));
    }

    /**
     * Jeder Eintrag der Ausnahmeliste nennt eine Spalte, die es gibt.
     *
     * Die Gegenrichtung, und die ist die, an der ein toter Eintrag entsteht:
     * Wird eine Spalte umgestellt oder umbenannt, bleibt ihr Freibrief stehen
     * und deckt beim nächsten Mal etwas anderes.
     */
    public function test_every_exemption_names_a_column_that_exists(): void
    {
        $vorhanden = [];

        foreach ($this->migrations() as $quelltext) {
            foreach ($this->timestamps($quelltext) as [$spalte, $zeile]) {
                $vorhanden[$this->tabelle($quelltext, $zeile).'.'.$spalte] = true;
            }
        }

        foreach (array_keys($this->exempt()) as $schluessel) {
            $this->assertArrayHasKey($schluessel, $vorhanden, sprintf(
                '%s steht in der Ausnahmeliste und ist keine nicht-nullbare timestamp-Spalte mehr — der Eintrag gehört heraus.',
                $schluessel,
            ));
        }
    }

    /**
     * Die Ausnahmeliste, mit ihrem Typ statt mit ihrem Inhalt.
     *
     * **Der Rückgabetyp tut das allein, und die `@var`-Zeile darunter war
     * falsch.** `BrowserDialogTest::exempt()` hat eine — dort ist die Liste
     * **leer**, und ohne die Zeile hätte `isset()` auf einem `array{}` keinen
     * Schlüssel, den es geben könnte. Hier steht etwas drin: Der Wert hat die
     * genaue Form der beiden Einträge, und ein `@var array<string, string>`
     * darüber ist keine Verengung, sondern eine Erweiterung — PHPStan weist das
     * mit `varTag.nativeType` ab.
     *
     * > **Ein Muster, das man von einer Stelle übernimmt, bringt deren
     * > Voraussetzung nicht mit.**
     *
     * Der deklarierte Rückgabetyp reicht in beide Lagen: Die Form der Einträge
     * ist ein Untertyp von `array<string, string>`, ein leeres `array{}` auch —
     * und der Aufrufer sieht nur den weiten Typ, `isset()` ist damit nie
     * unmöglich.
     *
     * @return array<string, string>
     */
    private function exempt(): array
    {
        return self::EXEMPT;
    }

    /**
     * Der Quelltext jeder Migration, ohne Kommentare.
     *
     * **Ohne Kommentare, und heute ändert das nichts** — gemessen am
     * 2. September 2026: roh 25 Treffer, ohne Kommentare dieselben 25. Es steht
     * hier trotzdem, weil in diesem Repo **jede** Behebung ihren Vorzustand im
     * Kommentar festhält: Der nächste, der erklärt, warum hier kein
     * `->timestamp('…')` mehr steht, schreibt es dabei hin.
     *
     * > **Ein Kommentar, der die entfernte Zeile zitiert, stellt sie für einen
     * > Ausdruck wieder her.** `OutcomeTest` ist am 1. September genau daran
     * > fälschlich grün geblieben.
     *
     * @return array<string, string>
     */
    private function migrations(): array
    {
        $quellen = [];

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $pfad) {
            $quellen[$pfad] = $this->withoutComments((string) file_get_contents($pfad));
        }

        return $quellen;
    }

    /**
     * Die nicht-nullbaren `timestamp()`-Spalten einer Migration.
     *
     * Gelesen wird die **ganze Kette** hinter dem Aufruf bis zum Semikolon:
     * `->nullable()` und `->useCurrent()` dürfen an jeder Stelle darin stehen,
     * und `->after(…)` oder `->index()` dazwischen ändern nichts.
     *
     * @return list<array{0: string, 1: int}> Spaltenname und Zeilennummer
     */
    private function timestamps(string $quelltext): array
    {
        $treffer = [];

        if (preg_match_all('/->timestamp\(\s*\'([a-z0-9_]+)\'[^;]*;/i', $quelltext, $funde, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($funde[0] as $index => [$kette, $offset]) {
            if (str_contains($kette, 'nullable()') || str_contains($kette, 'useCurrent()')) {
                continue;
            }

            $treffer[] = [
                (string) $funde[1][$index][0],
                substr_count(substr($quelltext, 0, (int) $offset), "\n") + 1,
            ];
        }

        return $treffer;
    }

    /**
     * Die Tabelle, in der eine Zeile steht.
     *
     * Gesucht wird rückwärts nach dem letzten `Schema::create('…'` oder
     * `Schema::table('…'` **vor** der Zeile — eine Datei kann mehrere Tabellen
     * anlegen, und `create_core_tables` tut genau das.
     */
    private function tabelle(string $quelltext, int $zeile): string
    {
        $zeilen = explode("\n", $quelltext);
        $name = '?';

        for ($i = 0; $i < $zeile && $i < count($zeilen); $i++) {
            if (preg_match('/Schema::(?:create|table)\(\s*\'([a-z0-9_]+)\'/i', $zeilen[$i], $fund) === 1) {
                $name = $fund[1];
            }
        }

        return $name;
    }
}
