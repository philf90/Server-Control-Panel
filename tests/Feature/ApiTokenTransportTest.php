<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Die Marke reist im Kopf `Authorization` und **nie** in der Adresse (B7).
 *
 * ## Warum das gemessen und nicht gemeint ist
 *
 * nginx schreibt `"$request"` ins Zugriffsprotokoll — die Anfragezeile
 * **mitsamt Abfrageteil**. Gemessen am 21. September 2026 gegen echtes
 * nginx 1.24.0 mit dem Format aus `SiteTemplate::httpConfig()`: Token im
 * Abfrageteil **1** Treffer im Protokoll, Token im Kopf **0**
 * (`docs/130` A7). Die Datei gehört `0640 <benutzer> adm`, bleibt vierzehn
 * Tage liegen und wird von diesem Panel selbst angezeigt.
 *
 * > **Ein Geheimnis, das in der Adresse reist, steht in einer Datei, die das
 * > Panel dem Betreiber vorliest.**
 *
 * ## Gemessen an der Wirkung **und** am Quelltext
 *
 * Die Wirkung allein reicht nicht: Dieselbe Marke im Abfrageteil gibt heute
 * 401, weil die Wache dort nicht hinsieht — und sie gäbe auch dann 401, wenn
 * jemand einen zweiten Leser einbaute, der einen *anderen* Parameternamen
 * liest. Deshalb steht daneben, dass diese Wache genau **eine** Quelle
 * kennt.
 */
final class ApiTokenTransportTest extends TestCase
{
    use RefreshDatabase;
    use WithoutPhpComments;

    /** @return array{kopf: array<string, string>, plain: string} */
    private function marke(Account $konto): array
    {
        $marke = ApiToken::mint($konto, 'Abrechnung');

        return [
            'kopf' => ['Authorization' => 'Bearer '.$marke['plain']],
            'plain' => $marke['plain'],
        ];
    }

    private function kunde(): Account
    {
        $kunde = Customer::factory()->create();
        Subscription::factory()->create(['customer_id' => $kunde->id]);

        return Account::factory()->customer($kunde)->create();
    }

    public function test_the_header_carries_the_token(): void
    {
        ['kopf' => $kopf] = $this->marke($this->kunde());

        $this->withHeaders($kopf)->getJson('/api/v1/me')->assertOk();
    }

    public function test_the_query_string_does_not(): void
    {
        ['plain' => $plain] = $this->marke($this->kunde());

        $this->getJson('/api/v1/me?token='.$plain)->assertUnauthorized();
    }

    public function test_no_token_is_refused(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->kunde();

        $this->withHeaders(['Authorization' => 'Bearer '.ApiToken::PREFIX.str_repeat('0', 48)])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    /**
     * Ein Adminkonto kommt nicht durch — und ein Kunde schon.
     *
     * Die Gegenprobe ist der Punkt: Ohne sie wäre dieser Fall auch dann grün,
     * wenn **niemand** durchkäme.
     */
    public function test_an_admin_token_is_refused(): void
    {
        ['kopf' => $adminKopf] = $this->marke(Account::factory()->admin()->create());

        $this->withHeaders($adminKopf)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_disabled_account_is_refused(): void
    {
        $konto = $this->kunde();
        $konto->forceFill(['status' => AccountStatus::Disabled])->save();

        ['kopf' => $kopf] = $this->marke($konto);

        $this->withHeaders($kopf)->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * Die Wache kennt genau **eine** Quelle.
     *
     * Kommentare werden abgestreift, bevor gesucht wird: Der Absatz, der
     * erklärt, warum der Abfrageteil nicht gelesen wird, schreibt seine Namen
     * wörtlich hin.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
     * > irgendwo steht — und ein Kommentar, der die entfernte Zeile zitiert,
     * > stellt sie für ihn wieder her.**
     */
    public function test_the_guard_reads_only_the_header(): void
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/AuthenticateToken.php'),
        );

        self::assertStringContainsString('bearerToken()', $quelle,
            'Die Wache liest den Kopf nicht mehr — dann misst dieser Fall nichts.');

        foreach (['->query(', '->input(', '->get(', '->cookie(', '$_GET'] as $verboten) {
            self::assertStringNotContainsString($verboten, $quelle, sprintf(
                'Die Wache liest die Marke über `%s`. Ein Token, das so reisen kann, steht im '.
                'Zugriffsprotokoll (docs/130 A7).',
                $verboten,
            ));
        }
    }
}
