<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\FindingCheck;
use App\Mail\CustomerNotice;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FindingNotification;
use App\Models\Subscription;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\MailChannel;
use App\Support\Notify\Notices;
use App\Support\Notify\NotifyTarget;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * An wen die Mail über ein Abonnement geht — B5, `docs/141 §0` Befund 4.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Bis zum 5. Oktober 2026 ging die Kundenmail an jedes aktive Konto des
 * Kunden, alle in einer Zeile `To`. Ein Zusatzbenutzer, dem das Abonnement gar
 * nicht zugewiesen ist, las damit von dessen Kontingenten, und jeder Empfänger
 * las die Adressen der anderen. Entschieden hat der Betreiber am selben Tag:
 * **wer das Abonnement sieht, je eine Mail.**
 *
 * {@see NoticeAudienceTest} misst, dass die Mail beim Kunden ankommt und nicht
 * beim Betreiber. Wer **unter** den Konten eines Kunden sie bekommt, misst
 * keiner seiner Fälle — dort hat jeder Kunde ein einziges Konto.
 *
 * ## Und was eine Zustellung an zwei Empfänger bucht
 *
 * Angekommen ist sie, wenn sie bei einem angekommen ist. Eine Adresse, die das
 * Relay dauerhaft abweist, liesse den Befund sonst fällig, und jeder andere
 * Empfänger bekäme dieselbe Mail jede Nacht wieder. Gemessen wird das mit einem
 * echten Transport, der eine Adresse abweist — `Mail::fake()` kann nicht
 * scheitern.
 */
