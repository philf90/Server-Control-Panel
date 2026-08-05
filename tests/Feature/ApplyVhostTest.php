<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `srvpanel vhost --sites` schreibt die Blöcke neu, die es gibt.
 *
 * **Warum es das Kommando gibt:** Die Vorlage lebt im Agenten, die Datei unter
 * `/etc/nginx` ist eine Kopie, und nach einem Update zog sie niemand nach. Für
 * die Oberfläche erledigt das jetzt das postinstall-Skript; für die
 * Kundendomains braucht es einen ausdrücklichen Aufruf, weil jeder neu
 * geschriebene Block für eine Domain ohne Zertifikat eines bestellt.
 *
 * **Ein Alias hat keinen eigenen Block.** Er steht im `server_name` seiner
 * Elterndomain; für ihn etwas anzuwenden hiesse, denselben Block ein zweites
 * Mal zu schreiben — und der Agent suchte für ihn ein DocumentRoot, das es
 * nicht gibt.
 *
 * Der Rückgabewert ist hier 1: Dieser Container hat keinen Agenten, der Block
 * der Oberfläche scheitert also. Genau das prüft der Durchgang mit — wer
 * beides verlangt hat, verliert das zweite nicht wegen des ersten.
 */
final class ApplyVhostTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    /** @return list<string> */
    private function appliedFor(): array
    {
        $this->tenancy()->allowAll();

        $names = [];

        foreach (Operation::query()->where('task', 'web.site.apply')->get() as $operation) {
            $domain = Domain::query()->find($operation->subject_id);

            if ($domain instanceof Domain) {
                $names[] = $domain->name;
            }
        }

        sort($names);

        return $names;
    }

    public function test_every_domain_with_its_own_block_is_written_again(): void
    {
        $this->tenancy()->allowAll();

        $subscription = Subscription::factory()->create(['name' => 'beispiel.de']);

        $main = Domain::factory()->for($subscription)->main()->create(['name' => 'beispiel.de']);
        Domain::factory()->alias($main)->create(['name' => 'www.beispiel.de']);
        $subdomain = Domain::factory()->subdomain($main)->create();

        Domain::factory()->create([
            'name' => 'weg.de',
            'status' => DomainStatus::Removing,
        ]);

        $this->tenancy()->reset();

        $this->artisan('srvpanel:vhost', ['--sites' => true])->assertExitCode(1);

        // Der Alias fehlt, weil er keinen eigenen Block hat — und die Domain
        // im Abbau fehlt, weil ihrer gerade verschwindet.
        $expected = [$main->name, $subdomain->name];
        sort($expected);

        $this->assertSame($expected, $this->appliedFor());
    }

    /** Ohne die Option bleiben die Kundenblöcke unangetastet. */
    public function test_without_the_option_nothing_is_queued_for_the_customers(): void
    {
        $this->tenancy()->allowAll();
        Domain::factory()->create(['name' => 'beispiel.de']);
        $this->tenancy()->reset();

        $this->artisan('srvpanel:vhost')->assertExitCode(1);

        $this->assertSame([], $this->appliedFor());
    }
}
