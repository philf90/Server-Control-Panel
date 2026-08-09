<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use SrvPanel\Agent\Db\Names;
use Tests\TestCase;

/**
 * Das Formular weist ab, was der Agent abweisen würde — und eines mehr.
 *
 * **Diesen Wächter gab es nicht, obwohl ein Kommentar ihn versprach.** In
 * `DatabaseController::store()` stand seit P5: *„Die Gegenprobe, dass beide
 * dasselbe sagen, steht in `DatabaseFormTest`."* Die Datei gab es nicht. Das ist
 * wortwörtlich das Muster aus CLAUDE.md — eine Zeichenkette, die auf etwas
 * verweist, ohne dass etwas den Bezug prüft —, diesmal in einem Kommentar über
 * einen Wächter. Gefunden am 8. August 2026, als die Frage des Betreibers nach
 * dem fehlenden Passwort hierher führte; `GuardReachTest` sorgt seitdem dafür,
 * dass ein genannter Test auch existiert.
 *
 * Geprüft werden drei Dinge:
 *
 * 1. **Dieselbe Regel für den Namen.** Der Ausdruck im Formular und der im
 *    Agenten müssen dasselbe zulassen. Zwei Formulierungen einer Regel driften,
 *    und die im Formular ist die, die zuerst nachgibt.
 * 2. **Ein vergebener Zugangsname wird abgewiesen** — vor dem Aufruf des
 *    Agenten, weil dessen `ALTER USER` sonst ein Passwort ersetzt, das ein Kunde
 *    schon in einer Konfigurationsdatei stehen hat.
 * 3. **Die Meldung erscheint an dem Feld, das der Absender geschickt hat.**
 */
