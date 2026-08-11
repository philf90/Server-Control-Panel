<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Databases as DatabasesCommand;
use App\Http\Controllers\DatabaseSettingsController;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Schema;
use SrvPanel\Agent\Ops\DbRemoteAccess;
use SrvPanel\Agent\Ops\PgRemoteAccess;
use Tests\Support\ReadsMethodSource;
use Tests\Support\WithoutPhpComments;
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
 * 4. **Der Zustand steht im Panel, der Schalter nicht.** Seit `docs/36 §22.3v`
 *    gibt es „Einstellungen → Datenbankserver"; sie liest und schreibt nicht.
 */
final class RemoteAccessTest extends TestCase
{
    use ReadsMethodSource;
    use RefreshDatabase;
    use WithoutPhpComments;

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
     * **Vorgabe `nein`, wie bei `--prune`.** Der Datenbankserver trägt auch das
     * Panel; ein `--remote` ohne Rückfrage wäre eine Unterbrechung, die niemand
     * angesagt hat. Der Lauf endet erfolgreich und hat nichts getan — der Agent
     * wird gar nicht erst gerufen, was in diesem Container ohnehin scheitern
     * würde.
     */
    public function test_nothing_happens_without_a_confirmation(): void
    {
        /*
         * **Die Rückfrage wird beantwortet und nicht übergangen.**
         * `--no-interaction` allein reicht nicht: Der Testläufer legt eine
         * Attrappe über die Ausgabe, und die verlangt für jede Frage eine
         * Erwartung — sonst endet der Lauf mit „no expectations were
         * specified" statt mit dem, was das Kommando tut. Beantwortet wird mit
         * `nein`, denn genau das ist auch die Vorgabe.
         */
        $this->artisan('srvpanel:db --remote=off')
            ->expectsConfirmation(
                'Dafür wird der Datenbankserver neu gestartet. Das Panel ist dabei kurz ohne Datenbank. Weiter?',
                'no',
            )
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

    /**
     * Die Seite zeigt den Zustand, auch wenn der Agent nicht antwortet.
     *
     * **Und das ist hier der Regelfall und kein Sonderfall.** In diesem
     * Container läuft kein Agent; eine Seite, die auf seine Antwort angewiesen
     * wäre, gäbe eine Fehlerseite statt einer Auskunft. Geprüft wird deshalb
     * genau das: `error` ist gesetzt, `remote` ist `false` — und die Seite
     * steht.
     */
    public function test_the_operator_sees_the_state_even_without_an_agent(): void
    {
        $this->actingAs(Account::factory()->admin()->create())
            ->get('/settings/database')
            ->assertOk()
            /*
             * **`etc()` ist hier keine Nachlässigkeit, sondern Pflicht.**
             * `AssertableInertia` prüft beim Aufräumen, dass jede Eigenschaft
             * der obersten Ebene angefasst wurde — ohne diesen Aufruf
             * scheitert der Test an `remote_users`, das er gar nicht meint.
             */
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Database')
                ->where('server.remote', false)
                ->where('commands.on', DatabaseSettingsController::COMMAND_ON)
                ->has('server.error')
                ->etc());
    }

    /** Und ein Kunde sieht sie nicht — sie beschreibt den ganzen Server. */
    public function test_a_customer_does_not_reach_the_page(): void
    {
        [$account] = $this->customerWithDatabase();

        $this->actingAs($account)->get('/settings/database')->assertForbidden();
    }

    /**
     * Gezählt werden die Zugänge auf fremde Adressen — und nur die.
     *
     * **Die Zahl ist der einzige Rechenschritt der Seite**, und sie ist der
     * Grund, warum es sie gibt: Aus ihr folgt die Warnung, dass Zugänge
     * bestehen, die an einem nur lokal horchenden Server nie zustande kommen.
     * Eine Zahl, die auch `localhost` mitzählt, wäre auf jedem Server grösser
     * als null und die Warnung damit dauerhaft an — genau die Sorte Meldung,
     * die man nach zwei Tagen nicht mehr liest.
     */
    public function test_only_foreign_hosts_are_counted(): void
    {
        app(Tenancy::class)->withoutRestriction(static function (): void {
            $subscription = Subscription::factory()->create(['system_user' => 'p1001']);

            // Zwei Wirte sind zwei Benutzer, und derselbe Wirt zweimal sind
            // zwei Zeilen — beides muss die Zahl unten aushalten.
            DbUser::factory()->forSubscription($subscription, 'lokal')->create();
            DbUser::factory()->forSubscription($subscription, 'ferne')->from('203.0.113.5')->create();
            DbUser::factory()->forSubscription($subscription, 'zweite')->from('203.0.113.5')->create();
        });

        $this->actingAs(Account::factory()->admin()->create())
            ->get('/settings/database')
            ->assertInertia(fn ($page) => $page
                ->where('remote_users.total', 2)
                ->where('remote_users.hosts', [['host' => '203.0.113.5', 'count' => 2]])
                ->etc());
    }

