<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Mail\DiagnoseReport;
use App\Mail\QuotaWarning;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Finding;
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
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * Wer welche Meldung bekommt — B1, `docs/129 §4`.
 *
 * ## Warum das ein eigener Wächter ist
 *
 * {@see NotificationLedgerTest} misst, dass **höchstens einmal** gemeldet wird,
 * und {@see ChannelReachTest}, dass es jeden Kanal gibt. Über die Frage, an wen
 * eine Meldung geht, sagt keiner von beiden etwas — und genau die hat sich am
 * 24. September geändert: Bis dahin trug der Mailkanal nur die
 * Kontingentbefunde des Kunden, seitdem auch die siebzehn übrigen Prüfungen,
 * und die gehören dem Betreiber.
 *
 * > **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
 * > stimmt, nur durch Glück getrennt.**
 *
 * ## Und jede Richtung einzeln
 *
 * „Der Kunde bekommt seine Mail" erfüllte auch ein Panel, das **jedem** alles
 * schickt. Jeder Fall hier misst deshalb beides: dass der Gemeinte etwas
 * bekommt **und** dass der andere nichts bekommt.
 *
 * ## Was er nicht kann
 *
 * Ob eine **neue** Prüfung dem Kunden oder dem Betreiber gehört, hängt daran,
 * wessen Gegenstand sie misst — das ist ein Urteil und keine Eigenschaft des
 * Quelltextes. {@see self::test_exactly_one_check_belongs_to_the_customer()}
 * hält deshalb nur die Zahl: Wer sie ändert, entscheidet.
 */
final class NoticeAudienceTest extends TestCase
{
    use RefreshDatabase;

