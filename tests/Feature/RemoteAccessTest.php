<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Ops\DbRemoteAccess;
use Tests\TestCase;

/**
 * Der Fernzugriff — der einzige Schalter von P5, nach dem ein Dienst von aussen
 * erreichbar ist.
 *
 * **Drei Dinge werden hier geprüft, und jedes hat einen eigenen Anlass.**
 *
 * 1. **Ein Zugang für eine fremde Adresse entsteht nicht, solange der Server
 *    nur lokal horcht.** Das Feld dafür wird in der Oberfläche gar nicht erst
 *    gezeigt — aber ein Formular ist keine Sperre. Was ein Kunde schicken kann,
 *    prüft der Steuerungscode.
 * 2. **Was der Agent nach `/etc` schreibt, nimmt das Paket beim `purge`
 *    mit.** Sonst hinterlässt ein entferntes Panel einen Datenbankserver, der
 *    auf einer erreichbaren Adresse horcht — die Lage aus `docs/35`, nur mit
 *    einem offenen Port statt eines privaten Schlüssels. Der Dateiname steht
 *    an zwei Stellen, und genau deshalb prüft dieser Test, dass es dieselbe
 *    ist.
 * 3. **Der Neustart passiert nicht ungefragt.** Der Datenbankserver trägt auch
 *    das Panel.
 */
final class RemoteAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein Kunde mit einem Abonnement und einer Datenbank.
     *
     * @return array{0: Account, 1: Database}
     */
    private function customerWithDatabase(): array
    {
        [$subscription, $database] = app(Tenancy::class)->withoutRestriction(function (): array {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

            return [$subscription, Database::factory()->forSubscription($subscription, 'shop')->create()];
        });

        $customer = Customer::query()->findOrFail($subscription->customer_id);

        return [Account::factory()->customer($customer)->create(), $database];
    }

    /**
     * Solange der Server nur lokal horcht, entsteht kein Zugang für eine fremde
     * Adresse.
     *
     * **Geprüft wird der Wortlaut und nicht nur, dass es rot ist.** In diesem
     * Container läuft kein Agent; ohne die Sperre käme ebenfalls ein Fehler am
     * Feld an, nur eben „Verbindung zum Agenten fehlgeschlagen". Beides ist
     * rot, und nur eines davon ist der Beleg — dieselbe Begründung wie in
     * {@see DatabaseFormTest}.
     */
    public function test_a_foreign_host_is_refused_while_the_server_listens_locally(): void
    {
        [$account, $database] = $this->customerWithDatabase();

        $response = $this->actingAs($account)
            ->from("/databases/{$database->id}")
            ->post("/databases/{$database->id}/users", ['label' => 'web', 'host' => '203.0.113.5']);

        $response->assertSessionHasErrors('host');

        $this->assertStringContainsString(
            'nur lokal erreichbar',
            (string) session('errors')?->first('host'),
            'Die Abweisung stammt nicht aus der Sperre — dann belegt dieser Test nichts.',
        );
    }

    /**
     * Und was das Paket beim `purge` wegräumt, ist die Datei, die der Agent
     * schreibt.
     *
     * **Der Dateiname steht an zwei Stellen** — in `DbRemoteAccess` und im
     * Entfernungsskript, das kein PHP lesen kann. Das ist wortwörtlich das
     * Muster, an dem dieses Projekt sechsmal hängengeblieben ist: eine
     * Zeichenkette, die auf etwas verweist, ohne dass etwas den Bezug prüft.
     * Hier ist der Bezug geprüft, und der Name kommt aus der Konstanten und
     * nicht aus diesem Test.
     */
    public function test_the_purge_takes_the_include_file_along(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/scripts/postremove.sh');

        $this->assertStringContainsString(DbRemoteAccess::FILE, $script, sprintf(
            'packaging/scripts/postremove.sh nennt %s nicht — ein entferntes Panel liesse den '
            .'Datenbankserver auf einer erreichbaren Adresse horchen.',
            DbRemoteAccess::FILE,
        ));

        // Nur beim `purge`: Ein `apt remove` soll das Paket loswerden und nicht
        // die Horchadresse eines Dienstes ändern, den es nicht mehr verwaltet.
        $this->assertStringContainsString('purge', $script);

        // Und das Skript wird auch eingebunden. Ohne diese Zeile läge es im
        // Repo und liefe nie — die zweite Hälfte desselben Fehlers.
        $this->assertStringContainsString(
            'postremove: ./packaging/scripts/postremove.sh',
            (string) file_get_contents(dirname(__DIR__, 2).'/packaging/nfpm.yaml'),
            'nfpm.yaml bindet postremove.sh nicht ein — dann liegt das Skript im Paket und läuft nie.',
        );
    }

    /**
     * Die geschriebene Datei sagt, worauf gehorcht wird und wem sie gehört.
     *
     * Der Inhalt ist eine reine Funktion, damit er ohne Datenbankserver zu
     * lesen ist — dieselbe Entscheidung wie bei `DbUserGrant::statement()`.
     */
    public function test_the_written_file_names_the_address_and_its_owner(): void
    {
        $text = DbRemoteAccess::content('0.0.0.0');

        $this->assertStringContainsString('[mysqld]', $text);
        $this->assertStringContainsString('bind-address = 0.0.0.0', $text);

        // Wer die Datei findet, soll wissen, dass sie beim nächsten Lauf
        // überschrieben wird — sonst editiert sie jemand und wundert sich.
        $this->assertStringContainsString('srvpanel', $text);

        $this->assertStringContainsString('bind-address = ::', DbRemoteAccess::content('::'));
    }

    /**
     * Ohne Rückfrage passiert nichts.
     *
     * **Vorgabe `false`, wie bei `--prune`.** Der Datenbankserver trägt auch
     * das Panel; ein `--remote` unter `--no-interaction` wäre eine
     * Unterbrechung, die niemand angesagt hat. Der Lauf endet erfolgreich und
     * hat nichts getan — der Agent wird gar nicht erst gerufen, was in diesem
     * Container ohnehin scheitern würde.
     */
    public function test_nothing_happens_without_a_confirmation(): void
    {
        $this->artisan('srvpanel:db --remote=off --no-interaction')
            ->expectsOutputToContain('Abgebrochen.')
            ->assertSuccessful();
    }

    /**
     * Und die Adresse kommt aus einer Positivliste.
     *
     * Sie landet in einer Konfigurationsdatei; ein freier Wert wäre genau das,
     * wovor die Positivliste des Agenten schützt. Abgewiesen wird hier schon
     * im Kommando, damit die Meldung lesbar ist — der Agent weist denselben
     * Wert noch einmal ab, und das ist kein Widerspruch: Das Kommando spart die
     * Runde, der Agent hält die Regel.
     */
    public function test_an_address_outside_the_list_is_refused(): void
    {
        $this->artisan('srvpanel:db --remote=on --bind=203.0.113.5 --no-interaction')
            ->expectsOutputToContain('--bind erwartet')
            ->assertFailed();
    }
}
