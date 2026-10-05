<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Models\Account;
use App\Models\Finding;
use App\Models\FindingNotification;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Channels;
use App\Support\Notify\NotifyTarget;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

/**
 * Die Seite der Bestandsdiagnose (A10 Schritt 7).
 *
 * ## Die Rollenteilung ist der Kern
 *
 * `docs/98 §9` Frage 5, mit **b** entschieden: Der Administrator sieht die
 * Liste, der Betreiber zusätzlich den Wortlaut der Werkzeuge. Der trägt bei
 * php-fpm Poolnamen und Pfade, bei nginx Zertifikatspfade und in einem
 * verwalteten Bereich die Regeln fremder Kunden.
 *
 * **Gemessen wird am Payload und nicht an der Seite.** Ein `v-if` im Browser
 * verbärge den Text und schickte ihn trotzdem.
 *
 * > **Was der Betrachter nicht sehen darf, wird nicht ausgeblendet, sondern
 * > nicht geschickt.**
 *
 * ## Und der Unterschied zwischen „nichts gefunden" und „nie gemessen"
 *
 * Beide Male ist die Liste leer. Nur eine der beiden Bedeutungen ist eine
 * Entwarnung — der Fehler aus `docs/44`, eine Ebene höher.
 */
final class DiagnosePageTest extends TestCase
{
    use RefreshDatabase;

    private const WORTLAUT = '2026/09/02 03:00:11 [emerg] 8896#8896: unexpected end of file in /etc/nginx/srvpanel.d/kunde.conf:6';

    /**
     * Die Zustände der Factory und keine eigenen Werte.
     *
     * **Der erste Wurf setzte `type` und `role` von Hand** — und bekam einen
     * 302 statt der Seite: Ein Adminkonto ohne bestätigten zweiten Faktor wird
     * dorthin geschickt, wo er eingerichtet wird. `admin()` und
     * `administrator()` tragen ihn, weil jedes echte Adminkonto ihn trägt.
     *
     * > **Ein Testkonto, das man selbst zusammensetzt, ist eines, das es so
     * > nicht gibt — und der Umstand, der fehlt, fällt als Weiterleitung auf
     * > und nicht als Rechteproblem.**
     */
    private function betreiber(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function administrator(): Account
    {
        return Account::factory()->administrator()->create();
    }

    private function befund(): void
    {
        (new FindingLog)->replace(FindingCheck::WebConfig, [
            ['subject' => '/etc/nginx/nginx.conf', 'reason' => 'invalid', 'detail' => self::WORTLAUT],
        ], Carbon::parse('2026-09-02 03:00:00'));
    }

    public function test_the_operator_sees_the_verbatim_output(): void
    {
        $this->befund();

        $this->actingAs($this->betreiber())
            ->get('/diagnose')
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->component('Diagnose/Index')
                ->where('verbatim', true)
                ->where('findings.0.detail', self::WORTLAUT));
    }

