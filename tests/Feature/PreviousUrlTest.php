<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RememberPageUrl;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ein Formularfehler kommt am Formular an — und nicht auf der Anmeldeseite.
 *
 * ## Der Fehler
 *
 * Laravel merkt sich die vorige Seite nur bei GET-Anfragen, die **nicht** als
 * XHR gelten. Jede Inertia-Navigation ist XHR. In diesem Panel wurde
 * `_previous.url` damit nach dem Anmelden nie wieder gesetzt: Es stand auf der
 * letzten vollständigen Seitenladung — `/login`. Dorthin leitete jede
 * `ValidationException`, die `guest`-Middleware schickte den angemeldeten
 * Benutzer weiter auf die Übersicht, und die Meldung sah niemand.
 *
 * Das traf **jedes Formular des Panels**, seit es das Panel gibt.
 *
 * ## Warum kein Test das je gemerkt hat
 *
 * **Weil die Tests einen `Referer` schicken.** `->from('/irgendwo')` setzt ihn,
 * und `back()` liest zuerst ihn — im Test funktioniert also genau der Weg, den
 * es im Browser nicht gibt: Der Vhost des Panels schickt
 * `Referrer-Policy: no-referrer`. Fast jeder Formulartest hier benutzt
 * `->from()`, weil es die bequeme Art ist, `assertRedirect` zu schreiben.
 *
 * > **Ein Test, der eine Kopfzeile mitschickt, die der Browser nicht schickt,
 * > prüft eine andere Anwendung.**
 *
 * Das ist dieselbe Lehre wie `docs/42`: *Ein Test, der gegen eine andere
 * Datenbank läuft als der Server, prüft die Grenzen der falschen.* Deshalb
 * benutzt dieser Test `->from()` **nicht** — er baut den Zustand so auf, wie
 * ein Browser ihn erzeugt: erst die Seite ansehen, dann das Formular abschicken.
 *
 * ## Und derselbe Satz noch einmal, andersherum — der Fund vom 13. August 2026
 *
 * **Dieser Wächter hielt seine Regel nicht.** Der Bruch dazu — `RememberPageUrl`
 * aus `bootstrap/app.php` streichen — liess ihn **grün**, gefunden vom Lauf des
 * Bruchskripts und von keinem Test.
 *
 * ## Und ein drittes Mal derselbe Satz — der Fund vom 26. August 2026
 *
 * Hier stand `->withHeader('X-Inertia-Version', '')`, und die leere Fassung ist
 * **kein Wert, den ein Browser je schickt**: Inertia trägt dort den Stand der
 * Bauartefakte ein, und stimmt er nicht mit dem des Servers überein, antwortet
 * die Anwendung mit **409** statt mit der Seite.
 *
 * Grün war das nur, weil in der CI `php artisan test` **vor** `npm run build`
 * läuft — ohne `public/build/manifest.json` ist die Fassung `null`, und die
 * leere Kopfzeile passte dazu. In einem Container, in dem jemand gebaut hat,
 * fielen beide Fälle mit 409, und zwar ohne dass an der Regel etwas kaputt
 * gewesen wäre. Gemessen in beide Richtungen: mit Manifest 0 von 2, ohne
 * Manifest 2 von 2.
 *
 * > **Eine Kopfzeile mit einem Wert, den der Browser nie sendet, ist derselbe
 * > Fehler wie eine, die er nie sendet — sie fällt nur später auf.**
 *
 * Gefragt wird deshalb die Anwendung selbst, so wie der Browser es tut.
 *
 * ## Der Grund für das zweite Mal
 *
 * Der Satz oben mit umgekehrtem Vorzeichen: Nicht eine Kopfzeile
 * zu viel, sondern **eine zu wenig.** Die Aufrufe hier trugen `X-Inertia`, aber
 * kein `X-Requested-With` — im Browser setzt Inertia beide. Ohne die zweite ist
 * `$request->ajax()` falsch, und dann merkt sich Laravels `StartSession` die Seite
 * **selbst**. Genau der Weg, dessen Fehlen diese Mittelschicht ausgleicht, war
 * im Test also offen; die Regel wurde von der Voraussetzung erfüllt, die es im
 * Browser nicht gibt.
 *
 * > **Eine fehlende Kopfzeile prüft eine andere Anwendung genauso wie eine
 * > überflüssige — nur fällt sie niemandem auf, weil der Test grün ist.**
 *
 * Die Aufrufe schicken sie seitdem. Belegt ist es am Bruch: Ohne
 * {@see RememberPageUrl} sind jetzt **beide** Fälle rot.
 */
