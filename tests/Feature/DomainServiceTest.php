<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\Domains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Die Schranke aus §6.2.3: Kontingent- und Planprüfung **im Dienst**.
 *
 * Jeder Test hier stellt dieselbe Frage aus der Sicht von jemandem, der das
 * Formular umgeht: Was passiert, wenn die Werte nicht aus der Oberfläche
 * kommen, sondern direkt? Die Antwort muss dieselbe sein — sonst ist die
 * Prüfung eine Anzeige und keine Schranke.
 */
final class DomainServiceTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        app(Tenancy::class)->allowAll();

        // Kein Vorgang läuft wirklich an: Der Agent fehlt in diesem Container.
        Queue::fake();

        // Was auf dem Server liegt, steht im Zwischenspeicher — ohne ihn ist
        // keine Version wählbar, und das ist die richtige Vorgabe.
        app(Settings::class)->savePhpVersions(['8.2', '8.3', '8.4']);

        $this->subscription = Subscription::factory()->create([
            'name' => 'beispiel.de',
            'system_user' => 'p1001',
        ]);
    }

    private function service(): Domains
    {
        return app(Domains::class);
    }

    /** @param array<string, mixed> $data */
    private function create(array $data = []): Domain
    {
        return $this->service()->create($this->subscription, array_merge([
            'type' => DomainType::Addon->value,
            'name' => 'zweite.de',
        ], $data));
    }

    public function test_a_new_domain_waits_for_the_agent(): void
    {
        $domain = $this->create();

        // Der Zustand folgt dem Agenten: „aktiv" setzt erst der Lebenslauf.
        $this->assertSame(DomainStatus::Provisioning, $domain->status);
        $this->assertSame('zweite.de', $domain->name);
        $this->assertSame('zweite.de', $domain->document_root);
        $this->assertSame('8.4', $domain->php_version, 'Die Vorgabe ist die neueste wählbare Version.');

        // Zwei Vorgänge: erst der Pool, dann der Server-Block. Andersherum
        // weist der Agent den Server-Block zurück.
        $tasks = Operation::query()->orderBy('id')->pluck('task')->all();

        $this->assertSame(['php.pool.apply', 'web.site.apply'], $tasks);
    }

    public function test_the_operation_says_what_it_is_about(): void
    {
        $domain = $this->create();

        $operation = Operation::query()->where('task', 'web.site.apply')->firstOrFail();

        $this->assertSame('domain', $operation->subject_type);
        $this->assertSame((int) $domain->id, $operation->subject_id);

        // Und die Argumente kommen aus der Zeile, nicht aus der Anfrage.
        $this->assertSame('beispiel.de', $operation->payload['subscription'] ?? null);
        $this->assertSame('p1001', $operation->payload['user'] ?? null);
        $this->assertSame('zweite.de', $operation->payload['domain'] ?? null);
    }

    public function test_the_quota_counts_main_and_addon_together(): void
    {
        $this->subscription->plan->update(['quotas' => ['domains' => 2] + $this->subscription->plan->quotas]);

        Domain::factory()->main()->for($this->subscription)->create(['name' => 'beispiel.de']);
        $this->create(['name' => 'zwei.de']);

        // Zwei sind vergeben — die dritte nicht mehr.
        $this->expectException(ValidationException::class);

        $this->create(['name' => 'drei.de']);
    }

    /**
     * Aliasse zählen auf kein Kontingent.
     *
     * {@see Quota::hint()} verspricht das dem Betreiber im Formular. Hier
     * steht die Einlösung: Bei erschöpftem Domainkontingent geht der Alias
     * trotzdem durch.
     */
    public function test_an_alias_costs_no_quota(): void
    {
        $this->subscription->plan->update(['quotas' => ['domains' => 1] + $this->subscription->plan->quotas]);

        $main = Domain::factory()->main()->for($this->subscription)->create(['name' => 'beispiel.de']);

        $alias = $this->create([
            'type' => DomainType::Alias->value,
            'name' => 'beispiel.at',
            'parent_domain_id' => $main->id,
        ]);

        $this->assertSame(DomainType::Alias, $alias->type);
        $this->assertNull($alias->document_root, 'Ein Alias liefert aus dem Verzeichnis seiner Elterndomain aus.');
        $this->assertNull($alias->php_version);
    }

    public function test_subdomains_have_their_own_quota(): void
    {
        $this->subscription->plan->update([
            'quotas' => ['domains' => 5, 'subdomains' => 1] + $this->subscription->plan->quotas,
        ]);

        $main = Domain::factory()->main()->for($this->subscription)->create(['name' => 'beispiel.de']);

        $this->create(['type' => DomainType::Subdomain->value, 'name' => 'shop.beispiel.de', 'parent_domain_id' => $main->id]);

        $this->expectException(ValidationException::class);

        $this->create(['type' => DomainType::Subdomain->value, 'name' => 'blog.beispiel.de', 'parent_domain_id' => $main->id]);
    }

    /**
     * Der Angriff über den Namen einer fremden Domain.
     *
     * Die Eindeutigkeit gilt serverweit. Ein Kunde sieht die fremde Domain
     * nicht — geprüft wird trotzdem gegen alle, sonst liefe er in einen
     * Datenbankfehler statt in eine Meldung.
     */
    public function test_a_domain_of_another_subscription_cannot_be_taken_over(): void
    {
        $foreign = Subscription::factory()->create();
        Domain::factory()->for($foreign)->create(['name' => 'fremd.de']);

        $this->expectException(ValidationException::class);

        $this->create(['name' => 'fremd.de']);
    }

    /**
     * Und derselbe Angriff aus der Sicht eines Kunden.
     *
     * **Der Test davor lief als Admin und war damit blind für den Fall, der
     * zählt.** Mit offener Klammer sieht die Abfrage die fremde Domain
     * ohnehin; die ausdrückliche Ausnahme in `assertNameIsFree()` wäre
     * entbehrlich gewesen, und ihr Entfernen blieb in der Gegenprobe grün.
     * Hier ist die Klammer zu — genau wie bei einem angemeldeten Kunden —,
     * und ohne die Ausnahme ginge der fremde Name durch: bis in die
     * Datenbank, wo der eindeutige Index ihn mit einer Meldung abfängt, die
     * niemandem hilft.
     */
    public function test_a_customer_cannot_take_over_a_domain_it_cannot_see(): void
    {
        $foreign = Subscription::factory()->create();
        Domain::factory()->for($foreign)->create(['name' => 'fremd.de']);

        // Die Sicht eines Kunden: nur das eigene Abonnement.
        app(Tenancy::class)->restrictTo([(int) $this->subscription->id]);

        try {
            $this->create(['name' => 'fremd.de']);
            $this->fail('Der fremde Name hätte abgewiesen werden müssen.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('name', $error->errors());
        } finally {
            app(Tenancy::class)->allowAll();
        }
    }

    public function test_a_subdomain_must_lie_below_its_parent(): void
    {
        $main = Domain::factory()->main()->for($this->subscription)->create(['name' => 'beispiel.de']);

        // `boesebeispiel.de` endet auf `beispiel.de` und ist trotzdem eine
        // fremde Domain — der Vergleich läuft über die Bestandteile.
        $this->expectException(ValidationException::class);

        $this->create([
            'type' => DomainType::Subdomain->value,
            'name' => 'boesebeispiel.de',
            'parent_domain_id' => $main->id,
        ]);
    }

    public function test_a_parent_of_another_subscription_is_not_found(): void
    {
        $foreign = Subscription::factory()->create();
        $foreignDomain = Domain::factory()->main()->for($foreign)->create(['name' => 'fremd.de']);

        $this->expectException(ValidationException::class);

        $this->create([
            'type' => DomainType::Subdomain->value,
            'name' => 'shop.fremd.de',
            'parent_domain_id' => $foreignDomain->id,
        ]);
    }

    public function test_a_php_version_outside_the_plan_is_refused(): void
    {
        $this->subscription->plan->update([
            'quotas' => ['php_versions' => ['8.3']] + $this->subscription->plan->quotas,
        ]);

        $this->expectException(ValidationException::class);

        $this->create(['php_version' => '8.4']);
    }

    public function test_a_php_version_that_is_not_installed_is_refused(): void
    {
        // Der Plan gibt 8.1 her, der Server hat sie nicht.
        $this->subscription->plan->update([
            'quotas' => ['php_versions' => ['8.1', '8.4']] + $this->subscription->plan->quotas,
        ]);

        $this->expectException(ValidationException::class);

        $this->create(['php_version' => '8.1']);
    }

    public function test_a_document_root_cannot_leave_the_subscription(): void
    {
        foreach (['../../etc', '/etc/nginx', 'logs', '.ssh'] as $root) {
            try {
                $this->create(['name' => 'test-'.md5($root).'.de', 'document_root' => $root]);
                $this->fail(sprintf('%s hätte abgewiesen werden müssen.', $root));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_php_settings_stay_below_the_plan_cap(): void
    {
        $this->subscription->plan->update([
            'quotas' => ['php_memory_mb' => 128] + $this->subscription->plan->quotas,
            'features' => [Feature::PhpSettings->value => true] + $this->subscription->plan->features,
        ]);

        $domain = $this->create(['name' => 'eins.de', 'php_settings' => ['memory_limit' => '128M']]);

        $this->assertSame(['memory_limit' => '128M'], $domain->php_settings);

        $this->expectException(ValidationException::class);

        $this->create(['name' => 'zwei.de', 'php_settings' => ['memory_limit' => '256M']]);
    }

    public function test_php_settings_need_the_feature(): void
    {
        $this->subscription->plan->update([
            'features' => [Feature::PhpSettings->value => false] + $this->subscription->plan->features,
        ]);

        $this->expectException(ValidationException::class);

        $this->create(['php_settings' => ['memory_limit' => '64M']]);
    }

    /** Die Abschottung lässt sich nicht über die Domaineinstellungen aufmachen. */
    public function test_no_domain_setting_reaches_the_isolation(): void
    {
        $this->subscription->plan->update([
            'features' => [Feature::PhpSettings->value => true] + $this->subscription->plan->features,
        ]);

        foreach (['open_basedir' => '/', 'disable_functions' => '', 'session.save_path' => '/tmp'] as $key => $value) {
            try {
                $this->create(['name' => 'x-'.md5($key).'.de', 'php_settings' => [$key => $value]]);
                $this->fail(sprintf('%s hätte abgewiesen werden müssen.', $key));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_own_directives_go_through_the_allowlist(): void
    {
        $domain = $this->create(['nginx_directives' => ['autoindex off;']]);

        $this->assertSame(['autoindex off;'], $domain->nginx_directives);

        $this->expectException(ValidationException::class);

        $this->create(['name' => 'zwei.de', 'nginx_directives' => ['root /etc;']]);
    }

    public function test_a_suspended_subscription_takes_no_new_domain(): void
    {
        $suspended = Subscription::factory()->suspended()->create();

        $this->expectException(ValidationException::class);

        $this->service()->create($suspended, ['type' => DomainType::Addon->value, 'name' => 'neu.de']);
    }

    public function test_the_main_domain_cannot_be_removed_on_its_own(): void
    {
        $main = Domain::factory()->main()->for($this->subscription)->create(['name' => 'beispiel.de']);

        $this->expectException(ValidationException::class);

        $this->service()->remove($main);
    }

    public function test_a_domain_with_children_is_not_removed(): void
    {
        $addon = $this->create();
        $addon->forceFill(['status' => DomainStatus::Active])->save();

        Domain::factory()->subdomain($addon)->create();

        $this->expectException(ValidationException::class);

        $this->service()->remove($addon);
    }

    /**
     * Das Verzeichnis geht mit — es sei denn, eine zweite Domain liefert
     * daraus aus.
     */
    public function test_the_directory_only_goes_when_it_belongs_to_this_domain_alone(): void
    {
        $alone = $this->create(['name' => 'allein.de']);
        $alone->forceFill(['status' => DomainStatus::Active])->save();

        $operation = $this->service()->remove($alone);

        $this->assertTrue($operation->payload['remove_document_root'] ?? null);

        $shared = $this->create(['name' => 'geteilt.de', 'document_root' => 'gemeinsam']);
        $shared->forceFill(['status' => DomainStatus::Active])->save();

        $this->create(['name' => 'zweite-geteilt.de', 'document_root' => 'gemeinsam']);

        $second = $this->service()->remove($shared);

        $this->assertFalse($second->payload['remove_document_root'] ?? null);
    }

    public function test_a_pending_domain_is_not_changed(): void
    {
        $domain = $this->create();

        // Sie steht auf „wird angelegt" — ein zweiter Auftrag wäre ein
        // Wettlauf zweier Agent-Läufe um dieselbe Datei.
        $this->expectException(ValidationException::class);

        $this->service()->update($domain, ['document_root' => 'anders']);
    }

    public function test_counts_ignore_nothing_that_is_on_its_way(): void
    {
        Domain::factory()->main()->for($this->subscription)->create(['name' => 'beispiel.de']);
        $this->create(['name' => 'zwei.de']);

        $counts = $this->service()->counts($this->subscription);

        // Die zweite steht auf „wird angelegt" und zählt trotzdem: Sonst
        // kämen zwei gleichzeitige Anlagen beide durch.
        $this->assertSame(2, $counts[Quota::Domains->value]);
        $this->assertSame(0, $counts[Quota::Subdomains->value]);
    }

    public function test_a_plan_without_domains_allows_none(): void
    {
        $plan = Plan::factory()->create(['quotas' => ['domains' => 0] + Plan::factory()->make()->quotas]);
        $subscription = Subscription::factory()->for($plan)->create();

        $this->expectException(ValidationException::class);

        $this->service()->create($subscription, ['type' => DomainType::Addon->value, 'name' => 'keine.de']);
    }
}
