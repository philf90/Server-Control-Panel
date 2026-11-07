<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\SftpController;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use SrvPanel\Agent\Names;
use Tests\TestCase;

/**
 * Die Seite „SFTP-Zugang" nennt Rechner und Port.
 *
 * **Ein SFTP-Programm fragt zuerst nach dem Rechner**, und bis zum
 * 7. November 2026 nannte keine Kundenseite ihn — die Seite führte Benutzer
 * und Verzeichnis und sonst nichts. Gefunden beim Ausschreiben des Durchlaufs
 * mit einer fremden Person (`docs/145 §0`, Befund 1): Eine Aufgabe dort
 * verlangt, sich mit einem SFTP-Programm zu verbinden, und ohne Rechner geht
 * das nur mit Raten oder Fragen.
 *
 * Der Port kommt aus `sshd -T` — wie er dort gelesen wird, misst
 * `SftpCheckTest`. Hier steht, was die Seite daraus macht.
 */
final class SftpConnectTest extends TestCase
{
    use RefreshDatabase;

    /** Der Rechner aus `Names`, der Port aus dem, was sshd für den Benutzer meldet. */
    public function test_the_host_comes_from_names_and_the_port_from_sshd(): void
    {
        $this->assertSame(
            ['host' => Names::host(), 'port' => '2222'],
            SftpController::connect(['effective' => ['chrootdirectory' => '/var/www/vhosts/beispiel.de', 'port' => '2222']]),
        );
    }

    /**
     * **Antwortet der Agent nicht, steht der Port offen — und nicht geraten.**
     * Eine angenommene 22 wäre auf einem Server mit verlegtem sshd die falsche
     * Auskunft, und die ist schlechter als keine.
     */
    public function test_without_an_answer_the_port_is_left_open(): void
    {
        $this->assertNull(SftpController::connect(['unavailable' => 'Der Agent antwortet nicht.'])['port']);
        $this->assertNull(SftpController::connect(['effective' => ['error' => 'sshd -T scheitert']])['port']);
        $this->assertSame(Names::host(), SftpController::connect([])['host'], 'Den Rechner kennt die Seite auch ohne Agent.');
    }

    /** Und der Kunde bekommt beides auf seiner Seite. */
    public function test_the_customer_gets_both_on_the_page(): void
    {
        $subscription = app(Tenancy::class)->withoutRestriction(
            static fn (): Subscription => Subscription::factory()->create(['system_user' => 'p1000']),
        );
        $customer = Customer::query()->findOrFail($subscription->customer_id);

        $this->actingAs(Account::factory()->customer($customer)->create())
            ->get("/subscriptions/{$subscription->id}/sftp")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Subscriptions/Sftp')
                ->where('connect.host', Names::host())
                ->has('connect.port')
                ->etc());
    }

    /** Die Seite zeigt beide, vor Benutzer und Verzeichnis. */
    public function test_the_page_shows_host_and_port_first(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Subscriptions/Sftp.vue');

        $this->assertMatchesRegularExpression(
            '/<td class="quiet">Rechner<\/td>\s*<td class="right ident">\{\{ props\.connect\.host \}\}<\/td>\s*<\/tr>\s*'.
            '<tr>\s*<td class="quiet">Port<\/td>\s*<td class="right ident">\{\{ props\.connect\.port \?\? \'—\' \}\}<\/td>\s*<\/tr>\s*'.
            '<tr>\s*<td class="quiet">Benutzer<\/td>/u',
            $template,
            'Die SFTP-Seite nennt Rechner und Port nicht mehr vor dem Benutzer.',
        );
    }
}