final class DatabaseFormTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Account, 1: Subscription} */
    private function customerWith(string $systemUser = 'p1000'): array
    {
        $customer = Customer::factory()->create();

        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'system_user' => $systemUser,
        ]);

        $account = Account::factory()->customer($customer)->create();

        $account->assignedSubscriptions()->attach($subscription->id, [
            'permissions' => json_encode(['databases' => true]),
        ]);

        return [$account, $subscription];
    }

    /**
     * Der Ausdruck des Formulars und der des Agenten lassen dasselbe zu.
     *
     * Verglichen wird an Beispielen und nicht an der Zeichenkette: Zwei
     * Ausdrücke dürfen verschieden geschrieben sein, solange sie dasselbe
     * entscheiden — `$` gegen `\z`, `{0,15}` gegen `{,15}`. Was zählt, ist die
     * Antwort auf jeden Fall, der vorkommt.
     */
    public function test_the_form_allows_exactly_what_the_agent_allows(): void
    {
        $form = $this->formPattern();
        $agent = $this->agentPattern();

        $faelle = [
            'shop', 'a', 'blog2', 'mein_shop', 'p1000',
            'sechzehnzeichen_', 'siebzehnzeichen__',
            '2shop', 'Shop', 'shop-2', 'shop.2', '_shop', '', 'shop ', "shop\n",
        ];

        foreach ($faelle as $fall) {
            $this->assertSame(
                preg_match($agent, $fall) === 1,
                preg_match($form, $fall) === 1,
                sprintf(
                    'Formular und Agent entscheiden über %s verschieden. Was das Formular '.
                    'durchlässt und der Agent abweist, wird ein Fehlschlag ohne lesbaren Grund; '.
                    'umgekehrt eine Regel, die strenger ist, als sie sein müsste.',
                    var_export($fall, true),
                ),
            );
        }
    }

    /**
     * Ein Zugangsname, den es schon gibt, wird abgewiesen.
     *
     * **Und die Meldung nennt den Namen** — daran erkennt dieser Test, dass die
     * Abweisung aus der Prüfung kommt und nicht vom Agenten. In diesem Container
     * läuft keiner; ohne die Prüfung käme ebenfalls ein Fehler am Feld an, nur
     * eben „Verbindung zum Agenten fehlgeschlagen". Beides ist rot, und nur
     * eines davon ist der Beleg.
     */
    public function test_an_existing_access_name_is_refused_before_the_agent_is_asked(): void
    {
        [$account, $subscription] = $this->customerWith();

        $database = Database::factory()->forSubscription($subscription, 'shop')->create();

        DbUser::factory()->create([
            'subscription_id' => $subscription->id,
            'name' => 'p1000_user',
            'label' => 'user',
            'host' => 'localhost',
        ]);

        $response = $this->actingAs($account)
            ->from("/databases/{$database->id}")
            ->post("/databases/{$database->id}/users", ['label' => 'user']);

        $response->assertSessionHasErrors('label');

        $this->assertStringContainsString(
            'p1000_user',
            (string) session('errors')?->first('label'),
            'Die Meldung stammt nicht aus der Namensprüfung — dann belegt dieser Test nichts.',
        );

        // Und es ist kein zweiter Zugang entstanden.
        $this->assertSame(1, $this->accessCount());
    }

    /**
     * Jede Meldung trifft das Feld, das der Absender geschickt hat.
     *
     * Die beiden Formulare nennen es verschieden — `user_label` beim Anlegen
     * einer Datenbank, `label` beim Nachtragen eines Zugangs —, und die Zeile
     * unter dem Feld liest jeweils den eigenen Namen. Eine Meldung am falschen
     * Schlüssel steht dann nur noch in der Zusammenfassung oben.
     *
     * **Geprüft am Aufruf und nicht am Lauf.** Das Anlegen einer Datenbank
     * fragt zuerst den Agenten, und in diesem Container gibt es keinen; die
     * Meldung käme dann von dort und nicht aus der Prüfung. Was sich prüfen
     * lässt, ist der Bezug: Der Feldname, den eine Methode weiterreicht, muss
     * einer sein, den sie selbst geprüft hat.
     */
    public function test_every_message_lands_on_a_field_of_its_own_form(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/DatabaseController.php'));

        // Die Methodenrümpfe: ab `public function` bis zum nächsten.
        $bodies = preg_split('/\n    (?:public|private) function /', $source) ?: [];
        $seen = 0;

        foreach ($bodies as $body) {
            /*
             * **`$this->createUserFor(` und `field:` — beides mit Absicht.**
             *
             * Der Ausdruck stand hier ohne `$this->` und suchte den Feldnamen
             * an der letzten Argumentstelle. Am 8. August 2026 bekam die
             * Methode einen fünften Parameter mit Vorgabewert
             * (`string $host = 'localhost'`), und der Ausdruck fand ihn — in
             * der *Erklärung* der Methode statt in einem Aufruf. Der Test
             * meldete daraufhin, `localhost` sei ein Feldname ohne Prüfregel.
             *
             * Ein benannter Parameter kann das nicht: `field:` steht nur dort,
             * wo jemand ihn übergibt, und eine Vorgabe in der Erklärung sieht
             * niemals so aus.
             */
            if (preg_match("/\\\$this->createUserFor\(.*?field: '([a-z_]+)'/s", $body, $call) !== 1) {
                continue;
            }

            $seen++;
            $field = $call[1];

            $this->assertMatchesRegularExpression(
                "/'".$field."' => \[/",
                $body,
                sprintf(
                    'Diese Methode reicht `%s` als Feldnamen weiter und prüft ein Feld dieses '.
                    'Namens gar nicht. Die Meldung landet dann an einem Feld, das ihr Formular '.
                    'nicht hat — sichtbar nur noch in der Zusammenfassung oben.',
                    $field,
                ),
            );
        }

        $this->assertSame(2, $seen, 'Zwei Formulare legen einen Zugang an — mehr oder weniger heisst, die Aufteilung hat sich geändert.');
    }

    /**
     * Wie viele Zugänge es gibt — ohne Mandantenklammer gezählt.
     *
     * **Nicht `count()`.** Der Name gehört der Basisklasse, und eine
     * abgeleitete Fassung bricht beim *Laden* der Klasse — `php -l` sieht davon
     * nichts, und `php artisan test` endet, bevor ein Test läuft (CLAUDE.md).
     */
    private function accessCount(): int
    {
        return app(Tenancy::class)->withoutRestriction(
            static fn (): int => DbUser::query()->count(),
        );
    }

    /** Der Ausdruck aus der Prüfregel des Formulars. */
    private function formPattern(): string
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/DatabaseController.php'));

        $this->assertSame(
            1,
            preg_match("/'label' => \['required', 'string', 'regex:([^']+)'\]/", $source, $treffer),
            'Die Prüfregel des Formulars ist nicht auffindbar — dann vergleicht dieser Test nichts.',
        );

        return $treffer[1];
    }

    /** Und der des Agenten, aus der Konstante und nicht abgeschrieben. */
    private function agentPattern(): string
    {
        $names = new ReflectionClass(Names::class);
        $suffix = $names->getConstant('SUFFIX');

        $this->assertIsString($suffix, 'Names::SUFFIX gibt es nicht mehr — der Vergleich hat kein Gegenüber.');

        return $suffix;
    }
}
