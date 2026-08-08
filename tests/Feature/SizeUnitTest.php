<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Database;
use App\Models\Subscription;
use App\Support\Databases\Usage as DatabaseUsage;
use App\Support\Tenancy\Tenancy;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Der gemessene Platz bleibt eine Byte-Zahl, bis ihn jemand anzeigt.
 *
 * **Der Anlass ist ein Widerspruch im eigenen Werk, gefunden am dritten
 * Abnahmelauf vom 8. August 2026.** `DbUsageScopeTest` begründet, warum der
 * Agent Bytes liefert und nicht Megabyte:
 *
 * > Wer hier durch 1024² teilte, verlöre für jede Datenbank unter einem
 * > Megabyte die Unterscheidung zwischen „leer" und „klein" — und das ist genau
 * > die Unterscheidung, nach der jemand sucht, der eine Sicherung vermisst.
 *
 * Genau diese Division stand eine Zeile später in {@see DatabaseUsage::apply()}.
 * Die Regel galt also bis zum Socket und wurde im Panel gebrochen; die
 * Oberfläche zeigte für jede Datenbank unter einem Megabyte „0 MB", dasselbe
 * wie für eine leere.
 *
 * **Warum es der Lauf gezeigt hat und kein Test.** Die Messung meldete zwei
 * Schemata, beide zugeordnet — und die Selbsttest-Tabelle mit ihrer einen Zeile
 * belegt rund 16 KB. Ein vertauschtes Spaltenpaar, ein `NULL` statt der Summe,
 * ein Faktor daneben: alles hätte dieselbe Null ergeben. Die Zuordnung war
 * belegt und die Zahl nicht.
 *
 * Dieser Wächter hält beide Hälften der Regel: **abgelegt wird ungerechnet**,
 * und **gerundet wird an genau einer Stelle**.
 */
