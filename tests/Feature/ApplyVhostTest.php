<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\SubscriptionStatus;
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
 *
 * **Die Rotationsdateien gehen seit dem 25. September 2026 bei jedem Lauf
 * mit**, auch ohne `--sites`: Sie bestellen nichts, und ohne diesen Weg
 * erreichte keine Änderung der Vorlage ein Abonnement, das es schon gab
 * (Befund 7 des B2-Laufs, `docs/134`).
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

    /**
     * Jedes lebende Abonnement bekommt seine Rotationsdatei neu — ohne Option.
     *
     * **Gesperrt zählt mit, „wird angelegt" nicht.** Die Domains eines
     * gesperrten Abonnements antworten mit 503, und nginx schreibt das weiter
     * in ihre Protokolle; eines, das angelegt wird, schreibt seine Datei am
     * Ende von `subscription.provision` selbst.
     *
     * Verglichen wird der **Inhalt** des Auftrags und nicht die Zahl der
     * Zeilen: Ohne Benutzer schriebe der Agent `create 0640  adm`, und eine
     * Zählung sähe das nicht.
     */
    public function test_every_live_subscription_gets_its_rotation_written_again(): void
    {
        $this->tenancy()->allowAll();

        Subscription::factory()->create(['name' => 'aktiv.de', 'system_user' => 'p1001']);
        Subscription::factory()->suspended()->create(['name' => 'gesperrt.de', 'system_user' => 'p1002']);
        Subscription::factory()->create([
            'name' => 'neu.de',
            'system_user' => 'p1003',
            'status' => SubscriptionStatus::Provisioning,
        ]);

        $this->tenancy()->reset();

        $this->artisan('srvpanel:vhost')->assertExitCode(1);

        $this->tenancy()->allowAll();

        $auftraege = [];

        foreach (Operation::query()->where('task', 'web.logrotate.apply')->orderBy('id')->get() as $operation) {
            $auftraege[] = [$operation->payload['subscription'] ?? null, $operation->payload['user'] ?? null];
        }

        $this->assertSame([['aktiv.de', 'p1001'], ['gesperrt.de', 'p1002']], $auftraege);

        // Und die Kundenblöcke bleiben trotzdem, wo sie sind — die Rotation
        // hat die Regel darunter nicht aufgeweicht.
        $this->assertSame([], $this->appliedFor());
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
