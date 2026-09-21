<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplyTenancy;
use App\Http\Middleware\AuthenticateToken;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Eine Liste ohne Mandantenklammer gibt `200 []` — und das meldet niemand.
 *
 * ## Der Fall, für den es diesen Wächter gibt
 *
 * Eine **gebundene** fremde Kennung gibt 404 und fällt auf. Eine **Liste**
 * ohne Klammer gibt `200` und eine leere Antwort — und ein Kunde ohne
 * Abonnements sieht von aussen genauso aus. Gemessen am 21. September 2026
 * (`docs/130` A4), vorhergesagt schon in `docs/128` M8.
 *
 * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer
 * > leeren Liste und nicht mit einem Fehler — und `200 []` ist die Antwort,
 * > die niemand meldet.**
 *
 * ## Zwei Fälle, und der zweite ist der Grund für den ersten
 *
 * Der strukturelle Fall hält, dass **jede** Route unter `api/` durch die
 * Klammer läuft. Der Fall durch die Tür belegt, dass der Unterschied
 * überhaupt sichtbar wäre — ohne ihn hielte der erste eine Regel, von der
 * niemand weiss, ob sie etwas bewirkt.
 */
final class ApiEmptyListTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{kopf: array<string, string>} */
    private function kundeMitAbo(): array
    {
        $kunde = Customer::factory()->create();
        Subscription::factory()->create(['customer_id' => $kunde->id]);
        $konto = Account::factory()->customer($kunde)->create();

        return ['kopf' => ['Authorization' => 'Bearer '.ApiToken::mint($konto, 'Prüfkörper')['plain']]];
    }

    /**
     * Jede Route unter `api/` läuft durch die Klammer.
     *
     * Gefragt wird der Router und keine Liste in diesem Test — und die Gruppe
     * wird aufgelöst, weil `gatherMiddleware()` ihren **Namen** zurückgibt und
     * nicht ihre Mitglieder.
     */
    public function test_every_api_route_passes_the_clamp(): void
    {
        $this->app?->make(HttpKernel::class);

        $gruppen = Route::getMiddlewareGroups();
        $ohne = [];
        $geprueft = 0;

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $geprueft++;

            $kette = [];

            foreach ($route->gatherMiddleware() as $eintrag) {
                if (is_string($eintrag) && array_key_exists($eintrag, $gruppen)) {
                    foreach ($gruppen[$eintrag] as $mitglied) {
                        $kette[] = $mitglied;
                    }

                    continue;
                }

                $kette[] = $eintrag;
            }

            $abgelegt = $route->excludedMiddleware();

            if (! in_array(ApplyTenancy::class, $kette, true)
                || in_array(ApplyTenancy::class, $abgelegt, true)) {
                $ohne[] = $route->uri();
            }
        }

        self::assertGreaterThan(3, $geprueft,
            'Es werden kaum Routen unter api/ gefunden — dann prüft dieser Fall nichts.');

        self::assertSame([], $ohne, sprintf(
            "Diese Routen laufen nicht durch die Mandantenklammer:\n  %s\n\n".
            'Eine Liste antwortet dann mit `200 []` — und das ist von „dieser Kunde hat nichts" '.
            'nicht zu unterscheiden.',
            implode("\n  ", $ohne),
        ));
    }

    /** Mit Klammer steht eine Zeile da. */
    public function test_the_list_carries_the_own_subscription(): void
    {
        ['kopf' => $kopf] = $this->kundeMitAbo();

        $antwort = $this->withHeaders($kopf)->getJson('/api/v1/subscriptions');

        $antwort->assertOk();
        self::assertCount(1, $antwort->json('data'));
    }

    /**
     * Und ohne Klammer ist sie leer — als **erste** Anfrage dieses Falls.
     *
     * `Tenancy` ist ein Singleton; zwei Anfragen in einem PHPUnit-Fall teilen
     * sich seinen Zustand, php-fpm tut das nicht. Stünde diese Messung hinter
     * der darüber, mässe sie die Klammer der vorigen Anfrage.
     *
     * > **Ein Prüfstand, der mehrere Anfragen in einem Prozess fährt, misst
     * > den Zustand, den die vorige hinterlassen hat.**
     */
    public function test_without_the_clamp_the_same_list_is_empty(): void
    {
        ['kopf' => $kopf] = $this->kundeMitAbo();

        Route::middleware([AuthenticateToken::class])
            ->get('/api/probe/liste', fn () => response()->json([
                'data' => Subscription::query()->pluck('id')->all(),
            ]));

        $antwort = $this->withHeaders($kopf)->getJson('/api/probe/liste');

        $antwort->assertOk();
        self::assertSame([], $antwort->json('data'),
            'Ohne Klammer kommt etwas zurück — dann misst der Fall darüber nichts.');
    }

    /**
     * Und auf **dieser** Route antwortet die Policy zuerst — gemessen.
     *
     * ## Eine Berichtigung an der eigenen Messrunde
     *
     * `docs/130` A4 hat `200 []` an einer Wegwerfroute **ohne** `can:`
     * gemessen und daraus geschlossen, ein Kunde ohne Abonnements und eine
     * Route ohne Klammer seien von aussen gleich. Für die echte Route stimmt
     * das nicht: `SubscriptionPolicy::viewAny()` lässt nur durch, wer
     * überhaupt ein Abonnement erreicht, und ein Kunde ohne bekommt **403**.
     *
     * > **Ein Prüfkörper ohne die Wache des Prüflings misst eine andere
     * > Route.**
     *
     * Der Befund bleibt trotzdem stehen, und dieser Wächter mit ihm: Die
     * Policy ist ein zweiter Mechanismus und keine Eigenschaft der Klammer.
     * Eine Route, die morgen ohne `viewAny` entsteht — oder eine, deren
     * Policy jeden durchlässt —, hat den Fall sofort wieder. Deshalb hält der
     * Fall oben die **Klammer** und nicht die Policy.
     */
    public function test_a_customer_without_subscriptions_is_refused_by_the_policy(): void
    {
        $konto = Account::factory()->customer(Customer::factory()->create())->create();
        $kopf = ['Authorization' => 'Bearer '.ApiToken::mint($konto, 'Prüfkörper')['plain']];

        $this->withHeaders($kopf)->getJson('/api/v1/subscriptions')->assertForbidden();
    }

    /**
     * Und `/me` beantwortet die Frage, die eine leere Liste offenlässt.
     *
     * Eine Null neben einem Namen ist eine Auskunft; eine leere Liste ist es
     * nicht. Genau dafür gibt es die Route.
     */
    public function test_me_answers_with_a_number(): void
    {
        $konto = Account::factory()->customer(Customer::factory()->create())->create();
        $kopf = ['Authorization' => 'Bearer '.ApiToken::mint($konto, 'Prüfkörper')['plain']];

        $antwort = $this->withHeaders($kopf)->getJson('/api/v1/me');

        $antwort->assertOk();
        self::assertSame(0, $antwort->json('data.subscriptions'));
    }
}
