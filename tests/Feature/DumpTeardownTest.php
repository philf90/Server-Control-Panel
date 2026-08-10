<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Databases\DatabasePrune;
use App\Support\Databases\Dumps;
use App\Support\Operations\Lifecycles;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Rückbau nimmt die Sicherungen mit — die Dateien **und** ihre Zeilen.
 *
 * **Der Anlass ist ein Kommentar, der das Gegenteil versprach.**
 * `DbLifecycle::afterDump()` sagte damals: „Der Rückbau eines ganzen Abonnements trägt
 * keinen Gegenstand — dort verschwinden die Zeilen mit dem Abonnement." Sie
 * verschwanden nicht. `database_dumps.subscription_id` steht mit Absicht auf
 * `nullOnDelete` (§7.2), damit eine Sicherung ihre Datenbank überlebt; nach
 * einem erfolgreichen Rückbau ist die Datei aber fort, und der Wegweiser zeigt
 * ins Leere. (Die Methode heisst seit Schritt 6 `DumpLifecycle::afterSuccess()`
 * und gilt für beide Datenbanksysteme.)
 *
 * **Gefunden am 8. August 2026 auf `cloudsrv24`**, und zwar nicht von einer
 * Abfrage an MariaDB, sondern von `srvpanel db`: Es zählte drei Sicherungen,
 * während zwei auf der Platte lagen, und meldete eine Zeile ohne Abonnement
 * (`docs/36 §22.3r`). Das ist die teurere Hälfte des Fehlers — ein Rückbau, der
 * sauber gelaufen ist, meldet einen Rest, und ein Melder, der jedes Mal Alarm
 * gibt, wird bald gelesen wie ein Rauschen.
 *
 * **Geprüft wird an der Stelle, die es gemeldet hat.** Ein Test, der nur die
 * Zeilen zählt, bliebe grün, wenn `DatabasePrune` seine Auswahl ändert; hier
 * steht am Ende dieselbe Frage wie im Kommando: Was meldet der Bestand, nachdem
 * das Abonnement fort ist?
 */