final class SizeUnitTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(): Subscription
    {
        return Subscription::factory()->create(['system_user' => 'p1000']);
    }

    /**
     * Die Summe je Abonnement, ohne Mandantenklammer gelesen.
     *
     * **Nicht der Bequemlichkeit wegen.** Die Klammer verweigert im
     * Grundzustand alles, und dieser Test hat kein angemeldetes Konto — ohne
     * die Ausnahme läse `databases()` null Zeilen, und die Behauptung wäre
     * grün, weil nichts da ist. Geprüft werden soll die Rechnung und nicht die
     * Klammer; für die gibt es `DbTenancyTest`.
     */
    private function usedMb(Subscription $subscription): ?int
    {
        return app(Tenancy::class)->withoutRestriction(
            static fn (): ?int => $subscription->databaseUsedMb(),
        );
    }

    private function measure(Database $database, int $bytes): void
    {
        app(DatabaseUsage::class)->apply([
            'available' => true,
            'databases' => [$database->name => $bytes],
        ]);
    }

    /**
     * Eine Datenbank mit 300 KB ist keine leere.
     *
     * Der Kern des Ganzen. Mit `intdiv(…, 1024 * 1024)` an dieser Stelle stünde
     * hier eine Null — und zwar dieselbe Null wie beim frisch angelegten Schema
     * eine Behauptung weiter unten.
     */
    public function test_a_small_database_is_not_stored_as_zero(): void
    {
        $database = Database::factory()->forSubscription($this->subscription(), 'klein')->create();

        $this->measure($database, 307_200);

        $this->assertSame(307_200, (int) $database->refresh()->size_bytes);
    }

    /** Und eine leere Datenbank bleibt eine gemessene Null, keine fehlende Messung. */
    public function test_an_empty_schema_stays_a_measured_zero(): void
    {
        $database = Database::factory()->forSubscription($this->subscription(), 'leer')->create();

        $this->assertNull($database->size_bytes, 'Vor dem ersten Lauf ist nichts gemessen.');

        // Nicht in der Antwort: `information_schema` führt ein Schema ohne
        // Tabelle nicht auf (docs/36 §9).
        app(DatabaseUsage::class)->apply(['available' => true, 'databases' => []]);

        $database->refresh();

        $this->assertSame(0, (int) $database->size_bytes);
        $this->assertNotNull($database->size_measured_at, 'Ohne den Zeitstempel wäre die Null nicht von „nie gemessen" zu unterscheiden.');
    }

    /**
     * Die Summe am Abonnement teilt erst am Ende.
     *
     * Vier Datenbanken zu je 300 KB sind 1 MB. Wer je Zeile teilte, käme auf
     * null — und das ist die Sorte Fehler, die niemand meldet, weil eine Null
     * bei einem Kontingent von 2048 MB vollkommen plausibel aussieht.
     */
    public function test_the_subscription_sums_before_it_divides(): void
    {
        $subscription = $this->subscription();

        foreach (['eins', 'zwei', 'drei', 'vier'] as $label) {
            $this->measure(
                Database::factory()->forSubscription($subscription, $label)->create(),
                307_200,
            );
        }

        $this->assertSame(1, $this->usedMb($subscription));
    }

    /** Ohne eine einzige Messung bleibt die Summe `null` und wird nicht null. */
    public function test_without_a_measurement_the_sum_is_unknown(): void
    {
        $subscription = $this->subscription();

        Database::factory()->forSubscription($subscription, 'ungemessen')->create();

        $this->assertNull($this->usedMb($subscription));
    }

    /**
     * Gerundet wird an einer Stelle, und die heisst `bytes.ts`.
     *
     * **Vor diesem Wächter gab es drei Fassungen**: eine in der Liste, eine in
     * der Einzelansicht und eine dritte für die Sicherungen — und die dritte war
     * die beste, weil sie als einzige KB kannte. Genau so driften zwei Fassungen
     * einer Regel: nicht dadurch, dass eine falsch wird, sondern dadurch, dass
     * eine besser wird und niemand die andere nachzieht.
     *
     * Gesucht wird nach dem Faktor und nicht nach dem Funktionsnamen: Wer neu
     * rechnet, schreibt `1024` hin, wie immer er seine Funktion nennt.
     */
    public function test_only_one_place_in_the_interface_turns_bytes_into_a_unit(): void
    {
        $offenders = [];
        $seen = 0;

        foreach ($this->interfaceFiles() as $path => $source) {
            if (str_ends_with($path, '/bytes.ts')) {
                $seen++;

                continue;
            }

            if (preg_match('/\b(1024|1_024|1048576|1_048_576)\b/', $source) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(1, $seen, 'resources/js/bytes.ts ist die eine Stelle — ohne sie prüft dieser Wächter nichts.');
        $this->assertSame([], $offenders, 'Eine zweite Umrechnung in der Oberfläche wird die Fassung, die niemand nachzieht.');
    }

    /**
     * Und beide Seiten, die eine Grösse zeigen, holen sie von dort.
     *
     * Die Gegenrichtung: Der Ausdruck oben bliebe auch dann grün, wenn eine
     * Seite die Zahl schlicht roh ausgäbe — „1048576" ohne Einheit enthält
     * keinen Faktor.
     */
    public function test_every_page_that_shows_a_size_uses_that_one_place(): void
    {
        $pages = 0;

        foreach ($this->interfaceFiles() as $path => $source) {
            // `bytes.ts` ist die Stelle selbst und importiert sich nicht.
            if (str_ends_with($path, '/bytes.ts')) {
                continue;
            }

            if (! str_contains($source, 'size_bytes') && ! str_contains($source, 'bytes: number')) {
                continue;
            }

            $pages++;

            // Der Pfad ist relativ und hängt an der Tiefe der Seite — geprüft
            // wird das Ziel und nicht die Zahl der Punkte davor.
            $this->assertSame(
                1,
                preg_match("/from '[^']*\\/bytes'/", $source),
                $path.' zeigt eine Byte-Zahl und rechnet sie selbst um.',
            );
        }

        $this->assertGreaterThanOrEqual(2, $pages, 'Liste und Einzelansicht zeigen beide eine Grösse.');
    }

    /**
     * @return array<string, string> Pfad => Inhalt
     */
    private function interfaceFiles(): array
    {
        $root = dirname(__DIR__, 2).'/resources/js';
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (in_array($file->getExtension(), ['vue', 'ts'], true)) {
                $files['resources/js'.substr($file->getPathname(), strlen($root))] =
                    (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