final class QuotaRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ohne Doppel fragte der Webhook-Kanal den echten Agenten.
        $this->app?->instance(NotifyTarget::class, ScriptedNotifyTarget::empty());

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
     * Ein Kunde mit vier Konten und zwei Abonnements.
     *
     * Das Kundenkonto sieht beide, der eine Zusatzbenutzer nur `p1000`, der
     * andere nur `p1001`, und das gesperrte Konto sieht nichts mehr.
     */
    private function bestand(): void
    {
        app(Tenancy::class)->withoutRestriction(static function (): void {
            $kunde = Customer::factory()->create();
            $abo = Subscription::factory()->create(['name' => 'p1000', 'customer_id' => $kunde->id]);
            $anderes = Subscription::factory()->create(['name' => 'p1001', 'customer_id' => $kunde->id]);

            Account::factory()->customer($kunde)->create(['email' => 'kunde@example.org']);
            Account::factory()->customer($kunde)->create(['email' => 'gesperrt@example.org', 'status' => AccountStatus::Disabled->value]);

            $dieses = Account::factory()->additional($kunde)->create(['email' => 'zusatz-dieses@example.org']);
            $dieses->assignedSubscriptions()->attach($abo->id, ['permissions' => '[]', 'domain_ids' => '[]']);

            $fremd = Account::factory()->additional($kunde)->create(['email' => 'zusatz-anderes@example.org']);
            $fremd->assignedSubscriptions()->attach($anderes->id, ['permissions' => '[]', 'domain_ids' => '[]']);
        });
    }

    /**
     * Zwei Läufe im Abstand der Haltezeit und danach der Versand.
     *
     * @return array<string, array{sent: int, resolved: int, findings: int, without_recipient: int, failed: int, skipped: int}>
     */
    private function zweiNaechte(): array
    {
        $erste = Carbon::parse('2026-10-05 03:00:00');
        $zeilen = [['subject' => 'p1000', 'reason' => 'disk_near_limit', 'detail' => '19 MB von 20 MB (95,0 %)']];

        app(FindingLog::class)->replace(FindingCheck::QuotaExceeded, $zeilen, $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        app(FindingLog::class)->replace(FindingCheck::QuotaExceeded, $zeilen, $zweite);

        return app(Notices::class)->send($zweite);
    }

    /**
     * Den Transport des Relays gegen einen tauschen, der diese Adressen abweist.
     *
     * Das Relay kommt beim ersten Auflösen des Mailers aus den Einstellungen;
     * danach wird nur der Transport ersetzt, und der Mailer neu gebaut.
     *
     * @param  list<string>  $abweisen
     * @return object{angekommen: list<string>}
     */
    private function relaisDasAbweist(array $abweisen): object
    {
        $transport = new class($abweisen) extends AbstractTransport
        {
            /** @var list<string> */
            public array $angekommen = [];

            /** @param list<string> $abweisen */
            public function __construct(private readonly array $abweisen)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                foreach ($message->getEnvelope()->getRecipients() as $empfaenger) {
                    if (in_array($empfaenger->getAddress(), $this->abweisen, true)) {
                        throw new TransportException('550 5.1.1 Empfänger abgewiesen');
                    }

                    $this->angekommen[] = $empfaenger->getAddress();
                }
            }

            public function __toString(): string
            {
                return 'abweisend://';
            }
        };

        app('mail.manager');
        Mail::extend('abweisend', static fn (): AbstractTransport => $transport);
        config(['mail.mailers.smtp.transport' => 'abweisend']);
        app('mail.manager')->purge('smtp');

        return $transport;
    }

    public function test_who_sees_the_subscription_gets_the_mail_and_nobody_else(): void
    {
        Mail::fake();
        $this->bestand();

        $bilanz = $this->zweiNaechte();

        self::assertSame(1, $bilanz['mail']['sent']);

        foreach (['kunde@example.org', 'zusatz-dieses@example.org'] as $sieht) {
            Mail::assertSent(CustomerNotice::class, static fn (CustomerNotice $m): bool => $m->hasTo($sieht));
        }

        foreach (['zusatz-anderes@example.org', 'gesperrt@example.org'] as $siehtNicht) {
            Mail::assertNotSent(CustomerNotice::class, static fn (CustomerNotice $m): bool => $m->hasTo($siehtNicht));
        }
    }

    /** Jeder bekommt seine eigene Mail — keine Adressen nebeneinander. */
    public function test_every_recipient_gets_a_mail_of_their_own(): void
    {
        Mail::fake();
        $this->bestand();

        $this->zweiNaechte();

        $mails = Mail::sent(CustomerNotice::class);

        self::assertCount(2, $mails, 'Zwei Konten sehen das Abonnement — zwei Mails.');

        foreach ($mails as $mail) {
            self::assertCount(1, $mail->to, 'Eine Mail mit mehreren Empfängern zeigt jedem die Adressen der anderen.');
        }
    }

    /**
     * Kommt sie bei einem an, ist sie zugestellt — und kommt nicht wieder.
     *
     * Der andere Empfänger wird abgewiesen. Bliebe der Befund deshalb fällig,
     * bekäme der erste dieselbe Mail jede Nacht.
     */
    public function test_one_arrival_books_the_finding(): void
    {
        $this->bestand();
        $relais = $this->relaisDasAbweist(['zusatz-dieses@example.org']);

        $bilanz = $this->zweiNaechte();

        self::assertSame(['kunde@example.org'], $relais->angekommen);
        self::assertSame(1, $bilanz['mail']['sent']);
        self::assertSame(0, $bilanz['mail']['failed']);
        self::assertSame(1, FindingNotification::query()->where('channel', MailChannel::CHANNEL)->count());

        $spaeter = Carbon::parse('2026-10-07 03:00:00');
        app(FindingLog::class)->replace(FindingCheck::QuotaExceeded, [
            ['subject' => 'p1000', 'reason' => 'disk_near_limit', 'detail' => '19 MB von 20 MB (95,0 %)'],
        ], $spaeter);

        self::assertSame(0, app(Notices::class)->send($spaeter)['mail']['sent'], 'Gebucht ist gebucht — keine zweite Mail an den, der sie hat.');
        self::assertSame(['kunde@example.org'], $relais->angekommen);
    }

    /** Und kommt sie bei keinem an, bleibt sie fällig. */
    public function test_no_arrival_leaves_it_due(): void
    {
        $this->bestand();
        $relais = $this->relaisDasAbweist(['kunde@example.org', 'zusatz-dieses@example.org']);

        $bilanz = $this->zweiNaechte();

        self::assertSame([], $relais->angekommen);
        self::assertSame(0, $bilanz['mail']['sent']);
        self::assertSame(1, $bilanz['mail']['failed']);
        self::assertSame(0, FindingNotification::query()->where('channel', MailChannel::CHANNEL)->count(),
            'Was bei niemandem ankam, darf nicht als gemeldet dastehen.');
    }
}
