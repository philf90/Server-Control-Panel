<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditResult;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Support\Audit\Impersonation;
use App\Support\Authorization\RouteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as Router;
use ReflectionClassConstant;
use Tests\Support\ResolvesRouteMiddleware;
use Tests\TestCase;

/**
 * In fremder Sicht ändert keine Route das Konto des Kunden — gefragt von der
 * Seite der Tür.
 *
 * ## Der Befund
 *
 * `docs/144 §8`, gefunden am 11. Oktober 2026 im Lauf für B7 und B8: Auf
 * „Mein Konto" stand in fremder Sicht neben „Zweiter Faktor" der Verweis
 * „verwalten", und `TwoFactorSetupController` fragte die fremde Sicht nicht.
 * Im Container durch die echten Routen gemessen liess sich der zweite Faktor
 * am Konto des Kunden einschalten — mit einem Geheimnis in der App des
 * Betreibers und mit Wiederherstellungscodes, die nur der Betreiber gesehen
 * hat. Abschalten liess er sich nur mit einem Code aus derselben App.
 *
 * `ImpersonationControlTest` war dabei grün und konnte nicht anders: Er liest
 * die abgewiesenen Handlungen aus den Aufrufen von
 * `refuseWhileImpersonating()`, und ein Controller, der sie nie ruft, kommt in
 * seiner Liste gar nicht vor.
 *
 * > **Ein Wächter, der die Abweisungen aus ihren Aufrufen liest, sieht keine
 * > Tür, die gar nicht abweist.**
 *
 * ## Was hier gemessen wird
 *
 * **Die Routen kommen aus dem Router und nicht aus einer Liste.** Jede Route,
 * die ein angemeldetes Konto ohne Fähigkeit erreicht (`auth` ohne `can:`) und
 * die schreibt (eine Methode ausser GET), ändert das Konto, mit dem jemand
 * angemeldet ist — in fremder Sicht das des Kunden. In fremder Sicht wird
 * jede davon durch die Tür geschickt, mit leerem Rumpf, und muss 403 geben
 * und eine Zeile `denied` ins Protokoll schreiben. Leer ist der Rumpf, weil
 * die Abweisung vor der Prüfung steht; gibt eine Route 302 mit einer
 * Prüfmeldung, hat sie zuerst geprüft und dann nicht abgewiesen.
 *
 * Was in fremder Sicht durchgehen muss, steht unten mit Grund — **in beide
 * Richtungen**: Ein Eintrag nennt eine Route, die es als schreibende Route
 * ohne Fähigkeit gibt, und sie weist dort nicht ab. Sonst stünde der Rückweg
 * zur Verwaltung irgendwann hinter einer Abweisung, und die Ausnahme hielte
 * ihn trotzdem für offen.
 *
 * Und dazu die eine Anzeige, die abweist: die Seite des zweiten Faktors. Sie
 * ändert nichts am Konto, aber sie legt ein Geheimnis in die Sitzung und zeigt
 * einen QR-Code, den in fremder Sicht niemand bestätigen darf.
 *
 * ## Was er nicht kann
 *
 * Eine Route mit `can:` fragt er nicht. Was dort in fremder Sicht geschieht,
 * entscheidet die Policy, und das ist gewollt: Der Wechsel ist dafür da, an
 * den Abonnements des Kunden zu sehen, was der Kunde sieht. Und eine Route,
 * deren Rumpf vor der Abweisung geprüft wird, misst er nur, solange die
 * Prüfung an einem leeren Rumpf scheitert — dann gibt sie 302 und nicht 403,
 * und das fällt hier auf.
 */
