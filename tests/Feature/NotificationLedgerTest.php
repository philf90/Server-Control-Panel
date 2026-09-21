<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Mail\QuotaWarning;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Finding;
use App\Models\Subscription;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Notices;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ein Befund wird höchstens **einmal** gemeldet — B5, `docs/129 §8`.
 *
 * ## Gemessen an der Wirkung über zwei Läufe
 *
 * Die Zusage lässt sich an keiner einzelnen Zeile ablesen. Sie steht in zwei
 * Feldern, die verschiedene Klassen pflegen: `first_seen_at` hält
 * {@see FindingLog} über Läufe hinweg still, `notified_at` setzt
 * {@see Notices}. Ein Wächter über eines von beiden sagt nichts über die
 * Zusage.
 *
 * ## Und die Gegenrichtung trägt sie erst
 *
 * „Höchstens einmal" allein erfüllte auch ein Panel, das **nie** meldet. Jeder
 * Fall hier steht deshalb neben seinem Gegenstück: gemeldet und nicht noch
 * einmal, verschwunden und wieder gemeldet, ohne Relay nichts und mit Relay
 * etwas.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 */
final class NotificationLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function notices(): Notices
    {
        return app(Notices::class);
    }

    /** Ein Abonnement mit einem Kundenkonto, das eine Adresse hat. */
    private function abonnement(string $name = 'p1000', bool $mitKonto = true): Subscription
    {
        return app(Tenancy::class)->withoutRestriction(static function () use ($name, $mitKonto): Subscription {
            $kunde = Customer::factory()->create();

            if ($mitKonto) {
                Account::factory()->customer($kunde)->create(['email' => 'kunde@example.org']);
            }

            return Subscription::factory()->create(['name' => $name, 'customer_id' => $kunde->id]);
        });
    }

    private function relais(): void
    {
        app(Settings::class)->saveMail(new MailSettings(
            host: 'relay.example.org',
            port: 587,
            encryption: 'tls',
            username: '',
            password: '',
            from_address: 'panel@example.org',
            from_name: 'SrvPanel',
        ));
    }

    /**
     * Einen Lauf fahren — über {@see FindingLog} und nicht mit einem
     * `Finding::create()`.
     *
     * Der Prüfkörper geht damit durch dieselbe Stelle wie der Nachtlauf. Ein
     * von Hand gesetztes `first_seen_at` prüfte, ob dieser Test rechnen kann.
     *
     * @param  list<string>  $reasons
     */
    private function lauf(string $subject, array $reasons, Carbon $at): void
    {
        app(FindingLog::class)->replace(
            FindingCheck::QuotaExceeded,
            array_map(static fn (string $reason): array => [
                'subject' => $subject,
                'reason' => $reason,
                'detail' => '1.024 MB von 500 MB',
            ], $reasons),
            $at,
        );
    }

    public function test_a_fresh_overrun_is_not_reported_yet(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement();

        $jetzt = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $jetzt);

        $bilanz = $this->notices()->send($jetzt);

        self::assertSame(0, $bilanz['sent']);
        Mail::assertNothingSent();
        self::assertNull(Finding::query()->firstOrFail()->notified_at,
            'Was nicht verschickt wurde, darf nicht als gemeldet dastehen — sonst wäre die Zeile für '
            .'immer stumm.');
    }

    public function test_after_the_hold_it_is_reported_once(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        self::assertSame(1, $this->notices()->send($zweite)['sent']);
        Mail::assertSent(QuotaWarning::class, 1);
        self::assertNotNull(Finding::query()->firstOrFail()->notified_at);
    }

    /** Und der Lauf danach meldet nichts mehr. */
    public function test_a_later_run_does_not_report_again(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);
        $this->notices()->send($zweite);

        $dritte = $zweite->copy()->addDay();
        $this->lauf('p1000', ['disk_over'], $dritte);

        self::assertSame(0, $this->notices()->send($dritte)['sent']);
        Mail::assertSent(QuotaWarning::class, 1);
    }

    /**
     * Die Gegenrichtung: Was verschwindet und wiederkommt, meldet wieder.
     *
     * Getragen wird das von `FindingLog::forgetMissing()` — was ein Lauf nicht
     * mehr nennt, wird gelöscht, und mit der Zeile geht die Erinnerung an die
     * Zustellung. Ohne diese Richtung erfüllte auch ein Panel die Zusage, das
     * nach der ersten Meldung für immer schweigt.
     */
    public function test_a_finding_that_returns_reports_again(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);
        $this->notices()->send($zweite);

        // Behoben: Der Lauf nennt den Befund nicht mehr.
        $behoben = $zweite->copy()->addDay();
        $this->lauf('p1000', [], $behoben);
        self::assertSame(0, Finding::query()->count());

        // Und wieder da.
        $wieder = $behoben->copy()->addDay();
        $this->lauf('p1000', ['disk_over'], $wieder);
        self::assertSame(0, $this->notices()->send($wieder)['sent'],
            'Der Zustand steht erst seit diesem Lauf — die Haltezeit gilt auch beim zweiten Mal.');

        $spaeter = $wieder->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $spaeter);

        self::assertSame(1, $this->notices()->send($spaeter)['sent']);
        Mail::assertSent(QuotaWarning::class, 2);
    }

    /**
     * Zwei Überschreitungen eines Abonnements sind **eine** Nachricht.
     *
     * Das Abnahmekriterium sagt „genau eine Mail", und zwei Mails in derselben
     * Minute sind für den Empfänger genau das, wogegen es geschrieben ist.
     */
    public function test_two_overruns_of_one_subscription_are_one_mail(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over', 'traffic_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over', 'traffic_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(1, $bilanz['sent']);
        self::assertSame(2, $bilanz['findings']);
        Mail::assertSent(QuotaWarning::class, 1);
        self::assertSame(0, Finding::query()->whereNull('notified_at')->count(),
            'Beide Zeilen sind gemeldet — sonst schickte der nächste Lauf eine zweite Mail über '
            .'dieselbe Nachricht.');
    }

    /** Ohne eingetragenes Relay geht nichts hinaus und nichts wird vermerkt. */
    public function test_without_a_relay_nothing_is_marked(): void
    {
        Mail::fake();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(0, $bilanz['sent']);
        self::assertSame(1, $bilanz['skipped'],
            'Die Zahl der stehengebliebenen Meldungen ist die Auskunft — ohne sie sähe „kein Relay" '
            .'aus wie „nichts zu melden".');
        self::assertNull(Finding::query()->firstOrFail()->notified_at,
            'Ein `notified_at` ohne Zustellung nähme der Zeile für immer ihre Fälligkeit.');
    }

    /** Ein Kunde ohne Konto mit Adresse bekommt nichts — und bleibt fällig. */
    public function test_a_subscription_without_a_recipient_stays_due(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement(mitKonto: false);

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(0, $bilanz['sent']);
        self::assertSame(1, $bilanz['without_recipient']);
        Mail::assertNothingSent();
        self::assertNull(Finding::query()->firstOrFail()->notified_at);
    }

    /**
     * „Zuletzt erfolgreich zugestellt" entsteht nur bei einer Zustellung.
     *
     * Stünde dort ein Zeitpunkt, an dem nichts ankam, wäre der Satz falsch,
     * während er richtig aussieht.
     */
    public function test_the_channel_records_only_a_delivery(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        self::assertSame(0, $this->notices()->send($erste)['sent']);
        self::assertNull(app(Settings::class)->noticeSentAt(Notices::CHANNEL),
            'Ein Lauf ohne Zustellung schreibt nichts.');

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);
        $this->notices()->send($zweite);

        self::assertSame(
            $zweite->toDateTimeString(),
            app(Settings::class)->noticeSentAt(Notices::CHANNEL),
            'Und einer mit Zustellung schreibt den Zeitpunkt des Laufs.',
        );
    }

    /**
     * Ein „nicht beurteilt" ist keine Meldung an den Kunden.
     *
     * `traffic_unknown` sagt, dass dem Server die Zeitzone fehlt. Das ist ein
     * Problem des Betreibers; eine Mail darüber an den Kunden wäre eine
     * Meldung über etwas, das er nicht ändern kann.
     */
    public function test_an_unjudged_state_is_not_mailed(): void
    {
        Mail::fake();
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['traffic_unknown'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['traffic_unknown'], $zweite);

        self::assertSame(0, $this->notices()->send($zweite)['sent']);
        Mail::assertNothingSent();
    }
}