    /**
     * Und die Seite schaltet nichts.
     *
     * **Das ist eine Entscheidung und keine Auslassung** (`docs/36 §22.3v`): Ein
     * Umschalten startet den Datenbankserver neu, auf dem dieses Panel selbst
     * arbeitet — die Anfrage, die den Vorgang anstösst, verlöre ihre
     * Verbindung mitten im Lauf, und übrig bliebe ein Vorgang, der für immer
     * auf „läuft" steht. Deshalb steht dort der Befehl und kein Knopf.
     *
     * Wer das ändern will, ändert diesen Test mit — und liest dabei den Grund.
     */
    public function test_the_settings_page_only_reads(): void
    {
        $schreibend = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'settings/database')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (! in_array($method, ['GET', 'HEAD'], true)) {
                    $schreibend[] = $method.' /'.$route->uri();
                }
            }
        }

        $this->assertSame([], $schreibend, sprintf(
            "Unter /settings/database liegt eine schreibende Route:\n  %s\n\n".
            "Der Fernzugriff wird auf der Kommandozeile geschaltet, weil sein Neustart den\n".
            'Datenbankserver mitnimmt, auf dem dieses Panel arbeitet (docs/36 §22.3v).',
            implode("\n  ", $schreibend),
        ));
    }

    /**
     * **Jedes System wird nach seinem eigenen Zustand gefragt.**
     *
     * Der Fehler, den diese Prüfung festhält, ist schon zweimal passiert und
     * beide Male auf dieselbe Art unsichtbar:
     *
     * - `DatabaseController::remoteAccess()` rief `db.server.info` ohne
     *   Fallunterscheidung — auch auf der Seite einer PostgreSQL-Datenbank.
     *   Daraus wurde entschieden, ob ein PostgreSQL-Zugang ein Netz eintragen
     *   darf.
     * - Diese Seite zeigte bis zum 11. August 2026 gar keine Horchadresse für
     *   PostgreSQL; wer `srvpanel db --remote=on` gefahren hatte und hier
     *   nachsah, bekam die Auskunft von MariaDB.
     *
     * **Beide Antworten haben dieselbe Form**, und genau deshalb fiele es
     * niemandem auf: `remote` ist ein `bool`, die Adresse eine Zeichenkette.
     * Auf einem Server, der nur eines der beiden von aussen erreichbar hat,
     * steht dann das Gegenteil auf der Seite.
     *
     * Geprüft wird im Quelltext, weil die Verwechslung eine Frage der
     * **Zuordnung** ist und nicht des Ergebnisses: Ein Testfall mit einem
     * Agenten, der für beide Operationen dasselbe antwortet, wäre grün.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): `pg.server.info` in
     * `DatabaseSettingsController::postgres()` durch `db.server.info` ersetzen.
     */
    public function test_each_system_is_asked_about_itself(): void
    {
        $paare = [
            ['postgres', 'pg.server.info', 'db.server.info', 'PostgreSQL'],
            ['server', 'db.server.info', 'pg.server.info', 'MariaDB'],
        ];

        foreach ($paare as [$methode, $eigene, $fremde, $system]) {
            /*
             * **Ohne Kommentare, und das hat dieser Wächter an sich selbst
             * gelernt.** Die Erklärung, warum hier *nicht* das andere System
             * gefragt wird, nennt dessen Operation beim Namen — Prosa, die für
             * einen Ausdruck aussieht wie Code. Beim ersten Lauf hat er
             * deshalb seinen eigenen Kommentar gemeldet. `token_get_all()`
             * unterscheidet die beiden; ein regulärer Ausdruck nie.
             */
            /*
             * **Das `<?php` gehört dazu, und ohne es prüft dieser Test nichts.**
             * {@see WithoutPhpComments} fragt `token_get_all()`, und der
             * Tokenizer beginnt ausserhalb von PHP: Ein Rumpf ohne öffnende
             * Marke ist für ihn ein einziges `T_INLINE_HTML`, in dem kein
             * Kommentar vorkommt. Die Kommentare blieben also stehen, und die
             * Prüfung darunter meldete den eigenen Erklärtext als Fund.
             *
             * Gemerkt hat das die CI und nicht der Lauf davor — der prüfte
             * dieselbe Regel mit einem regulären Ausdruck statt mit diesem
             * Aufruf. **Zwei Fassungen derselben Prüfung, und die grüne war die
             * falsche.**
             */
            $quelle = $this->withoutComments(
                "<?php\n".(string) $this->methodSource(DatabaseSettingsController::class, $methode),
            );

            $this->assertStringContainsString($eigene, $quelle, sprintf(
                'DatabaseSettingsController::%s() fragt nicht %s — dann steht auf der Seite der '
                .'Zustand eines anderen Servers.',
                $methode,
                $eigene,
            ));

            $this->assertStringNotContainsString($fremde, $quelle, sprintf(
                'DatabaseSettingsController::%s() fragt %s und meldet damit für %s die Auskunft des '
                .'anderen Systems. Beide Antworten haben dieselbe Form; auffallen würde es erst auf '
                .'einem Server, der nur eines von beiden erreichbar hat.',
                $methode,
                $fremde,
                $system,
            ));
        }
    }

    /**
     * Und die Horchadresse von PostgreSQL steht auch wirklich auf der Seite.
     *
     * Die Brücke zwischen Steuerungscode und Vorlage hält {@see InertiaPropsTest}
     * — er prüft, dass jede *verlangte* Eigenschaft geschickt wird. Die
     * Gegenrichtung prüft er nicht: Eine Angabe, die der Controller schickt und
     * die Seite nie liest, fällt niemandem auf. Genau das wäre hier der
     * teuerste Fall, denn die Angabe ist der ganze Zweck dieser Änderung.
     */
    public function test_the_page_shows_what_postgresql_listens_on(): void
    {
        $seite = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Settings/Database.vue');

        foreach (['postgresql.listen_addresses', 'postgresql.remote', 'remote_networks'] as $feld) {
            $this->assertStringContainsString($feld, $seite, sprintf(
                'Die Seite liest %s nicht. Der Controller schickt die Angabe dann für nichts, und '
                .'der Betreiber sieht über PostgreSQL weiter nur, dass es da ist.',
                $feld,
            ));
        }
    }

    /**
     * **Der Rückweg braucht den Bestand nicht.**
     *
     * Am 11. August 2026 auf `cloudsrv24`: `srvpanel db --remote=on --bind=::`
     * hatte MariaDB IPv6-only gebunden und damit das Panel von seiner eigenen
     * Datenbank abgeschnitten. Der Griff dagegen — `srvpanel db --remote=off` —
     * starb an der Zählung der ausgesperrten Zugänge, *bevor* er den Agenten
     * rief. Der Betreiber musste die Include-Datei von Hand löschen.
     *
     * > Ein Rückweg, der den Bestand braucht, ist keiner für den Fall, dass der
     * > Bestand weg ist.
     *
     * **Die Tabellen werden wirklich weggenommen und nicht nachgestellt.** Eine
     * Attrappe, die eine Ausnahme wirft, prüfte, ob dieses Kommando `catch`
     * schreibt; hier scheitert dieselbe Abfrage aus demselben Grund wie auf dem
     * Server — die Tabelle ist nicht da. Die Rückfrage danach ist der Beleg: Sie
     * kommt erst, nachdem die Zählung durch ist.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): das `try` in
     * `Databases::foreignAccess()` entfernen und unbedingt zählen.
     */
    public function test_the_way_back_does_not_need_the_inventory(): void
    {
        Schema::drop('db_user_networks');
        Schema::drop('db_users');

        $this->artisan('srvpanel:db --remote=off')
            ->expectsOutputToContain('der Bestand ist nicht lesbar')
            ->expectsConfirmation(
                'Dafür wird der Datenbankserver neu gestartet. Das Panel ist dabei kurz ohne Datenbank. Weiter?',
                'no',
            )
            ->expectsOutputToContain('Abgebrochen.')
            ->assertSuccessful();
    }

    /**
     * Und die erlaubten Horchadressen stehen an **einer** Stelle.
     *
     * Das Kommando prüft `--bind`, damit die Meldung lesbar ist, und der Agent
     * prüft denselben Wert noch einmal, weil er die Regel hält. Solange beide
     * dieselbe Liste lesen, ist das kein Widerspruch — schreibt eine von beiden
     * ihre eigene, ist es die zweite Fassung derselben Regel. Bis zum
     * 11. August 2026 war es genau das: `['0.0.0.0', '::']` stand wörtlich im
     * Kommando **und** im Agenten.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): `*` aus
     * `DbRemoteAccess::ADDRESSES` streichen.
     */
    public function test_both_systems_take_the_same_addresses(): void
    {
        $this->assertSame(
            DbRemoteAccess::ADDRESSES,
            PgRemoteAccess::ADDRESSES,
            'MariaDB und PostgreSQL nehmen nicht mehr dieselben Horchadressen. Das Kommando reicht sie '
            .'unübersetzt an beide weiter — ein Wert, den nur eines von beiden kennt, wird dort '
            .'abgewiesen, nachdem das andere schon neu gestartet hat.',
        );

        $quelle = $this->withoutComments(
            "<?php\n".(string) $this->methodSource(DatabasesCommand::class, 'remote'),
        );

        $this->assertStringContainsString('DbRemoteAccess::ADDRESSES', $quelle,
            'Databases::remote() prüft --bind gegen eine eigene Liste statt gegen die des Agenten.');

        foreach (DbRemoteAccess::ADDRESSES as $adresse) {
            $this->assertStringNotContainsString(
                "'".$adresse."'",
                $quelle,
                sprintf('Databases::remote() nennt %s wörtlich — die zweite Fassung derselben Liste.', $adresse),
            );
        }
    }

    /**
     * Und `*` ist der Wert, der beide Stapel bedient.
     *
     * **Gemessen und nicht gelesen** (`docs/44`): Nach `bind-address = ::`
     * meldet `ss -tlnp` auf MariaDB 10.11.14 genau `[::]:3306`, und eine
     * Verbindung auf `127.0.0.1:3306` endet in `Connection refused`. Das Panel
     * verbindet sich über `127.0.0.1`. Vorher stand im Quelltext, `::` decke
     * „auf einem Doppelstapel beides" — daraus wurde eine Anweisung im
     * Abnahmelauf, und die hat das Panel abgeschaltet.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): in
     * `DbRemoteAccess::content()` das `*` gegen `::` tauschen.
     */
    public function test_the_dual_stack_address_is_the_star(): void
    {
        $this->assertContains('*', DbRemoteAccess::ADDRESSES,
            'Ohne * gibt es keinen Wert für „von überall", der das Panel nicht aussperrt.');

        $this->assertStringContainsString('bind-address = *', DbRemoteAccess::content('*'));

        /*
         * Und `srvpanel db --bind=*` kommt durch die Prüfung des Kommandos.
         * Ohne diese Zeile wäre die Liste erweitert und der Weg dahin zu.
         *
         * **Ohne `--no-interaction`, wie im Test über die Rückfrage.** Mit dem
         * Schalter beantwortet Symfony die Frage selbst mit der Vorgabe, die
         * Attrappe wird nie gerufen, und der Lauf scheitert an einer Erwartung,
         * die nichts mit dieser Regel zu tun hat.
         */
        $this->artisan('srvpanel:db --remote=on --bind=*')
            ->expectsConfirmation(
                'Dafür wird der Datenbankserver neu gestartet. Das Panel ist dabei kurz ohne Datenbank. Weiter?',
                'no',
            )
            ->expectsOutputToContain('Abgebrochen.')
            ->assertSuccessful();
    }

    /**
     * Nach dem Umschalten wird gefragt, ob das Panel noch hereinkommt.
     *
     * **Das ist der Fehler vom 11. August, und er sah wie ein Erfolg aus.** Der
     * Agent meldete `Horcht auf :: — Fernzugriff möglich.`, das Kommando
     * verglich die Antwort mit der Absicht, fand sie stimmig und war fertig.
     * Zu diesem Zeitpunkt gab das Panel bereits auf jeder Seite einen 500er.
     *
     * Die Gegenprobe des Agenten läuft über den Unix-Socket ({@see
     * \SrvPanel\Agent\Db\Session}) — eine Strecke, die nicht kaputtgeht, wenn
     * TCP kaputtgeht. Deshalb steht die Frage im Panel.
     *
     * Geprüft wird im Quelltext, weil der Fall einen laufenden Agenten und
     * einen echten Neustart bräuchte. Was hier festgehalten wird, ist die
     * **Reihenfolge**: erst umschalten, dann selbst anklopfen, und bei einem
     * Fehlschlag zurücknehmen.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): den Aufruf von
     * `panelDatabaseUnreachable()` aus `Databases::remote()` entfernen.
     */
    public function test_the_switch_checks_that_the_panel_still_gets_in(): void
    {
        $quelle = $this->withoutComments(
            "<?php\n".(string) $this->methodSource(DatabasesCommand::class, 'remote'),
        );

        $this->assertStringContainsString('panelDatabaseUnreachable', $quelle,
            'Databases::remote() fragt nach dem Umschalten nicht, ob das Panel seine eigene Datenbank '
            .'noch erreicht — dann meldet es Erfolg, während jede Seite einen 500er gibt.');

        $this->assertStringContainsString('undoRemote', $quelle,
            'Es gibt keinen Rückweg für den Fall, dass es sie nicht mehr erreicht.');

        $rueckweg = $this->withoutComments(
            "<?php\n".(string) $this->methodSource(DatabasesCommand::class, 'undoRemote'),
        );

        $this->assertStringContainsString("'off'", $rueckweg,
            'Der Rückweg nimmt den Fernzugriff nicht zurück.');
    }
}