    /** Eine Prüfung, die dem Server gehört — und nicht die einzige. */
    private const SERVER = FindingCheck::UnitState;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->relais();
    }

    private function notices(): Notices
    {
        return app(Notices::class);
    }

    /** Ohne Doppel fragte der Webhook-Kanal den echten Agenten. */
    private function ziel(ScriptedNotifyTarget $target = new ScriptedNotifyTarget): ScriptedNotifyTarget
    {
        $this->app?->instance(NotifyTarget::class, $target);

        return $target;
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

    /** Ein Abonnement mit einem Kundenkonto, das eine Adresse hat. */
    private function abonnement(string $name = 'p1000', string $mail = 'kunde@example.org'): Subscription
    {
        return app(Tenancy::class)->withoutRestriction(static function () use ($name, $mail): Subscription {
            $kunde = Customer::factory()->create();
            Account::factory()->customer($kunde)->create(['email' => $mail]);

            return Subscription::factory()->create(['name' => $name, 'customer_id' => $kunde->id]);
        });
    }

    private function betreiber(string $mail = 'betreiber@example.org'): Account
    {
        return app(Tenancy::class)->withoutRestriction(
            static fn (): Account => Account::factory()->admin()->create(['email' => $mail]),
        );
    }

    /**
     * Einen Lauf fahren — über {@see FindingLog} und nicht mit einem
     * `Finding::create()`.
     *
     * Der Prüfkörper geht damit durch dieselbe Stelle wie der Nachtlauf. Ein
     * von Hand gesetztes `first_seen_at` prüfte, ob dieser Test rechnen kann.
     *
     * @param  list<array{subject: string, reason: string}>  $zeilen
     */
    private function lauf(FindingCheck $check, array $zeilen, Carbon $at): void
    {
        app(FindingLog::class)->replace(
            $check,
            array_map(static fn (array $z): array => [
                'subject' => $z['subject'],
                'reason' => $z['reason'],
                'detail' => 'gemessener Wortlaut',
            ], $zeilen),
            $at,
        );
    }

    /**
     * Zwei Läufe im Abstand der Haltezeit und danach der Versand.
     *
     * @param  list<array{subject: string, reason: string}>  $zeilen
     * @return array<string, array{sent: int, resolved: int, findings: int, without_recipient: int, failed: int, skipped: int}>
     */
    private function zweiNaechte(FindingCheck $check, array $zeilen): array
    {
        $erste = Carbon::parse('2026-09-24 03:00:00');
        $this->lauf($check, $zeilen, $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf($check, $zeilen, $zweite);

        return $this->notices()->send($zweite);
    }

    public function test_a_quota_finding_goes_to_the_customer_and_not_to_the_operator(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->abonnement();
        $this->betreiber();

        $bilanz = $this->zweiNaechte(FindingCheck::QuotaExceeded, [['subject' => 'p1000', 'reason' => 'disk_over']]);

        self::assertSame(1, $bilanz['mail']['sent']);
        Mail::assertSent(QuotaWarning::class, static fn (QuotaWarning $m): bool => $m->hasTo('kunde@example.org'));
        Mail::assertNotSent(DiagnoseReport::class);
        Mail::assertNotSent(QuotaWarning::class, static fn (QuotaWarning $m): bool => $m->hasTo('betreiber@example.org'));
    }

    public function test_a_server_finding_goes_to_the_operator_and_not_to_the_customer(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->abonnement();
        $this->betreiber();

        $bilanz = $this->zweiNaechte(self::SERVER, [['subject' => 'srvpanel-worker.service', 'reason' => 'inactive']]);

        self::assertSame(1, $bilanz['mail']['sent']);
        Mail::assertSent(DiagnoseReport::class, static fn (DiagnoseReport $m): bool => $m->hasTo('betreiber@example.org'));
        Mail::assertNotSent(QuotaWarning::class);
        Mail::assertNotSent(DiagnoseReport::class, static fn (DiagnoseReport $m): bool => $m->hasTo('kunde@example.org'));
    }

    /**
     * Eine Nacht mit drei Befunden an drei Orten ist **eine** Mail.
     *
     * Nach `subject` gebündelt wären es drei — und drei Mails in derselben
     * Minute sind für den Empfänger genau das, wogegen „genau eine" geschrieben
     * ist.
     */
    public function test_the_operator_gets_one_mail_for_a_whole_night(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->betreiber();

        $bilanz = $this->zweiNaechte(self::SERVER, [
            ['subject' => 'srvpanel-worker.service', 'reason' => 'inactive'],
            ['subject' => 'srvpanel-metrics.service', 'reason' => 'failed'],
            ['subject' => 'nginx.service', 'reason' => 'inactive'],
        ]);

        self::assertSame(1, $bilanz['mail']['sent'], 'Drei Befunde, eine Nachricht.');
        self::assertSame(3, $bilanz['mail']['findings']);
        Mail::assertSent(DiagnoseReport::class, 1);
    }

    /**
     * Und der Webhook bündelt **anders** — je Gegenstand eine Meldung.
     *
     * **Das ist die Gegenrichtung zum Fall darüber und nicht eine Wiederholung
     * davon.** Ohne sie erfüllte auch ein `batchKey()`, das für jeden Kanal
     * dasselbe zurückgibt, beide Fälle — und dann bekäme ein Vorfallsystem
     * drei Sachen in einer Meldung.
     */
    public function test_the_webhook_sends_one_delivery_per_subject(): void
    {
        $ziel = $this->ziel();
        $this->betreiber();

        $bilanz = $this->zweiNaechte(self::SERVER, [
            ['subject' => 'srvpanel-worker.service', 'reason' => 'inactive'],
            ['subject' => 'srvpanel-metrics.service', 'reason' => 'failed'],
            ['subject' => 'nginx.service', 'reason' => 'inactive'],
        ]);

        self::assertSame(3, $bilanz['webhook']['sent']);
        self::assertCount(3, $ziel->sent);
        self::assertSame(
            ['nginx.service', 'srvpanel-metrics.service', 'srvpanel-worker.service'],
            array_values(array_unique(array_map(static fn (array $e): string => (string) $e['subject'], $ziel->sent))),
        );

        // Und die Mail daneben bleibt eine — sonst misst dieser Fall nur den Webhook.
        self::assertSame(1, $bilanz['mail']['sent']);
    }

    /** Zwei Kunden sind zwei Mails — die Bündelung fasst nicht über Empfänger hinweg. */
    public function test_two_subscriptions_are_two_mails(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());
        $this->abonnement('p1000', 'eins@example.org');
        $this->abonnement('p1001', 'zwei@example.org');

        $bilanz = $this->zweiNaechte(FindingCheck::QuotaExceeded, [
            ['subject' => 'p1000', 'reason' => 'disk_over'],
            ['subject' => 'p1001', 'reason' => 'disk_over'],
        ]);

        self::assertSame(2, $bilanz['mail']['sent']);
        Mail::assertSent(QuotaWarning::class, 2);
        Mail::assertSent(QuotaWarning::class, static fn (QuotaWarning $m): bool => $m->hasTo('eins@example.org'));
        Mail::assertSent(QuotaWarning::class, static fn (QuotaWarning $m): bool => $m->hasTo('zwei@example.org'));
    }

    /**
     * Ein gesperrtes Konto bekommt nichts.
     *
     * Wer sich nicht anmelden darf, bekommt auch keine Auskunft über den
     * Server. Die Regel steht an **einer** Stelle und gilt für beide
     * Empfänger; gemessen wird sie hier am Betreiber, weil dort die Wirkung
     * am grössten ist.
     */
    public function test_a_disabled_account_gets_nothing(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());

        app(Tenancy::class)->withoutRestriction(static function (): void {
            Account::factory()->admin()->disabled()->create(['email' => 'gesperrt@example.org']);
        });

        $bilanz = $this->zweiNaechte(self::SERVER, [['subject' => 'srvpanel-worker.service', 'reason' => 'inactive']]);

        self::assertSame(0, $bilanz['mail']['sent']);
        self::assertSame(1, $bilanz['mail']['without_recipient']);
        Mail::assertNothingSent();

        // Und die Gegenrichtung: derselbe Befund, ein aktives Konto daneben.
        $this->betreiber();
        $spaeter = Carbon::parse('2026-09-25 03:00:00');
        $this->lauf(self::SERVER, [['subject' => 'srvpanel-worker.service', 'reason' => 'inactive']], $spaeter);

        self::assertSame(1, $this->notices()->send($spaeter)['mail']['sent']);
        Mail::assertSent(DiagnoseReport::class, static fn (DiagnoseReport $m): bool => $m->hasTo('betreiber@example.org'));
    }

    /**
     * Ein Administrator ist kein Betreiber.
     *
     * Die Trennung ist seit A9 gebaut: Der Administrator sieht den Server, der
     * Betreiber dreht daran. Eine Meldung über einen toten Dienst ist eine
     * Aufforderung zu handeln.
     */
    public function test_an_administrator_is_not_an_operator(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());

        app(Tenancy::class)->withoutRestriction(static function (): void {
            Account::factory()->administrator()->create(['email' => 'admin@example.org']);
        });

        $bilanz = $this->zweiNaechte(self::SERVER, [['subject' => 'srvpanel-worker.service', 'reason' => 'inactive']]);

        self::assertSame(1, $bilanz['mail']['without_recipient']);
        Mail::assertNothingSent();
    }

    /** Ohne Betreiberadresse bleibt der Befund fällig statt zu verschwinden. */
    public function test_without_an_operator_address_the_finding_stays_due(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());

        $bilanz = $this->zweiNaechte(self::SERVER, [['subject' => 'srvpanel-worker.service', 'reason' => 'inactive']]);

        self::assertSame(0, $bilanz['mail']['sent']);
        self::assertSame(1, $bilanz['mail']['without_recipient']);
        self::assertSame(0, Finding::query()->firstOrFail()->notifications()->count(),
            'Was nicht verschickt wurde, darf nicht als gemeldet dastehen.');
    }

    /**
     * Genau **eine** Prüfung gehört dem Kunden.
     *
     * **Gemessen über den ganzen Katalog und nicht gegen eine Liste hier.**
     * Gefragt wird der Kanal selbst: Welche der achtzehn Prüfungen bündelt er
     * unter einem Abonnement, welche unter dem Betreiber? Eine Liste im Test
     * wäre die zweite Fassung derselben Zuordnung.
     *
     * **Und die Zahl ist ein Halt und kein Befund.** Wer eine neunzehnte
     * Prüfung baut, die einem Kunden gehört, macht diesen Fall rot — und das
     * ist der Augenblick, in dem jemand entscheidet, wer sie bekommt. Ein
     * Wächter kann das nicht wissen: Es hängt daran, wessen Gegenstand sie
     * misst.
     */
    public function test_exactly_one_check_belongs_to_the_customer(): void
    {
        $kanal = app(MailChannel::class);

        $betreiber = [];
        $kunden = [];

        foreach (FindingCheck::cases() as $check) {
            $schluessel = $kanal->batchKey($check, 'p1000');

            if (str_contains($schluessel, 'p1000')) {
                $kunden[] = $check->value;
            } else {
                $betreiber[] = $check->value;
            }
        }

        self::assertSame([FindingCheck::QuotaExceeded->value], $kunden,
            'Nur `quota.exceeded` misst den Gegenstand eines Kunden — alles andere misst den Server.');

        self::assertGreaterThanOrEqual(17, count($betreiber),
            'Es sind kaum Prüfungen gefunden worden — dann prüft dieser Fall nichts.');
    }

    /**
     * Die Abfrage und die Frage sagen dasselbe.
     *
     * **Der Anlass war ein Fehler, den dieser Wächter auf seinem ersten Lauf
     * gefunden hat.** `MailChannel` fragte die Betreiber über
     * `where('role', 'operator')` und sonst nichts. Heute ist das richtig — die
     * Migration hat die Spalte nur an Adminkonten gefüllt —, und richtig war es
     * damit **aus den Daten** und nicht aus der Regel.
     * {@see Account::isOperator()} fragt seit A9 **beide** Achsen.
     *
     * > **Zwei Fassungen derselben Regel sind eine zu viel — und die zweite
     * > ist die, die veraltet.**
     *
     * **Gemessen an der Wirkung über einen Bestand mit allen vier Fällen**,
     * und der dritte ist der, der den Fehler ans Licht gebracht hat: ein
     * Kundenkonto, das `role` trägt. Die Kontenfabrik setzt sie in ihrer
     * Vorgabe; im Betrieb entsteht so ein Konto heute nicht, und genau deshalb
     * hätte kein Blick auf die Daten den Fehler gezeigt.
     */
    public function test_the_query_and_the_question_agree(): void
    {
        app(Tenancy::class)->withoutRestriction(static function (): void {
            Account::factory()->admin()->create(['email' => 'betreiber@example.org']);
            Account::factory()->administrator()->create(['email' => 'admin@example.org']);
            Account::factory()->customer()->create(['email' => 'kunde@example.org']);
            Account::factory()->admin()->disabled()->create(['email' => 'gesperrt@example.org']);
        });

        app(Tenancy::class)->withoutRestriction(static function (): void {
            /** @var list<string> $ausDerAbfrage */
            $ausDerAbfrage = Account::operators()->orderBy('email')->pluck('email')->all();

            /** @var list<string> $ausDerFrage */
            $ausDerFrage = Account::query()
                ->orderBy('email')
                ->get()
                ->filter(static fn (Account $a): bool => $a->isOperator())
                ->pluck('email')
                ->values()
                ->all();

            self::assertSame($ausDerFrage, $ausDerAbfrage);

            // Und eine Untergrenze, damit die Gleichheit nicht die zweier
            // leerer Listen ist.
            self::assertCount(2, $ausDerAbfrage, 'Zwei der vier Konten sind Betreiber.');
            self::assertNotContains('kunde@example.org', $ausDerAbfrage,
                'Ein Kundenkonto mit der Spalte `role` ist kein Betreiber — die Spalte allein gewährt nichts.');
        });
    }
}