final class DumpTeardownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     */
    private function unrestricted(callable $work): mixed
    {
        return app(Tenancy::class)->withoutRestriction($work);
    }

    /**
     * Ein Abonnement mit einer Datenbank und zwei Sicherungen.
     *
     * @return array{0: Subscription, 1: Database}
     */
    private function subscriptionWithDumps(string $systemUser = 'p1000'): array
    {
        return $this->unrestricted(function () use ($systemUser): array {
            $subscription = Subscription::factory()->create(['system_user' => $systemUser]);
            $database = Database::factory()->forSubscription($subscription, 'shop')->create();

            DatabaseDump::factory()->count(2)->forDatabase($database)->create();

            return [$subscription, $database];
        });
    }

    /**
     * Den Vorgang fahren, den der Rückbau einreiht.
     *
     * Der Zustand wird gesetzt und der Lebenslauf gerufen — genau die
     * Reihenfolge aus `RunAgentOperation`, nur ohne Agenten. Was hier geprüft
     * wird, ist die Nacharbeit im Panel und nicht der Aufruf.
     */
    private function runTeardownOperation(Subscription $subscription): Operation
    {
        Queue::fake();

        $operation = app(Dumps::class)->removeAllFor($subscription);

        $this->assertNotNull($operation, 'Der Rückbau reiht keinen Vorgang für die Sicherungen ein.');

        $operation->forceFill(['status' => OperationStatus::Succeeded, 'result' => []])->save();

        /*
         * **Über {@see Lifecycles} und nicht über einen Handler unmittelbar.**
         * Bis Schritt 6 stand hier `DbLifecycle`, und mit dem Umzug der
         * Dump-Aufgaben nach `DumpLifecycle` zeigte der Test auf einen Ort, an
         * dem die Regel nicht mehr steht — er meldete Rot für eine Ordnung, die
         * er absichern soll.
         *
         * Der Weg über die Sammelstelle ist ausserdem der, den die Anwendung
         * geht: Zieht die Regel noch einmal um, bleibt dieser Test richtig.
         */
        app(Lifecycles::class)->afterSuccess($operation);

        return $operation;
    }

    public function test_the_teardown_takes_the_rows_along(): void
    {
        [$subscription] = $this->subscriptionWithDumps();

        $this->assertSame(2, $this->unrestricted(fn (): int => DatabaseDump::query()->count()));

        $this->runTeardownOperation($subscription);

        $this->assertSame(0, $this->unrestricted(fn (): int => DatabaseDump::query()->count()));
    }

    /**
     * Und der Bestand meldet danach nichts.
     *
     * **Das ist die Behauptung, die auf dem Server fehlgeschlagen ist**, und sie
     * steht hier bewusst nach dem harten Löschen des Abonnements: Solange es die
     * Zeile noch gibt, trägt die Sicherung ihr `subscription_id` und fiele
     * `DatabasePrune` gar nicht auf. Der Rest entsteht erst in dem Augenblick,
     * in dem `subscription.remove` durch ist — also einen Vorgang später.
     */
    public function test_nothing_is_left_over_after_the_subscription_is_gone(): void
    {
        [$subscription] = $this->subscriptionWithDumps();

        $this->runTeardownOperation($subscription);

        $this->unrestricted(function () use ($subscription): void {
            $subscription->forceDelete();
        });

        $this->assertSame([], app(DatabasePrune::class)->plan()['dumps']);
    }

    /**
     * Die Gegenprobe: ohne den Vorgang bleibt die Zeile — und wird gemeldet.
     *
     * Ohne sie sagte der Test oben nur, dass am Ende nichts dasteht, und das
     * wäre auch wahr, wenn die Zeilen nie entstanden wären. Hier entsteht
     * derselbe Zustand ohne den Rückbau der Sicherungen, und `DatabasePrune`
     * meldet ihn — der Rest ist also echt und der Melder ist wach.
     */
    public function test_without_that_operation_the_row_stays_and_is_reported(): void
    {
        [$subscription] = $this->subscriptionWithDumps();

        $this->unrestricted(function () use ($subscription): void {
            $subscription->forceDelete();
        });

        $this->assertCount(2, app(DatabasePrune::class)->plan()['dumps']);
    }

    /**
     * Und er nimmt nur seine eigenen mit.
     *
     * Ein `delete()` über die ganze Tabelle bestünde jeden Test oben. Die
     * Bedingung hängt an `subscription_id` des Vorgangs, und dass sie das tut,
     * sieht man nur an einem zweiten Abonnement daneben.
     */
    public function test_the_neighbour_keeps_its_dumps(): void
    {
        [$own] = $this->subscriptionWithDumps('p1000');
        [$foreign] = $this->subscriptionWithDumps('p1001');

        $this->runTeardownOperation($own);

        $remaining = $this->unrestricted(
            fn (): int => DatabaseDump::query()->where('subscription_id', $foreign->id)->count(),
        );

        $this->assertSame(2, $remaining);
    }

    /**
     * Ein Vorgang mit Gegenstand entfernt weiter genau eine Sicherung.
     *
     * Die neue Bedingung sitzt im Zweig „kein Gegenstand". Griffe sie eine
     * Zeile zu früh, nähme das Entfernen einer einzelnen Sicherung alle
     * anderen des Abonnements mit — und das sähe in der Oberfläche aus wie ein
     * Klick, der zu viel getan hat.
     */
    public function test_removing_a_single_dump_still_removes_only_that_one(): void
    {
        [$subscription] = $this->subscriptionWithDumps();

        $dump = $this->unrestricted(
            fn (): DatabaseDump => DatabaseDump::query()->where('subscription_id', $subscription->id)->firstOrFail(),
        );

        Queue::fake();

        $operation = app(Dumps::class)->remove($dump);
        $operation->forceFill(['status' => OperationStatus::Succeeded, 'result' => []])->save();

        /*
         * **Über {@see Lifecycles} und nicht über einen Handler unmittelbar.**
         * Bis Schritt 6 stand hier `DbLifecycle`, und mit dem Umzug der
         * Dump-Aufgaben nach `DumpLifecycle` zeigte der Test auf einen Ort, an
         * dem die Regel nicht mehr steht — er meldete Rot für eine Ordnung, die
         * er absichern soll.
         *
         * Der Weg über die Sammelstelle ist ausserdem der, den die Anwendung
         * geht: Zieht die Regel noch einmal um, bleibt dieser Test richtig.
         */
        app(Lifecycles::class)->afterSuccess($operation);

        $this->assertSame(1, $this->unrestricted(fn (): int => DatabaseDump::query()->count()));

        // Und es ist die andere, die steht — nicht irgendeine.
        $this->assertFalse($this->unrestricted(
            fn (): bool => DatabaseDump::query()->whereKey($dump->id)->exists(),
        ));
    }
}
