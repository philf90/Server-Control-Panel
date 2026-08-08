<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\DbUser;
use App\Models\Subscription;
use App\Support\Databases\DatabasePrune;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Was ein misslungener Rückbau liegenlässt — und was davon fort darf.
 *
 * **Die Regel ist enger als bei den Zertifikaten, und der Unterschied ist der
 * Punkt.** Ein Zertifikat überlebt sein Abonnement als Wegweiser: Die Zeile
 * bleibt stehen, damit `srvpanel tls --prune` die Datei findet, die niemand
 * mehr kennt. Eine Datenbank soll das gerade **nicht** — sie enthält die Daten
 * eines Kunden, und Leitbild 4 verlangt, dass ein Modul beim Rückbau
 * vollständig aufräumt (docs/36 §5).
 *
 * Eine Zeile ohne Abonnement ist hier also kein vorgesehener Zustand, sondern
 * der Beleg für einen Vorgang, der nicht durchgelaufen ist. Genau deshalb gibt
 * es sie: Ohne die Abschrift `subscription_name` und den `nullOnDelete` nähme
 * die Kaskade die Zeile mit, und das Schema läge in `/var/lib/mysql`, ohne dass
 * noch irgendetwas darauf zeigt — Wort für Wort der Zustand, in dem der
 * Zielserver am 7. August 2026 war, nur mit Kundendaten statt mit einem
 * privaten Schlüssel.
 *
 * **Was dieser Test absichert, ist die Auswahl und nicht das Löschen.** Sie
 * entscheidet, ob die Daten eines Kunden von der Platte gehen. Sie steht in
 * {@see DatabasePrune} und nicht im Kommando, damit dieser Test sie prüfen
 * kann, ohne sie nachzubauen — zwei Fassungen derselben Regel, und die im Test
 * bleibt grün, während die im Kommando abdriftet.
 */
final class DatabasePruneTest extends TestCase
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

    private function prune(): DatabasePrune
    {
        return app(DatabasePrune::class);
    }

    /**
     * Eine Zeile, wie sie nach dem Rückbau ihres Abonnements aussieht.
     *
     * Angelegt **mit** Abonnement und danach entkoppelt — nicht von Hand auf
     * `null` gesetzt: Die Abschrift entsteht beim Anlegen, und ein Waise, den
     * der Test selbst zusammenschreibt, hätte sie womöglich anders. Geprüft
     * werden soll, was die Anwendung erzeugt.
     */
    private function orphanedDatabase(): Database
    {
        $database = Database::factory()->create([
            'subscription_id' => Subscription::factory()->create()->id,
        ]);

        $this->unrestricted(fn (): int => Database::query()
            ->whereKey($database->id)
            ->update(['subscription_id' => null]));

        return $this->unrestricted(fn (): Database => Database::query()->findOrFail($database->id));
    }

    public function test_a_database_copies_the_subscription_name(): void
    {
        $subscription = Subscription::factory()->create();
        $database = Database::factory()->create(['subscription_id' => $subscription->id]);

        $this->assertSame(
            $subscription->name,
            $database->subscription_name,
            'Ohne die Abschrift ist die Zeile nach dem Rückbau nicht mehr zuzuordnen.',
        );
    }

    public function test_a_row_that_still_has_its_subscription_is_never_touched(): void
    {
        $subscription = Subscription::factory()->create();
        Database::factory()->create(['subscription_id' => $subscription->id]);
        DbUser::factory()->create(['subscription_id' => $subscription->id]);
        DatabaseDump::factory()->create(['subscription_id' => $subscription->id]);

        $plan = $this->prune()->plan();

        $this->assertSame(0, $plan['total'], 'Ein lebendes Abonnement darf nichts zum Aufräumen beisteuern.');
    }

    public function test_all_three_kinds_are_found(): void
    {
        $subscription = Subscription::factory()->create();

        Database::factory()->create(['subscription_id' => $subscription->id]);
        DbUser::factory()->create(['subscription_id' => $subscription->id]);
        DatabaseDump::factory()->create(['subscription_id' => $subscription->id]);

        // Der Rückbau: Das Abonnement ist fort, die drei Zeilen sind es nicht.
        $this->unrestricted(function () use ($subscription): void {
            Database::query()->where('subscription_id', $subscription->id)->update(['subscription_id' => null]);
            DbUser::query()->where('subscription_id', $subscription->id)->update(['subscription_id' => null]);
            DatabaseDump::query()->where('subscription_id', $subscription->id)->update(['subscription_id' => null]);
        });

        $plan = $this->prune()->plan();

        $this->assertCount(1, $plan['databases']);
        $this->assertCount(1, $plan['users']);
        $this->assertCount(1, $plan['dumps']);
        $this->assertSame(3, $plan['total']);

        $this->assertSame(
            $subscription->name,
            $plan['databases'][0]['subscription'],
            'Der Name des Abonnements gehört in die Meldung — er ist das, was ein Betreiber wiedererkennt.',
        );
    }

    /**
     * Ein zweites, lebendes Abonnement bleibt unberührt.
     *
     * Die Gegenprobe aus docs/36 §17 Kriterium 7, eine Ebene tiefer: **Ein
     * Aufräumen, das zu viel wegnimmt, sieht genauso erfolgreich aus wie eines,
     * das es richtig macht.** Ohne diese Behauptung wäre ein `plan()`, das
     * schlicht alle Zeilen zurückgibt, grün.
     */
    public function test_the_neighbour_is_left_alone(): void
    {
        $weg = $this->orphanedDatabase();
        $lebend = Subscription::factory()->create();
        $bleibt = Database::factory()->create(['subscription_id' => $lebend->id]);

        $plan = $this->prune()->plan();

        $namen = array_column($plan['databases'], 'name');

        $this->assertContains($weg->name, $namen);
        $this->assertNotContains($bleibt->name, $namen, 'Eine Datenbank mit Abonnement gehört nie in den Plan.');
    }

    public function test_forgetting_removes_only_the_orphan(): void
    {
        $weg = $this->orphanedDatabase();
        $lebend = Database::factory()->create(['subscription_id' => Subscription::factory()->create()->id]);

        $this->assertSame(1, $this->prune()->forgetDatabase((int) $weg->id));

        $this->assertSame(
            0,
            $this->prune()->forgetDatabase((int) $lebend->id),
            'Die Waisenbedingung muss auch beim Löschen dabeistehen — zwischen plan() und dem '.
            'Löschen kann eine Zeile wieder zu einem Abonnement gehören.',
        );

        $this->assertTrue(
            $this->unrestricted(fn (): bool => Database::query()->whereKey($lebend->id)->exists()),
        );
    }

    /**
     * Eine Zeile ohne Abschrift bleibt liegen.
     *
     * Sie kann durch die Anwendung nicht entstehen — {@see Database::booted()}
     * schreibt den Namen beim Anlegen mit. Sie käme aus einer Datenbank, an der
     * jemand von Hand war, und dann ist raten die falsche Antwort: Was das
     * Aufräumen nicht zuordnen kann, fasst es nicht an.
     */
    public function test_a_row_without_the_copied_name_is_left_alone(): void
    {
        $database = $this->orphanedDatabase();

        $this->unrestricted(fn (): int => Database::query()
            ->whereKey($database->id)
            ->update(['subscription_name' => null]));

        $this->assertSame(0, $this->prune()->plan()['total']);
        $this->assertSame(0, $this->prune()->forgetDatabase((int) $database->id));
    }
}
