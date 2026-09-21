<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Mail\QuotaWarning;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Finding;
use App\Models\FindingNotification;
use App\Models\Subscription;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Channel;
use App\Support\Notify\MailChannel;
use App\Support\Notify\Notices;
use App\Support\Notify\NotifyTarget;
use App\Support\Notify\WebhookChannel;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * Ein Befund wird höchstens **einmal** gemeldet — B5, `docs/129 §8`.
 *
 * ## Gemessen an der Wirkung über zwei Läufe
 *
 * Die Zusage lässt sich an keiner einzelnen Zeile ablesen. Sie steht an zwei
 * Orten, die verschiedene Klassen pflegen: `first_seen_at` hält
 * {@see FindingLog} über Läufe hinweg still, die Zeile in
 * `finding_notifications` schreibt {@see Notices}. Ein Wächter über eines von
 * beiden sagt nichts über die Zusage.
 *
 * ## Und seit B1 je Kanal
 *
 * Es gibt zwei ({@see MailChannel}, {@see WebhookChannel}), und sie buchen
 * getrennt. Der Fall, für den die Tabelle überhaupt entstanden ist, ist
 * {@see self::test_each_channel_books_only_for_itself()}: Kommt der eine nicht
 * durch und der andere schon, bleibt die Meldung für den einen fällig und für
 * den anderen nicht.
 *
 * > **Ein Kanal, der für einen anderen mitbucht, verliert dessen Meldung — und
 * > zwar dauerhaft.**
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

    /**
     * Das Meldeziel aus Papier einsetzen.
     *
     * **Ohne es fragt der Webhook-Kanal den echten Agenten**, und der antwortet
     * im Prüfstand nicht. Das wäre nicht falsch — er gälte als nicht
     * eingerichtet —, aber es wäre auch nicht gemessen: Ein Kanal, der aus
     * Versehen schweigt, sieht aus wie einer, der zu Recht schweigt.
     */
    private function ziel(ScriptedNotifyTarget $target = new ScriptedNotifyTarget): ScriptedNotifyTarget
    {
        $this->app?->instance(NotifyTarget::class, $target);

        return $target;
    }

    /** Wie oft dieser Befund über diesen Kanal gebucht ist. */
    private function gebucht(Channel|string $channel): int
    {
        $key = $channel instanceof Channel ? $channel->key() : $channel;

        return FindingNotification::query()->where('channel', $key)->count();
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
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->relais();
        $this->abonnement();

        $jetzt = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $jetzt);

        $bilanz = $this->notices()->send($jetzt);

        self::assertSame(0, $bilanz['mail']['sent']);
        Mail::assertNothingSent();
        self::assertSame(0, $this->gebucht(MailChannel::CHANNEL),
            'Was nicht verschickt wurde, darf nicht als gemeldet dastehen — sonst wäre die Zeile für '
            .'immer stumm.');
    }

    public function test_after_the_hold_it_is_reported_once(): void
    {
        Mail::fake();
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        self::assertSame(1, $this->notices()->send($zweite)['mail']['sent']);
        Mail::assertSent(QuotaWarning::class, 1);
        self::assertSame(1, $this->gebucht(MailChannel::CHANNEL));
    }

    /** Und der Lauf danach meldet nichts mehr. */
    public function test_a_later_run_does_not_report_again(): void
    {
        Mail::fake();
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);
        $this->notices()->send($zweite);

        $dritte = $zweite->copy()->addDay();
        $this->lauf('p1000', ['disk_over'], $dritte);

        self::assertSame(0, $this->notices()->send($dritte)['mail']['sent']);
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
        $this->ziel(ScriptedNotifyTarget::empty());
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
        self::assertSame(0, $this->notices()->send($wieder)['mail']['sent'],
            'Der Zustand steht erst seit diesem Lauf — die Haltezeit gilt auch beim zweiten Mal.');

        $spaeter = $wieder->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $spaeter);

        self::assertSame(1, $this->notices()->send($spaeter)['mail']['sent']);
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
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over', 'traffic_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over', 'traffic_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(1, $bilanz['mail']['sent']);
        self::assertSame(2, $bilanz['mail']['findings']);
        Mail::assertSent(QuotaWarning::class, 1);
        self::assertSame(2, $this->gebucht(MailChannel::CHANNEL),
            'Beide Zeilen sind gemeldet — sonst schickte der nächste Lauf eine zweite Mail über '
            .'dieselbe Nachricht.');
    }

    /** Ohne eingetragenes Relay geht nichts hinaus und nichts wird vermerkt. */
    public function test_without_a_relay_nothing_is_marked(): void
    {
        Mail::fake();
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(0, $bilanz['mail']['sent']);
        self::assertSame(1, $bilanz['mail']['skipped'],
            'Die Zahl der stehengebliebenen Meldungen ist die Auskunft — ohne sie sähe „kein Relay" '
            .'aus wie „nichts zu melden".');
        self::assertSame(0, $this->gebucht(MailChannel::CHANNEL),
            'Eine Buchung ohne Zustellung nähme der Zeile für immer ihre Fälligkeit.');
    }

    /** Ein Kunde ohne Konto mit Adresse bekommt nichts — und bleibt fällig. */
    public function test_a_subscription_without_a_recipient_stays_due(): void
    {
        Mail::fake();
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->relais();
        $this->abonnement(mitKonto: false);

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(0, $bilanz['mail']['sent']);
        self::assertSame(1, $bilanz['mail']['without_recipient']);
        Mail::assertNothingSent();
        self::assertSame(0, $this->gebucht(MailChannel::CHANNEL));
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
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        self::assertSame(0, $this->notices()->send($erste)['mail']['sent']);
        self::assertNull(app(Settings::class)->noticeSentAt(MailChannel::CHANNEL),
            'Ein Lauf ohne Zustellung schreibt nichts.');

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);
        $this->notices()->send($zweite);

        self::assertSame(
            $zweite->toDateTimeString(),
            app(Settings::class)->noticeSentAt(MailChannel::CHANNEL),
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
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['traffic_unknown'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['traffic_unknown'], $zweite);

        self::assertSame(0, $this->notices()->send($zweite)['mail']['sent']);
        Mail::assertNothingSent();
    }

    /**
     * Derselbe Befund geht über **beide** Kanäle.
     *
     * Ohne diesen Fall erfüllte auch ein Panel die Zusage, das den zweiten
     * Kanal gar nicht bedient — und er ist die Gegenrichtung zu dem darunter:
     * Erst wenn belegt ist, dass beide zustellen, sagt „der eine bleibt fällig"
     * etwas.
     */
    public function test_one_finding_goes_over_both_channels(): void
    {
        Mail::fake();
        $ziel = $this->ziel();
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(1, $bilanz['mail']['sent']);
        self::assertSame(1, $bilanz['webhook']['sent']);
        self::assertSame(1, $this->gebucht(MailChannel::CHANNEL));
        self::assertSame(1, $this->gebucht(WebhookChannel::CHANNEL));

        self::assertCount(1, $ziel->sent, 'Eine Meldung je Gegenstand und nicht je Befund.');
        self::assertSame('findings', $ziel->sent[0]['kind']);
        self::assertSame('p1000', $ziel->sent[0]['subject']);

        // Und der nächste Lauf meldet über keinen der beiden noch einmal.
        $dritte = $zweite->copy()->addDay();
        $this->lauf('p1000', ['disk_over'], $dritte);
        $danach = $this->notices()->send($dritte);

        self::assertSame(0, $danach['mail']['sent']);
        self::assertSame(0, $danach['webhook']['sent']);
    }

    /**
     * **Der Fall, für den es die Tabelle gibt.**
     *
     * Bis zum 24. September 2026 war die Buchung eine Spalte `notified_at` an
     * `findings`. Mit zwei Kanälen trägt sie nicht mehr, und es gibt genau zwei
     * Regeln, die sie haben könnte — beide falsch:
     *
     * - *Gesetzt, wenn **einer** zustellte* — dann wäre die Meldung des anderen
     *   dauerhaft fort.
     * - *Gesetzt, wenn **alle** zustellten* — dann hielte ein kaputter Kanal
     *   den anderen fest, und der meldete jede Nacht dasselbe.
     *
     * > **Ein Kanal, der für einen anderen mitbucht, verliert dessen Meldung —
     * > und zwar dauerhaft.**
     *
     * Gemessen wird hier der erste Ausgang: Die Mail kommt an, der Webhook
     * nicht. Danach ist die Zeile für den Mailweg gebucht und für den Webhook
     * fällig — und der nächste Lauf versucht **nur** den Webhook.
     */
    public function test_each_channel_books_only_for_itself(): void
    {
        Mail::fake();
        $this->ziel(ScriptedNotifyTarget::broken());
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(1, $bilanz['mail']['sent']);
        self::assertSame(1, $bilanz['webhook']['failed'], 'Das Ziel steht und nimmt nichts an.');
        self::assertSame(1, $this->gebucht(MailChannel::CHANNEL));
        self::assertSame(0, $this->gebucht(WebhookChannel::CHANNEL),
            'Was nicht ankam, bleibt fällig — sonst wäre die Meldung für diesen Kanal für immer fort.');

        // Der nächste Lauf: der Mailweg hat nichts mehr zu tun, der Webhook schon.
        $dritte = $zweite->copy()->addDay();
        $this->lauf('p1000', ['disk_over'], $dritte);
        $danach = $this->notices()->send($dritte);

        self::assertSame(0, $danach['mail']['findings'],
            'Für den Mailweg ist nichts mehr fällig — sonst bekäme der Kunde dieselbe Mail jede Nacht.');
        self::assertSame(1, $danach['webhook']['findings'],
            'Für den Webhook schon — er hat die Meldung nie bekommen.');
        Mail::assertSent(QuotaWarning::class, 1);
    }

    /**
     * Und ein schweigender Agent bucht nichts.
     *
     * **„Nicht feststellbar" ist nicht „nicht eingerichtet"** — aber für die
     * Buchung ist beides dasselbe: Eine Zeile behauptete eine Zustellung, die
     * es nicht gab.
     */
    public function test_a_silent_agent_marks_nothing(): void
    {
        Mail::fake();
        $this->ziel(ScriptedNotifyTarget::silent());
        $this->relais();
        $this->abonnement();

        $erste = Carbon::parse('2026-09-22 03:00:00');
        $this->lauf('p1000', ['disk_over'], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf('p1000', ['disk_over'], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(1, $bilanz['webhook']['skipped']);
        self::assertSame(0, $this->gebucht(WebhookChannel::CHANNEL));
    }
}