final class PreviousUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein Kunde mit einer PostgreSQL-Datenbank und einem Zugang daran.
     *
     * @return array{0: Account, 1: Database, 2: DbUser}
     */
    private function scenario(): array
    {
        [$subscription, $database, $user] = app(Tenancy::class)->withoutRestriction(function (): array {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

            /** @var Database $database */
            $database = Database::factory()->postgres()->forSubscription($subscription, 'shop')->create();

            /** @var DbUser $user */
            $user = DbUser::factory()->postgres()->forSubscription($subscription, 'web')->create();

            $user->databases()->attach($database);

            return [$subscription, $database, $user];
        });

        $customer = Customer::query()->findOrFail($subscription->customer_id);

        return [Account::factory()->customer($customer)->create(), $database, $user];
    }

    /**
     * Die Fassung der Bauartefakte — die, die auch der Browser mitschickt.
     *
     * **Nicht `Inertia::getVersion()`.** Die steht ausserhalb einer Anfrage auf
     * der leeren Zeichenkette; gesetzt wird sie von genau dieser Mittelschicht,
     * und zwar je Anfrage. Gefragt wird deshalb sie.
     */
    private function assetVersion(): string
    {
        return (string) (new HandleInertiaRequests)->version(request());
    }

    /**
     * Eine Inertia-Navigation ist eine Seite und wird als solche gemerkt.
     *
     * **Die Untergrenze dieses Wächters.** Ohne diesen Fall bliebe unbemerkt,
     * wenn {@see RememberPageUrl} gar nichts mehr merkt —
     * der Test darunter wäre dann immer noch grün, sobald irgendetwas anderes
     * zufällig dieselbe Adresse ablegte.
     */
    public function test_an_inertia_navigation_is_remembered_as_the_previous_page(): void
    {
        [$account, $database] = $this->scenario();

        $this->actingAs($account)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', $this->assetVersion())
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("/databases/{$database->id}")
            ->assertSuccessful();

        $this->assertSame(
            url("/databases/{$database->id}"),
            session()->previousUrl(),
            'Eine Inertia-Navigation wird nicht als vorige Seite gemerkt — dann weiss „zurück" nicht, wohin.',
        );
    }

    /**
     * **Und deshalb landet ein Eingabefehler am Formular.**
     *
     * Kein `->from()`: Der Browser schickt hier keinen `Referer`, und ein Test,
     * der einen mitschickt, prüft den Weg, den es nicht gibt.
     *
     * Der Fehlschlag entsteht in `DatabaseController::guardNetwork()` — in
     * diesem Container antwortet kein Agent, der Server gilt damit als nur
     * lokal erreichbar, und ein Netz käme nie zustande. Das ist eine
     * `ValidationException` wie jede andere; welche es ist, ist für diese Regel
     * gleichgültig.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): `RememberPageUrl` aus
     * `bootstrap/app.php` streichen.
     */
    public function test_a_form_error_returns_to_the_page_and_not_to_the_login(): void
    {
        [$account, $database, $user] = $this->scenario();

        $this->actingAs($account)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', $this->assetVersion())
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("/databases/{$database->id}")
            ->assertSuccessful();

        $response = $this->actingAs($account)
            ->post("/databases/{$database->id}/users/{$user->id}/networks", ['cidr' => '0.0.0.0/0']);

        $response->assertSessionHasErrors('cidr');

        $response->assertRedirect("/databases/{$database->id}");

        $this->assertNotSame(
            url('/login'),
            $response->headers->get('location'),
            'Ein Eingabefehler leitet auf die Anmeldung. Dort schickt die guest-Middleware den '
            .'angemeldeten Benutzer weiter auf die Übersicht — und die Meldung, die es gibt, sieht '
            .'niemand.',
        );
    }
}
