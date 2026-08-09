<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DumpStatus;
use App\Enums\OperationStatus;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Databases\DbLifecycle;
use App\Support\Databases\Staging;
use App\Support\Operations\AfterOperation;
use App\Support\Operations\Lifecycles;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ein gescheitertes Hochladen lässt nichts liegen.
 *
 * **Gefunden am 9. August 2026 auf `cloudsrv24`** (`docs/36 §22.3w`): Eine
 * abgewiesene Zip-Bombe von 109 MB lag unangetastet in der Übergabe, und nichts
 * im ganzen System hätte sie je wieder angefasst. Das `@unlink()` im
 * Steuerungscode umschliesst das *Einreihen* des Vorgangs; der Agent weist aber
 * erst später im Arbeiter ab, und dort gab es bis dahin überhaupt keine
 * Gegenrichtung.
 *
 * Bis zu 512 MB je Versuch, ausgelöst von einem Kunden, ohne Weg zurück — das
 * ist die Lehre aus `docs/35` an einer neuen Stelle.
 *
 * **Zwei Dinge werden hier geprüft, und das zweite ist das unbequemere:**
 *
 * 1. Der Fehlschlag räumt die Datei weg und schreibt den Grund an die Zeile.
 * 2. **Er löscht nur innerhalb der Übergabe.** Der Pfad kommt aus dem Auftrag
 *    eines Vorgangs, also aus der Datenbank; ein `unlink()` darauf ohne
 *    Prüfung wäre die Sorte Zeile, mit der ein Panel sich selbst löscht.
 */
final class ImportCleanupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Operation, 1: DatabaseDump, 2: string} */
    private function failedImport(?string $source = null): array
    {
        return app(Tenancy::class)->withoutRestriction(function () use ($source): array {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);
            $database = Database::factory()->forSubscription($subscription, 'shop')->create();

            $path = $source ?? Staging::ensure().'/'.bin2hex(random_bytes(8)).'.sql.gz';
            file_put_contents($path, "\x1f\x8bnicht wirklich gepackt");

            $dump = DatabaseDump::factory()->create([
                'database_id' => $database->id,
                'database_name' => $database->name,
                'subscription_id' => $subscription->id,
                'kind' => 'imported',
                'status' => DumpStatus::Pending,
            ]);

            $operation = Operation::factory()->create([
                'subscription_id' => $subscription->id,
                'type' => 'db.dump.import',
                'task' => 'db.dump.import',
                'subject_type' => DatabaseDump::class,
                'subject_id' => $dump->id,
                'status' => OperationStatus::Failed,
                'message' => 'Ausgepackt ist die Sicherung grösser als 20 GB.',
                'payload' => ['source' => $path],
            ]);

            return [$operation, $dump, $path];
        });
    }

    public function test_a_failed_import_removes_the_uploaded_file(): void
    {
        [$operation, , $path] = $this->failedImport();

        $this->assertFileExists($path, 'Die Übergabedatei war schon vor dem Lauf weg — dann prüft dieser Test nichts.');

        app(Lifecycles::class)->afterFailure($operation);

        $this->assertFileDoesNotExist($path, implode("\n", [
            'Die hochgeladene Datei bleibt nach einem gescheiterten Import liegen.',
            '',
            'Am 9. August 2026 lagen so 109 MB in der Übergabe, die nichts im System je',
            'wieder angefasst hätte — srvpanel db --prune sieht nur Zeilen ohne Abonnement an,',
            'und über das Panel ist die Datei gar nicht erreichbar.',
        ]));
    }

    /**
     * Und die Zeile sagt danach, was los war.
     *
     * `DumpStatus::Failed` und `last_error` gibt es seit Schritt 6; gesetzt hat
     * sie bis zum 9. August niemand, weil {@see AfterOperation} keine
     * Gegenrichtung hatte (`docs/36 §22.3u`). Ein Kunde sah einen Vorgang, der
     * nie fertig wurde.
     */
    public function test_a_failed_import_marks_the_record(): void
    {
        [$operation, $dump] = $this->failedImport();

        app(Lifecycles::class)->afterFailure($operation);

        $dump->refresh();

        $this->assertSame(DumpStatus::Failed, $dump->status, 'Die Zeile steht weiter auf „läuft".');
        $this->assertNotNull($dump->last_error, 'Der Grund des Fehlschlags steht nirgends.');
        $this->assertStringContainsString(
            '20 GB',
            (string) $dump->last_error,
            'Der Grund kommt nicht vom Vorgang — dann sind es zwei Auskünfte über denselben Fehlschlag.',
        );
    }

    /**
     * Ein Pfad ausserhalb der Übergabe wird nicht angefasst.
     *
     * **Der Bruch dazu ist der Grund für diese Prüfung.** Ohne die Wurzelprüfung
     * in {@see Staging::forget()} löscht der Lebenslauf, was in der Zeile
     * steht — und in der Zeile steht, was einmal jemand hineingeschrieben hat.
     */
    public function test_a_path_outside_the_handover_is_left_alone(): void
    {
        $fremd = storage_path('app/private/fremde-datei.sql.gz');
        @mkdir(dirname($fremd), 0o700, true);
        file_put_contents($fremd, 'gehört jemand anderem');

        [$operation] = $this->failedImport($fremd);

        app(Lifecycles::class)->afterFailure($operation);

        $this->assertFileExists($fremd, 'Der Lebenslauf löscht ausserhalb der Übergabe.');

        @unlink($fremd);
    }

    /**
     * Und jeder Lebenslauf beantwortet die Frage überhaupt.
     *
     * **Die mechanische Hälfte.** Ohne sie könnte jemand einen fünften
     * Lebenslauf schreiben, `afterFailure()` leer lassen, und niemand fragte
     * nach dem Grund — dieselbe Begründung, aus der es
     * {@see Lifecycles::HANDLERS} und `LifecycleReachTest` gibt.
     */
    public function test_every_lifecycle_answers_the_failure(): void
    {
        $this->assertGreaterThan(2, count(Lifecycles::HANDLERS), 'Es werden kaum Lebensläufe geprüft.');

        foreach (Lifecycles::HANDLERS as $handler) {
            $this->assertTrue(
                method_exists($handler, 'afterFailure'),
                sprintf('%s beantwortet den Fehlschlag nicht.', $handler),
            );
        }

        // Und der, der tatsächlich etwas tut, steht dazwischen.
        $this->assertContains(DbLifecycle::class, Lifecycles::HANDLERS);
    }
}
