<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplyTenancy;
use App\Http\Middleware\AuthenticateToken;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\AccountAccessReachTest;

/**
 * Die Mandantenklammer steht vor der Modellbindung — in **beiden** Gruppen.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `bootstrap/app.php` erklärt die Reihenfolge seit P7b und schrieb daneben:
 * *„Ein Test hält die Reihenfolge fest, damit sie nicht beim nächsten Umbau
 * still zurückfällt."* **Den Test gab es nicht.** Ausgezählt am
 * 21. September 2026 nannte keine Datei unter `tests/` diese beiden
 * Mittelschichten zusammen; gehalten war nur das Paar `EnforceAccountAccess`
 * vor `EnforceAdminNetwork` ({@see AccountAccessReachTest}).
 *
 * > **Eine Zeile, die einen Wächter behauptet, ist teurer als keine — der
 * > Nächste baut ihn nicht, weil er ihn für gebaut hält.**
 *
 * ## Und die Begründung daneben war auch falsch
 *
 * Sie lautete, eine Bindung vor der Klammer mache aus „nicht gefunden" ein
 * „verboten", und damit liesse sich abzählen, welche Kennungen es gibt.
 * Gemessen (`docs/130` A3) gibt die umgedrehte Reihenfolge **404 für das
 * fremde und 404 für das eigene** Abonnement: Die Klammer steht beim Binden im
 * Grundzustand, und der verweigert alles. Der Schaden ist kein Leck, sondern
 * ein Panel, in dem kein Kunde mehr seine eigene Seite sieht.
 *
 * > **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch
 * > dann falsch, wenn der Handgriff daneben richtig ist.**
 *
 * Deshalb misst der zweite Fall hier das **eigene** Abonnement und nicht das
 * fremde: Das fremde gibt in beiden Reihenfolgen 404 und trennt die Fälle
 * nicht.
 *
 * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
 * > misst nicht.**
 */
final class MiddlewareOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Mitglieder einer Gruppe, in ihrer Reihenfolge.
     *
     * Der HTTP-Kernel wird vorher angefasst: Die Gruppen kommen aus
     * `withMiddleware(…)` und stehen erst im Router, nachdem er sie dorthin
     * gespiegelt hat. Ohne das fragte dieser Wächter eine leere Liste — und
     * eine leere Liste hält jede Reihenfolge ein.
     *
     * @return list<string>
     */
    private function group(string $name): array
    {
        $this->app?->make(HttpKernel::class);

        $gruppen = Route::getMiddlewareGroups();

        self::assertArrayHasKey($name, $gruppen, sprintf(
            'Die Gruppe `%s` gibt es nicht — dieser Wächter misst dann nichts.',
            $name,
        ));

        return array_values(array_filter(
            $gruppen[$name],
            static fn (mixed $m): bool => is_string($m),
        ));
    }

    /** @param  list<string>  $gruppe */
    private function position(array $gruppe, string $mittelschicht, string $name): int
    {
        $wo = array_search($mittelschicht, $gruppe, true);

        self::assertIsInt($wo, sprintf(
            "`%s` steht nicht in der Gruppe `%s`:\n  %s",
            $mittelschicht,
            $name,
            implode("\n  ", $gruppe),
        ));

        return $wo;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gruppen(): iterable
    {
        yield 'web' => ['web'];
        yield 'api' => ['api'];
    }

    #[DataProvider('gruppen')]
    public function test_the_clamp_stands_before_the_binding(string $name): void
    {
        $gruppe = $this->group($name);

        $klammer = $this->position($gruppe, ApplyTenancy::class, $name);
        $bindung = $this->position($gruppe, SubstituteBindings::class, $name);

        self::assertLessThan($bindung, $klammer, sprintf(
            "In der Gruppe `%s` steht die Modellbindung vor der Mandantenklammer:\n  %s\n\n".
            'Beim Binden stünde die Klammer dann im Grundzustand, und der verweigert alles — '.
            'auch das eigene Abonnement. Gemessen in docs/130 A3.',
            $name,
            implode("\n  ", $gruppe),
        ));
    }

    /**
     * Und die Wache steht vor der Klammer, sonst hat diese nichts zu klammern.
     *
     * `ApplyTenancy` fragt `$request->user()`. Läuft sie vor der Wache, ist
     * dort niemand, die Klammer bleibt im Grundzustand — und der verweigert
     * alles.
     */
    public function test_the_api_guard_stands_before_the_clamp(): void
    {
        $gruppe = $this->group('api');

        $wache = $this->position($gruppe, AuthenticateToken::class, 'api');
        $klammer = $this->position($gruppe, ApplyTenancy::class, 'api');

        self::assertLessThan($klammer, $wache, sprintf(
            "In der Gruppe `api` steht die Klammer vor der Wache:\n  %s",
            implode("\n  ", $gruppe),
        ));
    }

    /**
     * Ein Prüfkörper: Konto, Abonnement, Marke — und die beiden Routen.
     *
     * @return array{kopf: array<string, string>, id: int}
     */
    private function probe(): array
    {
        $kunde = Customer::factory()->create();
        $abo = Subscription::factory()->create(['customer_id' => $kunde->id]);
        $konto = Account::factory()->customer($kunde)->create();
        $marke = ApiToken::mint($konto, 'Prüfkörper');

        Route::middleware([
            AuthenticateToken::class,
            ApplyTenancy::class,
            SubstituteBindings::class,
            'can:view,subscription',
        ])->get('/api/probe/richtig/{subscription}', fn (Subscription $subscription) => response()->json([
            'id' => $subscription->id,
        ]));

        Route::middleware([
            SubstituteBindings::class,
            AuthenticateToken::class,
            ApplyTenancy::class,
            'can:view,subscription',
        ])->get('/api/probe/falsch/{subscription}', fn (Subscription $subscription) => response()->json([
            'id' => $subscription->id,
        ]));

        return [
            'kopf' => ['Authorization' => 'Bearer '.$marke['plain']],
            'id' => (int) $abo->id,
        ];
    }

    /**
     * Die richtige Reihenfolge trägt — durch die Tür gemessen.
     */
    public function test_the_right_order_reaches_the_own_subscription(): void
    {
        ['kopf' => $kopf, 'id' => $id] = $this->probe();

        $this->withHeaders($kopf)->get('/api/probe/richtig/'.$id)->assertOk();
    }

    /**
     * Und die umgedrehte verbirgt es — als **erste** Anfrage dieses Falls.
     *
     * ## Warum das ein eigener Fall ist und kein zweiter Aufruf daneben
     *
     * {@see Tenancy} ist ein Singleton. Zwei Anfragen in
     * **einem** PHPUnit-Fall teilen sich seinen Zustand; php-fpm tut das
     * nicht. Der erste Wurf dieses Wächters hat genau daran gemessen: Die
     * Gegenprobe lief hinter der richtigen Reihenfolge und bekam **200** —
     * weil die Klammer der vorigen Anfrage noch stand.
     *
     * > **Ein Prüfstand, der mehrere Anfragen in einem Prozess fährt, misst
     * > den Zustand, den die vorige hinterlassen hat.**
     *
     * Derselbe Satz steht seit `docs/103 §1` im Repo und ist hier zum dritten
     * Mal bezahlt worden — einmal beim Messen (`docs/130` A3), einmal hier.
     */
    public function test_a_reversed_order_hides_the_own_subscription(): void
    {
        ['kopf' => $kopf, 'id' => $id] = $this->probe();

        $this->withHeaders($kopf)->get('/api/probe/falsch/'.$id)->assertNotFound();
    }
}
