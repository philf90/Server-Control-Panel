<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\Lifecycles;
use App\Support\Settings\Settings;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\PhpSelection;
use App\Support\Web\WebLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Was **nach** dem Agenten passiert — und was passiert, wenn er nicht antwortet.
 *
 * Die zweite Grenze aus CLAUDE.md: Der Zustand folgt dem System und nicht dem
 * Klick. Diese Tests laufen deshalb so, wie der Arbeiter läuft — ohne
 * angemeldetes Konto, also im Grundzustand der Mandantenklammer, in dem nichts
 * sichtbar ist. Was hier grün ist, funktioniert auch in der Warteschlange.
 */
final class WebLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    private function domain(): Domain
    {
        return $this->tenancy()->withoutRestriction(function (): Domain {
            $subscription = Subscription::factory()->create([
                'name' => 'beispiel.de',
                'system_user' => 'p1001',
            ]);

            return Domain::factory()->for($subscription)->create([
                'name' => 'zweite.de',
                'status' => DomainStatus::Provisioning,
            ]);
        });
    }

    /** @param array<string, mixed> $result */
    private function finished(Domain $domain, string $task, array $result = []): Operation
    {
        return $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'subject_type' => 'domain',
            'subject_id' => $domain->id,
            'type' => $task,
            'task' => $task,
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
            'result' => $result,
        ]));
    }

    public function test_apply_makes_the_domain_active(): void
    {
        $domain = $this->domain();

        // Der Grundzustand der Klammer — genau wie im Arbeiter.
        $this->tenancy()->reset();

        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.apply', ['suspended' => false]));

        $this->tenancy()->allowAll();

        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
    }

    /**
     * Der Agent sagt, ob er einen sperrenden Block geschrieben hat.
     *
     * Die Antwort wird übernommen und nicht noch einmal aus dem Abonnement
     * abgeleitet — sonst gäbe es zwei Antworten auf dieselbe Frage.
     */
    public function test_apply_takes_the_suspension_from_the_answer(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.apply', ['suspended' => true]));
        $this->tenancy()->allowAll();

        $this->assertSame(DomainStatus::Suspended, $domain->refresh()->status);
    }

    public function test_remove_frees_the_row_and_the_name(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.remove'));
        $this->tenancy()->allowAll();

        $this->assertNull(Domain::query()->find($domain->id));

        // Und der Name ist wieder zu haben — auch für ein anderes Abonnement.
        $other = Subscription::factory()->create();
        $again = Domain::factory()->for($other)->create(['name' => 'zweite.de']);

        $this->assertSame('zweite.de', $again->name);
        $this->assertNotSame($domain->subscription_id, $again->subscription_id);
    }

    /**
     * **Die Lücke, die beim Nachfragen aufgefallen ist.**
     *
     * Ein zurückgebautes Abonnement wird weich gelöscht — sein Systembenutzer
     * muss verbraucht bleiben. Der Fremdschlüssel der Domains hat
     * `cascadeOnDelete`, und das greift dabei nicht: Die Zeilen blieben stehen
     * und hielten ihre Namen belegt, auf einem Server, auf dem von ihnen
     * nichts mehr liegt.
     */
    public function test_the_teardown_of_a_subscription_frees_its_domain_names(): void
    {
        $domain = $this->domain();

        $subscription = $this->tenancy()->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($domain->subscription_id)
        );

        $this->assertNotNull($subscription);

        $operation = $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.remove',
            'task' => 'subscription.remove',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]));

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($operation);
        $this->tenancy()->allowAll();

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
        $this->assertNotNull($subscription->deleted_at);

        // Keine verwaiste Domainzeile …
        $this->assertSame(0, Domain::query()->where('subscription_id', $subscription->id)->count());

        // … und der Name lässt sich neu vergeben.
        $neu = Subscription::factory()->create();

        $this->assertNotNull(Domain::factory()->for($neu)->create(['name' => 'zweite.de']));
    }

    /** Die Argumente entstehen aus der Zeile — samt Aliassen und Sperre. */
    public function test_the_payload_comes_from_the_row(): void
    {
        $domain = $this->tenancy()->withoutRestriction(function (): Domain {
            $subscription = Subscription::factory()->create(['name' => 'beispiel.de', 'system_user' => 'p1001']);

            $main = Domain::factory()->main()->for($subscription)->create(['name' => 'beispiel.de']);

            Domain::factory()->alias($main)->create(['name' => 'www.beispiel.de']);
            Domain::factory()->alias($main)->create(['name' => 'beispiel.at']);

            return $main;
        });

        $payload = app(WebLifecycle::class)->payload($domain);

        $this->assertSame('beispiel.de', $payload['subscription']);
        $this->assertSame('p1001', $payload['user']);
        $this->assertSame(['beispiel.at', 'www.beispiel.de'], $payload['aliases']);
        $this->assertFalse($payload['suspended']);

        // Ohne zugeordnetes Zertifikat wird auch keines genannt — und der
        // Agent liefert dann keines aus, auch wenn unter dem Namen der Domain
        // eines läge (`docs/34 §2.1`).
        $this->assertNull($payload['certificate']);
    }

    /**
     * Welches Zertifikat ausgeliefert wird, sagt das Panel — mit seinem Namen.
     *
     * **Bis zum zweiten Wurf von P4 sah der Agent unter dem Namen der Domain
     * nach.** Damit entschied das Dateisystem darüber, was nginx vorweist, und
     * die Zuordnung in der Datenbank war die zweite Wahrheit daneben. Genannt
     * wird der Schlüssel im Ablageort, kein Pfad: Ein Pfad aus der Anwendung
     * wäre bei `ssl_certificate` dasselbe wie bei `root`.
     */
    public function test_the_payload_names_the_assigned_certificate(): void
    {
        $domain = $this->tenancy()->withoutRestriction(function (): Domain {
            $subscription = Subscription::factory()->create(['name' => 'beispiel.de', 'system_user' => 'p1001']);
            $main = Domain::factory()->main()->for($subscription)->create(['name' => 'beispiel.de']);

            $certificate = Certificate::factory()->covering(['*.beispiel.de', 'beispiel.de'])->create([
                'subscription_id' => $subscription->id,
                'storage_name' => '_wildcard.beispiel.de',
            ]);

            $main->certificate_id = (int) $certificate->id;
            $main->save();

            return $main;
        });

        $payload = app(WebLifecycle::class)->payload($domain);

        $this->assertSame('_wildcard.beispiel.de', $payload['certificate']);
    }

    /**
     * Die Sperre des Abonnements schlägt auf jede seiner Domains durch.
     *
     * Ohne diese Zeile lieferte eine erneut angewandte Domain eines gesperrten
     * Abonnements wieder aus — die Sperre stünde am Abonnement und wäre
     * trotzdem weg.
     */
    public function test_a_suspended_subscription_suspends_its_sites(): void
    {
        $domain = $this->tenancy()->withoutRestriction(function (): Domain {
            $subscription = Subscription::factory()->suspended()->create([
                'name' => 'beispiel.de',
                'system_user' => 'p1001',
            ]);

            return Domain::factory()->for($subscription)->create(['status' => DomainStatus::Active]);
        });

        $this->assertTrue(app(WebLifecycle::class)->payload($domain)['suspended']);
    }

    /** Nach einer Installation weiß das Panel, was auf dem Server liegt. */
    public function test_the_installed_versions_are_remembered(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();

        app(Lifecycles::class)->afterSuccess(
            $this->finished($domain, 'php.version.install', ['available' => ['8.3', '8.4', '9.9']]),
        );

        $this->tenancy()->allowAll();

        // `9.9` steht nicht im Katalog und wird verworfen — ein alter Agent
        // neben einem neuen Panel ist genau die Lage, in der so etwas ankommt.
        $this->assertSame(['8.3', '8.4'], app(PhpSelection::class)->installed());
        $this->assertNotNull(app(Settings::class)->phpVersionsCheckedAt());
    }

    /**
     * Das Sperren eines Abonnements schaltet seine Websites ab.
     *
     * Bis P3 setzte `subscription.suspend` nur die Rechte des Verzeichnisses;
     * ein Besucher sah einen nackten „403 Forbidden". Jetzt wird jeder
     * Server-Block neu geschrieben — und der Wert, der darüber entscheidet,
     * kommt aus dem Zustand, den der Lebenslauf des Abonnements **vorher**
     * gesetzt hat.
     */
    public function test_suspending_a_subscription_reapplies_its_sites(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['status' => DomainStatus::Active])->save();

        $operation = $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'account_id' => null,
            'type' => 'subscription.suspend',
            'task' => 'subscription.suspend',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]));

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($operation);
        $this->tenancy()->allowAll();

        $follow = Operation::query()->where('task', 'web.site.apply')->latest('id')->firstOrFail();

        $this->assertSame((int) $domain->id, $follow->subject_id);
        $this->assertTrue($follow->payload['suspended'] ?? null, 'Der Server-Block muss sperren.');
    }

    /**
     * Und die Reihenfolge, an der das hängt.
     *
     * Liefe der Lebenslauf der Websites zuerst, trüge der Server-Block noch
     * den Zustand von vorher: Die Sperre stünde im Panel und die Website
     * antwortete weiter. Der Test steht neben dem darüber, weil er dessen
     * Voraussetzung ist.
     */
    public function test_the_subscription_lifecycle_runs_first(): void
    {
        $order = Lifecycles::HANDLERS;

        $this->assertLessThan(
            array_search(WebLifecycle::class, $order, true),
            array_search(Lifecycle::class, $order, true),
        );
    }

    /**
     * Die Hauptdomain entsteht mit dem Abonnement — und erst danach.
     *
     * Vorher gäbe es kein `httpdocs`, in das der Server-Block zeigen könnte.
     * Der Name kommt aus dem Abonnement und nicht aus einem zweiten
     * Eingabefeld: Zwei Felder wären zwei Gelegenheiten, zwei verschiedene
     * Namen einzutragen.
     */
    public function test_provisioning_creates_and_applies_the_main_domain(): void
    {
        $subscription = $this->tenancy()->withoutRestriction(fn (): Subscription => Subscription::factory()->create([
            'name' => 'neukunde.de',
            'system_user' => 'p1005',
            'status' => SubscriptionStatus::Provisioning,
        ]));

        $operation = $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.provision',
            'task' => 'subscription.provision',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]));

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($operation);
        app(Lifecycles::class)->afterSuccess($operation);
        $this->tenancy()->allowAll();

        $domains = Domain::query()->where('subscription_id', $subscription->id)->get();

        // Zweimal aufgerufen und trotzdem eine: der Lauf ist wiederholbar.
        $this->assertCount(1, $domains);
        $this->assertSame('neukunde.de', $domains[0]->name);
        $this->assertSame(DomainType::Main, $domains[0]->type);

        // Und die Abschrift am Abonnement steht.
        $this->assertSame('neukunde.de', $subscription->refresh()->main_domain);
    }

    /** Ein Folgevorgang trägt das Konto dessen, der ihn ausgelöst hat. */
    public function test_a_follow_up_carries_the_account(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['status' => DomainStatus::Active])->save();

        $account = Account::factory()->admin()->create();

        $operation = $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'account_id' => $account->id,
            'type' => 'subscription.suspend',
            'task' => 'subscription.suspend',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]));

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($operation);
        $this->tenancy()->allowAll();

        $follow = Operation::query()->where('task', 'web.site.apply')->latest('id')->firstOrFail();

        // Ohne diese Zeile stünde in der Liste „—" neben einer Sperre, die
        // jemand angeordnet hat.
        $this->assertSame((int) $account->id, $follow->account_id);
    }

    /**
     * Ein Pool, den keine Domain mehr benutzt, wird abgeräumt.
     *
     * Bliebe er stehen, liesse sich die PHP-Version nie wieder entfernen:
     * `php.version.remove` weist ab, solange ein Abonnement einen Pool hat.
     * Der Betreiber suchte dann nach einem Abonnement, das es nicht mehr gibt.
     */
    public function test_removing_the_last_domain_of_a_version_removes_its_pool(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['php_version' => '8.3'])->save();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.remove'));
        $this->tenancy()->allowAll();

        $pool = Operation::query()->where('task', 'php.pool.remove')->sole();

        $this->assertSame('8.3', $pool->payload['php_version'] ?? null);
        $this->assertSame((int) $domain->subscription_id, $pool->subscription_id);
    }

    /** Solange eine zweite Domain dieselbe Version benutzt, bleibt der Pool. */
    public function test_a_pool_stays_while_another_domain_uses_it(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['php_version' => '8.3'])->save();

        $this->tenancy()->withoutRestriction(function () use ($domain): void {
            Domain::factory()->create([
                'subscription_id' => $domain->subscription_id,
                'name' => 'dritte.de',
                'php_version' => '8.3',
            ]);
        });

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.remove'));
        $this->tenancy()->allowAll();

        $this->assertSame(0, Operation::query()->where('task', 'php.pool.remove')->count());
    }

    /**
     * Die Protokollrotation entsteht mit dem Abonnement.
     *
     * Sie ist je Abonnement und deckt über den Ausdruck jede Domain ab, auch
     * die von morgen. Ohne sie füllt das Zugriffsprotokoll die Quota des
     * Kunden mit Dateien, die er nie angelegt hat.
     */
    public function test_provisioning_sets_up_the_log_rotation(): void
    {
        $subscription = $this->tenancy()->withoutRestriction(fn (): Subscription => Subscription::factory()->create([
            'name' => 'rotation.de',
            'system_user' => 'p1009',
            'status' => SubscriptionStatus::Provisioning,
        ]));

        $operation = $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.provision',
            'task' => 'subscription.provision',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]));

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($operation);
        $this->tenancy()->allowAll();

        $rotation = Operation::query()->where('task', 'web.logrotate.apply')->sole();

        $this->assertSame('rotation.de', $rotation->payload['subscription'] ?? null);
        $this->assertSame('p1009', $rotation->payload['user'] ?? null);
    }

    /** Ein Vorgang gehört genau einem Lebenslauf. */
    public function test_a_foreign_task_is_left_alone(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'agent.ping'));
        $this->tenancy()->allowAll();

        $this->assertSame(DomainStatus::Provisioning, $domain->refresh()->status);
    }

    /**
     * Beide Lebensläufe hängen am Arbeiter.
     *
     * Der Test steht hier und nicht in `LifecycleReachTest`, weil er die
     * Wirkung prüft und nicht die Liste: Ein Vorgang für ein Abonnement und
     * einer für eine Domain, beide durch denselben Aufruf.
     */
    public function test_both_lifecycles_run(): void
    {
        $this->assertContains(Lifecycle::class, Lifecycles::HANDLERS);
        $this->assertContains(WebLifecycle::class, Lifecycles::HANDLERS);

        $domain = $this->tenancy()->withoutRestriction(function (): Domain {
            $subscription = Subscription::factory()->create([
                'status' => SubscriptionStatus::Provisioning,
                'name' => 'zwei.de',
                'system_user' => 'p1002',
            ]);

            return Domain::factory()->for($subscription)->create([
                'type' => DomainType::Addon,
                'status' => DomainStatus::Provisioning,
            ]);
        });

        $this->tenancy()->reset();

        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.apply'));

        app(Lifecycles::class)->afterSuccess(
            $this->tenancy()->withoutRestriction(fn (): Operation => Operation::query()->create([
                'subscription_id' => $domain->subscription_id,
                'type' => 'subscription.provision',
                'task' => 'subscription.provision',
                'status' => OperationStatus::Succeeded,
                'progress' => 100,
            ]))
        );

        $this->tenancy()->allowAll();

        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
        $this->assertSame(
            SubscriptionStatus::Active,
            Subscription::query()->find($domain->subscription_id)?->status,
        );
    }
}
