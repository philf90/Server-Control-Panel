<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Databases as DatabasesCommand;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\DbUserNetwork;
use App\Models\Subscription;
use App\Support\Databases\RemoteAccess;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\AgentException;
use Tests\Support\ReadsMethodSource;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Bestand und `pg_hba.conf` laufen nicht auseinander — und wenn doch, sagt es
 * jemand.
 *
 * ## Der Anlass
 *
 * Im Abnahmelauf des Fernzugriffs (`docs/45 §5`) scheiterte ein Netz-Eintrag am
 * Rückweg des Agenten. Die Zeile im Bestand blieb trotzdem stehen: `add()`
 * legte sie an und rief danach `sync()`. Im Panel stand danach „erreichbar von
 * 198.51.100.0/24" für ein Netz, das in `pg_hba.conf` nicht existierte.
 *
 * > **Ein Vorgang, der seinen Bestand vor dem Server ändert, hat zwei
 * > Ergebnisse — und ein Fehlschlag kennt sonst nur eines.**
 *
 * **Und gemeldet hat es niemand.** `srvpanel db` fragte nur nach Zeilen ohne
 * Bestand; für die Gegenrichtung stand dort sogar ein früher Ausstieg, der
 * genau diesen Fall überspringt — leerer Block, also nichts zu sagen.
 *
 * > **Ein Abgleich, der nur eine Richtung kennt, ist eine halbe Frage.**
 *
 * ## Welche Richtung die gefährlichere ist
 *
 * Eine Zeile ohne Bestand lässt jemanden herein, den niemand mehr kennt —
 * schlecht, aber sichtbar. Ein Bestand ohne Zeile sperrt aus, während die
 * Anzeige das Gegenteil verspricht: Wer den Fehler sucht, sucht ihn am Netz, an
 * der Firewall, am Passwort. Nur nicht an einer Zeile, die laut Panel da ist.
 */
final class NetworkDriftTest extends TestCase
{
    use ReadsMethodSource;
    use RefreshDatabase;
    use WithoutPhpComments;

    /** Ein Abonnement mit einer PostgreSQL-Datenbank und einem Zugang daran. */
    private function user(): DbUser
    {
        return app(Tenancy::class)->withoutRestriction(function (): DbUser {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

            /** @var Database $database */
            $database = Database::factory()->postgres()->forSubscription($subscription, 'shop')->create();

            /** @var DbUser $user */
            $user = DbUser::factory()->postgres()->forSubscription($subscription, 'web')->create();

            $user->databases()->attach($database);

            return $user;
        });
    }

    /**
     * **Ein gescheitertes Schreiben hinterlässt keine Zeile.**
     *
     * In diesem Container antwortet kein Agent, `sync()` wirft also — dieselbe
     * Stelle, an der auf `cloudsrv24` der Rückweg zuschlug. Ohne die
     * Transaktion in {@see RemoteAccess::add()} bliebe die Zeile stehen.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): das `DB::transaction`
     * aus `add()` nehmen.
     */
    public function test_a_failed_write_leaves_no_network_behind(): void
    {
        $user = $this->user();

        try {
            app(RemoteAccess::class)->add($user, '203.0.113.5/32');

            $this->fail(
                'Der Agent hat geantwortet — in diesem Container sollte er das nicht. Dann prüft '
                .'dieser Test nichts.',
            );
        } catch (AgentException) {
            // Erwartet: genau hier scheitert es auch auf dem Server.
        }

        $this->assertSame(
            0,
            DbUserNetwork::query()->count(),
            'Das Schreiben ist gescheitert und die Zeile steht trotzdem im Bestand. Im Panel steht '
            .'dann „erreichbar von …" für ein Netz, das in pg_hba.conf nicht existiert.',
        );
    }

    /**
     * Und was der Bestand führt und die Datei nicht hat, ist auffindbar.
     *
     * **Die Untergrenze steht mit im Test:** Eine Zeile, die in beiden steht,
     * darf nicht als fehlend gelten — sonst meldete diese Abfrage jede Zeile und
     * wäre wertlos.
     */
    public function test_what_the_inventory_carries_and_the_file_lacks_is_found(): void
    {
        $user = $this->user();

        app(Tenancy::class)->withoutRestriction(static function () use ($user): void {
            DbUserNetwork::factory()->create(['db_user_id' => $user->id, 'cidr' => '203.0.113.5/32']);
        });

        $remote = app(RemoteAccess::class);

        $fehlend = $remote->missing([]);

        $this->assertCount(1, $fehlend, 'Ein Netz im Bestand ohne Zeile in der Datei fällt nicht auf.');
        $this->assertStringContainsString('203.0.113.5/32', $fehlend[0]);

        // Und steht die Zeile in der Datei, fehlt sie nicht mehr.
        $this->assertSame(
            [],
            $remote->missing($fehlend),
            'Eine Zeile, die in Datei und Bestand steht, gilt als fehlend — dann meldet die Abfrage '
            .'jede Zeile und sagt nichts.',
        );
    }

    /**
     * Und der Abgleich auf der Kommandozeile fragt beide Richtungen.
     *
     * Geprüft im Quelltext, weil die Ausgabe einen antwortenden Agenten
     * bräuchte: `srvpanel db` liest den Block aus `pg.server.info`. Was hier
     * festgehalten wird, ist die **Frage** — dass beide gestellt werden.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): den `missing()`-Aufruf
     * aus `Databases::reportRuleDrift()` entfernen.
     */
    public function test_the_reconciliation_asks_both_directions(): void
    {
        $quelle = $this->withoutComments(
            "<?php\n".(string) $this->methodSource(DatabasesCommand::class, 'reportRuleDrift'),
        );

        foreach (['orphans(', 'missing('] as $frage) {
            $this->assertStringContainsString($frage, $quelle, sprintf(
                'Der Abgleich fragt %s nicht. Ein Abgleich, der nur eine Richtung kennt, ist eine '
                .'halbe Frage.',
                $frage,
            ));
        }

        /*
         * **Und der frühe Ausstieg darf nicht am Block allein hängen.** Genau
         * der hat die gefährliche Richtung übersprungen: leerer Block, also
         * nichts zu melden — und dabei ist „leerer Block, aber Netze im
         * Bestand" der Fall, um den es geht.
         */
        $this->assertStringNotContainsString(
            'if ($managed === []) {',
            $quelle,
            'Der Abgleich steigt bei leerem Block aus, ohne den Bestand gefragt zu haben — dann '
            .'schweigt er genau im gefährlichen Fall.',
        );
    }
}