    /**
     * Der Administrator sieht den Befund und **nicht** den Wortlaut.
     *
     * Geprüft wird, dass der Schlüssel gar nicht da ist — nicht, dass er leer
     * ist. Ein leerer Schlüssel wäre derselbe Fehler mit einem Schritt mehr.
     */
    public function test_the_administrator_sees_the_finding_without_the_verbatim_output(): void
    {
        $this->befund();

        $this->actingAs($this->administrator())
            ->get('/diagnose')
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->component('Diagnose/Index')
                ->where('verbatim', false)
                ->where('findings.0.subject', '/etc/nginx/nginx.conf')
                ->where('findings.0.sentence', FindingCheck::WebConfig->sentence('invalid'))
                ->missing('findings.0.detail'));
    }

    /** Und der Wortlaut steht auch nirgends sonst in der Antwort. */
    public function test_the_verbatim_output_is_nowhere_in_the_administrators_answer(): void
    {
        $this->befund();

        $antwort = $this->actingAs($this->administrator())->get('/diagnose');

        $this->assertStringNotContainsString('8896#8896', $antwort->getContent() ?: '', 'Der Wortlaut des Werkzeugs steht im Payload — dann hilft kein v-if.');
    }

    /** Ein Kunde kommt gar nicht erst herein. */
    public function test_a_customer_does_not_reach_the_page(): void
    {
        $this->actingAs(Account::factory()->customer()->create())
            ->get('/diagnose')
            ->assertForbidden();
    }

    /**
     * „Nichts gefunden" und „nie gemessen" sehen verschieden aus.
     *
     * Punkt 1 des Abnahmekriteriums verlangt, dass die Seite sagt, wann zuletzt
     * gemessen wurde — und zwar für den Fall, dass sie sonst nichts zu sagen
     * hat.
     */
    public function test_a_run_without_findings_still_says_when_it_ran(): void
    {
        $this->actingAs($this->betreiber())
            ->get('/diagnose')
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite->where('findings', [])->where('ran_at', null));

        app(Settings::class)->saveDiagnoseRun('2026-09-02 03:00:00');

        $this->actingAs($this->betreiber())
            ->get('/diagnose')
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->where('findings', [])
                ->where('ran_at', fn (mixed $wert): bool => is_string($wert) && $wert !== ''));
    }

    /**
     * Die Reihenfolge ist die Schwere.
     *
     * Wer eine Seite mit dreissig Zeilen öffnet, liest die ersten. Ein `warn`
     * über einem `fail` verschöbe den Befund, für den es die Seite gibt.
     */
    public function test_the_worst_finding_stands_first(): void
    {
        $log = new FindingLog;
        $at = Carbon::parse('2026-09-02 03:00:00');

        $log->replace(FindingCheck::OrphanRow, [['subject' => 'alt.invalid', 'reason' => 'certificate']], $at);
        $log->replace(FindingCheck::UnitState, [['subject' => 'nginx.service', 'reason' => 'inactive']], $at);
        $log->unreachable(FindingCheck::QuotaState, ['/var/www/vhosts'], $at, 'kein Agent');

        $this->actingAs($this->betreiber())
            ->get('/diagnose')
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->where('findings.0.state', 'fail')
                ->where('findings.1.state', 'unknown')
                ->where('findings.2.state', 'warn'));
    }

    /**
     * **Ob und wann ein Befund gemeldet ist, steht bei ihm** (`docs/141 §0`
     * Befund 5).
     *
     * Bis zum 5. Oktober 2026 sagte die Seite dazu nichts, und
     * „zuletzt erfolgreich zugestellt" je Kanal sagte nicht, welche Meldung das
     * war. Gemessen werden alle vier Zustände durch die Tür: gemeldet, fällig
     * und nicht zugestellt, noch nicht fällig, nie gemeldet.
     *
     * **Und die Seite fragt dabei den Agenten nicht.** Das Doppel des
     * Meldeziels wirft beim ersten Fragen; ob das Ziel eingerichtet ist, weiss
     * nur der Agent, und `open` nennt deshalb den Mailversand und nie das
     * Meldeziel.
     */
    public function test_each_finding_says_whether_and_when_it_was_reported(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $this->app?->instance(NotifyTarget::class, new class implements NotifyTarget
        {
            public function describe(): ?array
            {
                throw new LogicException('Die Seite fragt den Agenten.');
            }

            public function reachable(): bool
            {
                throw new LogicException('Die Seite fragt den Agenten.');
            }

            public function store(string $url, ?string $secret, string $provider, array $config = []): void {}

            public function forget(): bool
            {
                return false;
            }

            public function send(array $event): void {}
        });

        app(Settings::class)->saveMail(new MailSettings(
            host: 'relay.example.org', port: 587, encryption: 'tls', username: '', password: '',
            from_address: 'panel@example.org', from_name: 'SrvPanel',
        ));

        $log = new FindingLog;
        $log->replace(FindingCheck::QuotaExceeded, [
            ['subject' => 'gemeldet.invalid', 'reason' => 'disk_near_limit'],
            ['subject' => 'offen.invalid', 'reason' => 'databases_over'],
            ['subject' => 'unbeurteilt.invalid', 'reason' => 'traffic_unknown'],
        ], Carbon::parse('2026-10-05 03:00:00', 'UTC'));
        $log->replace(FindingCheck::UnitState, [['subject' => 'nginx.service', 'reason' => 'inactive']], Carbon::parse('2026-10-06 03:00:00', 'UTC'));

        $mail = app(Channels::class)->byKey('mail');
        self::assertNotNull($mail);
        FindingNotification::record(Finding::query()->where('subject', 'gemeldet.invalid')->firstOrFail(), $mail, Carbon::parse('2026-10-06 03:00:12', 'UTC'));

        /** @var list<array{subject: string, notice: array{sent: list<array{channel: string, at: string}>, open: list<string>, due_at: string|null, due: bool}}> $zeilen */
        $zeilen = $this->actingAs($this->betreiber())->get('/diagnose')->assertOk()->viewData('page')['props']['findings'];

        $je = array_column($zeilen, 'notice', 'subject');

        self::assertSame([['channel' => 'mail', 'at' => '2026-10-06 03:00:12']], $je['gemeldet.invalid']['sent']);
        self::assertSame([], $je['gemeldet.invalid']['open'], 'Gemeldet ist gemeldet, und vom Meldeziel weiss die Seite nichts.');

        self::assertSame([], $je['offen.invalid']['sent']);
        self::assertSame(['mail'], $je['offen.invalid']['open']);
        self::assertTrue($je['offen.invalid']['due']);
        self::assertSame('2026-10-05 23:00:00', $je['offen.invalid']['due_at'], 'Fällig nach zwanzig Stunden — dieselbe Regel, nach der der Lauf meldet.');

        self::assertFalse($je['nginx.service']['due'], 'Neun Stunden alt — noch nicht fällig.');
        self::assertSame('2026-10-06 23:00:00', $je['nginx.service']['due_at']);

        self::assertNull($je['unbeurteilt.invalid']['due_at'], 'Nicht beurteilt wird nie gemeldet.');
        self::assertSame([], $je['unbeurteilt.invalid']['open']);

        Carbon::setTestNow();
    }

    /** Der Zeitpunkt eines Laufs ist der der Befunde und nicht der des Schreibens. */
    public function test_the_run_records_the_moment_its_findings_carry(): void
    {
        app(Settings::class)->saveDiagnoseRun('2026-09-02 03:00:00');

        $this->assertSame('2026-09-02 03:00:00', app(Settings::class)->diagnoseRunAt());
    }
}
