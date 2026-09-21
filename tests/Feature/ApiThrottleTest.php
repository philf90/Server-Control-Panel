<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\SrvPanelServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Jede Route unter `api/` trägt eine Begrenzung (B7).
 *
 * ## Warum es sie überhaupt braucht
 *
 * Vor dieser Stufe hatte dieses Panel **keine einzige** `throttle`-Middleware
 * (gemessen, `docs/130 §1`); `LoginThrottle` ist handgebaut und bedient genau
 * die Anmeldung. Eine Schnittstelle, die ein Skript bedient, bremst zweierlei:
 * das Durchprobieren von Marken und einen Klienten, der in einer Schleife
 * hängt.
 *
 * ## Und sie steht **vor** der Wache
 *
 * Sonst kostet jeder Versuch mit einer erfundenen Marke einen
 * Datenbankzugriff. Der Preis dafür ist der Schlüssel: An dieser Stelle gibt
 * es noch kein Konto, gezählt wird die Adresse. Was das nicht kann — zwei
 * Marken hinter derselben Adresse trennen —, steht in `docs/131 §9`.
 *
 * > **Ein Schlüssel, der einen Wert nennt, den es an dieser Stelle nicht gibt,
 * > ist keine Einschränkung, sondern eine Zusage ohne Gegenstand.**
 */
final class ApiThrottleTest extends TestCase
{
    public function test_every_api_route_carries_a_limit(): void
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

            $hat = false;

            foreach ($kette as $mittelschicht) {
                if (is_string($mittelschicht) && str_starts_with($mittelschicht, 'throttle:')) {
                    $hat = true;

                    break;
                }
            }

            if (! $hat) {
                $ohne[] = $route->uri();
            }
        }

        self::assertGreaterThan(3, $geprueft,
            'Es werden kaum Routen unter api/ gefunden — dann prüft dieser Fall nichts.');

        self::assertSame([], $ohne, sprintf(
            "Diese Routen tragen keine Begrenzung:\n  %s",
            implode("\n  ", $ohne),
        ));
    }

    /**
     * Und der Grenzwert ist gesetzt — gemessen am aufgelösten Limit.
     *
     * Ein Wächter über die Zeile im Provider bliebe grün, wenn jemand den
     * Namen `api` dort stehenliesse und den Limiter woanders überschriebe.
     * Gefragt wird deshalb die Registratur und nicht der Quelltext.
     */
    public function test_the_limiter_is_registered_and_counts_the_address(): void
    {
        $limiter = RateLimiter::limiter('api');

        self::assertNotNull($limiter, 'Es gibt keinen Limiter namens `api` — `throttle:api` liefe dann ins Leere.');

        $anfrage = Request::create('/api/v1/me');
        $anfrage->server->set('REMOTE_ADDR', '203.0.113.7');

        $limit = $limiter($anfrage);

        self::assertInstanceOf(Limit::class, $limit);

        /*
         * **Der Schlüssel ist die Adresse**, und das ist die Regel — nicht die
         * Zahl daneben.
         */
        self::assertSame('203.0.113.7', $limit->key);

        /*
         * **Und die Zahl wird gegen eine Spanne gehalten und nicht gegen sich
         * selbst.**
         *
         * Hier stand `assertSame(API_PER_MINUTE, $limit->maxAttempts)`. Der
         * Limiter wird aus genau dieser Konstante gebaut — die Behauptung war
         * also wahr, egal welchen Wert sie trägt, und ein Eingriff, der sie
         * verdoppelt, blieb grün. Gemeldet hat es der Bruchlauf und nicht das
         * Nachdenken.
         *
         * > **Ein Wächter, der einen Wert gegen die Quelle vergleicht, aus der
         * > er stammt, prüft die Zuleitung und nicht den Wert.**
         *
         * Die Spanne fängt, was eine Zuleitung nicht fangen kann: den Tippfehler
         * in der Konstante selbst. Eine Null zuviel machte aus der Bremse eine
         * Verzierung, eine zuwenig aus der API ein Ärgernis.
         */
        self::assertGreaterThanOrEqual(10, $limit->maxAttempts,
            'Weniger als zehn Anfragen je Minute sind keine Schnittstelle mehr.');
        self::assertLessThanOrEqual(600, $limit->maxAttempts,
            'Mehr als zehn Anfragen je Sekunde bremsen nichts — das ist eine Verzierung.');

        self::assertSame(SrvPanelServiceProvider::API_PER_MINUTE, $limit->maxAttempts,
            'Der Grenzwert kommt nicht mehr aus der einen Konstante.');
    }
}
