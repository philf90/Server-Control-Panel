<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DbUserStatus;
use App\Enums\DomainStatus;
use App\Enums\OperationStatus;
use App\Models\DbUser;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Operations\Lifecycles;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Pg\Names;
use Tests\TestCase;

/**
 * Was mit einem Abonnement gesperrt wird, kommt mit ihm zurück.
 *
 * **Diese Datei gibt es, weil die Gegenrichtung nirgends geprüft war.** Am
 * 10. August 2026 hat der Betreiber ein Abonnement entsperrt, und die Domain
 * darunter blieb „gesperrt" — dauerhaft, ohne Knopf, der sie zurückgeholt
 * hätte. Es gab einen Wächter für das Sperren, und das Sperren war nie kaputt.
 *
 * > **Ein Wächter deckt einen Weg ab, keine Wirkung.**
 *
 * Und die vorhandenen Wächter für die Datenbankzugänge waren dieselbe Sorte:
 * `EngineScopeTest` prüft, dass beide Lebensläufe die Aufgabe *kennen*
 * (`handles()`), nicht dass danach ein Vorgang mit `mode: unlock` entsteht. Ein
 * Abonnement hat aber drei Sorten Untergebene — Websites, MariaDB-Zugänge,
 * PostgreSQL-Rollen —, und die Freigabe muss alle drei erreichen. Das ist die
 * Aussage dieser Datei, in beide Richtungen.
 *
 * **Geprüft werden die eingereihten Vorgänge und nicht der Zustand danach.**
 * Der Zustand folgt dem Agenten (CLAUDE.md, zweite Grenze), und den gibt es hier
 * nicht. Was dieses Panel schuldet, ist der Auftrag — dass er losgeht und was in
 * ihm steht.
 */
final class SubscriptionResumeReachTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    /**
     * Ein Abonnement mit allem, was darunter hängt.
     *
     * Der Zustand ist der, den ein Sperren hinterlässt: Abonnement gesperrt,
     * Domain gesperrt, beide Zugänge gesperrt. Genau daraus muss die Freigabe
     * herausführen.
     */
    private function suspended(): Subscription
    {
        return $this->tenancy()->withoutRestriction(function (): Subscription {
            $subscription = Subscription::factory()->suspended()->create([
                'name' => 'unterbau.de',
                'system_user' => 'p1077',
            ]);

            $this->claim($subscription);

            Domain::factory()->for($subscription)->create(['status' => DomainStatus::Suspended]);

            DbUser::factory()->forSubscription($subscription, 'web')
                ->create(['status' => DbUserStatus::Locked]);

            DbUser::factory()->postgres()->forSubscription($subscription, 'cron')
                ->create(['status' => DbUserStatus::Locked]);

            return $subscription;
        });
    }

    /**
     * Die Zeile im Verzeichnis der Systembenutzer — mit dem Präfix.
     *
     * **Ohne sie bricht der PostgreSQL-Lebenslauf ab**, und zwar mit
     * `RuntimeException: Zum Systembenutzer p1077 gibt es kein
     * Datenbankpräfix`. Auf einem echten Server entsteht die Zeile in
     * `Lifecycle::claim()` zusammen mit dem Systembenutzer; die Fabrik kennt sie
     * nicht, weil sie nicht zum Abonnement gehört, sondern zum Server
     * (`docs/35`).
     *
     * Das ist keine Umgehung des Fehlschlags, sondern der Aufbau, den der Test
     * meint: **ein Abonnement, das PostgreSQL benutzen kann.** Ohne Präfix gäbe
     * es keine Rolle, die sich entsperren liesse.
     */
    private function claim(Subscription $subscription): void
    {
        SystemUser::query()->create([
            'number' => (int) ltrim((string) $subscription->system_user, 'p'),
            'subscription' => $subscription->name,
            'db_prefix' => Names::newPrefix(),
            'claimed_at' => now(),
        ]);
    }

    private function finish(Subscription $subscription, string $task): void
    {
        $operation = $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => null,
            'type' => $task,
            'task' => $task,
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]));

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($operation);
        $this->tenancy()->allowAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(string $task): array
    {
        $operation = Operation::query()->where('task', $task)->latest('id')->first();

        if ($operation === null) {
            $this->fail(sprintf(
                'Es wurde kein %s eingereiht — dieser Teil des Abonnements bleibt, wie er war.',
                $task,
            ));
        }

        return (array) ($operation->payload ?? []);
    }

    /**
     * Die Freigabe erreicht Website, MariaDB-Zugang und PostgreSQL-Rolle.
     *
     * **Alle drei in einem Test, und das ist Absicht.** Drei getrennte Tests
     * beantworten dreimal dieselbe Frage und lassen offen, ob *zusammen* alles
     * herauskommt — und genau dort sass der Fehler: Die Zugänge kamen zurück,
     * die Website nicht.
     */
    public function test_resuming_reaches_everything_below_the_subscription(): void
    {
        $this->finish($this->suspended(), 'subscription.resume');

        $this->assertFalse(
            $this->payloadOf('web.site.apply')['suspended'] ?? null,
            'Der Server-Block liefert nicht wieder aus — die Domain bleibt gesperrt, '
            .'obwohl das Abonnement frei ist.',
        );

        $this->assertSame('unlock', $this->payloadOf('db.user.lock')['mode'] ?? null);
        $this->assertSame('unlock', $this->payloadOf('pg.role.lock')['mode'] ?? null);
    }

    /**
     * Und das Sperren erreicht dieselben drei.
     *
     * **Die Untergrenze.** Ohne sie liesse sich der Test darüber erfüllen, indem
     * gar nichts mehr sperrt — und ein gesperrtes Abonnement, dessen Website
     * weiter ausliefert, ist der teurere Fehler von beiden.
     */
    public function test_suspending_reaches_the_same_three(): void
    {
        $subscription = $this->tenancy()->withoutRestriction(function (): Subscription {
            $subscription = Subscription::factory()->create([
                'name' => 'unterbau.de',
                'system_user' => 'p1078',
            ]);

            $this->claim($subscription);

            Domain::factory()->for($subscription)->create(['status' => DomainStatus::Active]);
            DbUser::factory()->forSubscription($subscription, 'web')->create();
            DbUser::factory()->postgres()->forSubscription($subscription, 'cron')->create();

            return $subscription;
        });

        $this->finish($subscription, 'subscription.suspend');

        $this->assertTrue($this->payloadOf('web.site.apply')['suspended'] ?? null);
        $this->assertSame('lock', $this->payloadOf('db.user.lock')['mode'] ?? null);
        $this->assertSame('lock', $this->payloadOf('pg.role.lock')['mode'] ?? null);
    }
}