final class ImpersonationDoorTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesRouteMiddleware;

    /**
     * Was in fremder Sicht durchgehen muss. Schlüssel wie in {@see RouteGuard}.
     *
     * @return array<string, string>
     */
    private function exemptions(): array
    {
        return [
            'POST impersonation/stop' => 'Der Rückweg zur Verwaltung. Wiese er in fremder Sicht ab, käme der Betreiber dort nur noch durch Abmelden heraus.',
            'POST logout' => 'Abmelden beendet die Sitzung und mit ihr die fremde Sicht. Am Konto des Kunden ändert es nichts.',
        ];
    }

    /**
     * Die schreibenden Routen, die ein angemeldetes Konto ohne Fähigkeit
     * erreicht.
     *
     * @return array<string, Route>
     */
    private function writingRoutes(): array
    {
        $aus = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (array_diff($route->methods(), ['GET', 'HEAD']) === []) {
                continue;
            }

            $sitzung = false;
            $faehigkeit = false;

            foreach ($this->resolvedMiddleware($route) as $mittelschicht) {
                if (! is_string($mittelschicht)) {
                    continue;
                }

                $sitzung = $sitzung || $mittelschicht === 'auth' || str_starts_with($mittelschicht, 'auth:');
                $faehigkeit = $faehigkeit || str_starts_with($mittelschicht, 'can:');
            }

            if ($sitzung && ! $faehigkeit) {
                $aus[RouteGuard::key($route->methods(), $route->uri())] = $route;
            }
        }

        ksort($aus);

        return $aus;
    }

    /**
     * Ein Betreiber in der Sicht eines Kunden, über die echte Route.
     */
    private function impersonate(): Account
    {
        $customer = Customer::factory()->create();
        $kunde = Account::factory()->customer($customer)->create();
        $betreiber = Account::factory()->admin()->create();

        $this->actingAs($betreiber)->post('/customers/'.$customer->id.'/impersonate')->assertRedirect();

        $this->assertTrue(session()->has(Impersonation::SESSION_KEY),
            'Der Wechsel in die fremde Sicht hat nicht stattgefunden — dann misst hier nichts die fremde Sicht.');
        $this->assertSame($kunde->id, Auth::id(), 'Nach dem Wechsel ist nicht der Kunde angemeldet.');

        return $kunde;
    }

    /** @return array<string, mixed> */
    private function snapshot(Account $konto): array
    {
        $frisch = $konto->fresh();

        $this->assertNotNull($frisch);

        return [
            'name' => $frisch->name,
            'email' => $frisch->email,
            'password' => $frisch->password,
            'theme' => $frisch->theme,
            'two_factor_secret' => $frisch->two_factor_secret,
            'two_factor_confirmed_at' => $frisch->two_factor_confirmed_at,
            'api_tokens' => $frisch->apiTokens()->count(),
        ];
    }

    public function test_every_writing_route_without_ability_refuses_in_foreign_view(): void
    {
        $routen = $this->writingRoutes();

        /*
         * **Die Untergrenze zählt mit.** Gezählt am 14. Oktober 2026: neun —
         * Abmelden, der Rückweg, Konto, Passwort, Darstellung, API-Token
         * anlegen und entfernen, zweiter Faktor ein und aus.
         */
        $this->assertGreaterThanOrEqual(9, count($routen),
            'Es werden kaum schreibende Routen ohne Fähigkeit gefunden — dann greift die Frage an den Router '.
            'ins Leere, und jeder Befund hier wäre ein Freispruch.');

        $kunde = $this->impersonate();
        $vorher = $this->snapshot($kunde);
        $offen = [];
        $gefragt = 0;

        foreach ($routen as $schluessel => $route) {
            if (array_key_exists($schluessel, $this->exemptions())) {
                continue;
            }

            $gefragt++;
            $methode = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $pfad = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
            $letzte = (int) AuditEvent::query()->max('id');

            $antwort = $this->call($methode, $pfad);
            $zeile = AuditEvent::query()->where('id', '>', $letzte)->orderBy('id')->first();

            if ($antwort->status() !== 403) {
                $offen[] = sprintf('%s — %d statt 403', $schluessel, $antwort->status());

                continue;
            }

            if ($zeile === null || $zeile->result !== AuditResult::Denied) {
                $offen[] = sprintf('%s — 403 ohne eine Zeile `denied` im Protokoll', $schluessel);
            }
        }

        $this->assertGreaterThanOrEqual(7, $gefragt,
            'Kaum eine Route wurde gefragt — dann stehen zu viele als Ausnahme da.');

        $this->assertSame([], $offen, sprintf(
            'Diese Routen erreicht ein angemeldetes Konto ohne Fähigkeit, sie schreiben, und in fremder Sicht '.
            "weisen sie nicht ab:\n  %s\n\n".
            'In fremder Sicht ist das angemeldete Konto das des Kunden. Die Methode gehört an den Anfang '.
            'mit `$this->refuseWhileImpersonating(…)` (RefusesWhileImpersonating) — oder die Route steht hier '.
            'mit Grund unter den Ausnahmen.',
            implode("\n  ", $offen),
        ));

        $this->assertSame($vorher, $this->snapshot($kunde), 'Das Konto des Kunden hat sich in fremder Sicht verändert.');
    }

    public function test_every_exemption_is_a_writing_route_and_stays_open_in_foreign_view(): void
    {
        $routen = $this->writingRoutes();

        foreach ($this->exemptions() as $schluessel => $grund) {
            $this->assertArrayHasKey($schluessel, $routen, sprintf(
                '„%s" steht als Ausnahme da und ist keine schreibende Route ohne Fähigkeit (mehr). '.
                'Eine Ausnahme, die nichts mehr ausnimmt, deckt beim nächsten Umbau die falsche Route.', $schluessel));
            $this->assertGreaterThan(40, mb_strlen($grund), sprintf('Die Ausnahme „%s" trägt keinen Grund.', $schluessel));

            Auth::logout();
            $this->flushSession();
            $this->impersonate();

            $route = $routen[$schluessel];
            $methode = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $letzte = (int) AuditEvent::query()->max('id');

            $status = $this->call($methode, '/'.ltrim($route->uri(), '/'))->status();
            $abgewiesen = AuditEvent::query()->where('id', '>', $letzte)->where('result', AuditResult::Denied->value)->count();

            $this->assertNotSame(403, $status, sprintf('„%s" weist in fremder Sicht ab, steht aber als Ausnahme da: %s', $schluessel, $grund));
            $this->assertSame(0, $abgewiesen, sprintf('„%s" schreibt in fremder Sicht eine Zeile `denied`.', $schluessel));
        }
    }

    public function test_the_two_factor_page_is_refused_in_foreign_view(): void
    {
        $this->impersonate();
        $letzte = (int) AuditEvent::query()->max('id');

        $this->get('/settings/two-factor')->assertForbidden();

        $schluessel = (string) (new ReflectionClassConstant(TwoFactorSetupController::class, 'PENDING_SECRET'))->getValue();
        $zeile = AuditEvent::query()->where('id', '>', $letzte)->orderBy('id')->first();

        $this->assertFalse(session()->has($schluessel),
            'Die abgewiesene Seite hat trotzdem ein Geheimnis in die Sitzung gelegt.');
        $this->assertNotNull($zeile, 'Die Abweisung hat keine Zeile ins Protokoll geschrieben.');
        $this->assertSame('auth.two_factor.viewed', $zeile->action);
        $this->assertSame(AuditResult::Denied, $zeile->result);
    }

    /**
     * Die Gegenrichtung: Abgewiesen wird die fremde Sicht und nicht die Seite.
     * Ohne diesen Fall bestünde der vorige auch dann, wenn die Seite für jeden
     * 403 gäbe.
     */
    public function test_the_customer_reaches_the_two_factor_page_in_the_own_view(): void
    {
        $customer = Customer::factory()->create();
        $kunde = Account::factory()->customer($customer)->create();

        $antwort = $this->actingAs($kunde)->get('/settings/two-factor');

        $antwort->assertOk();
        $this->assertSame('Auth/TwoFactorSetup', $antwort->viewData('page')['component']);
    }
}
