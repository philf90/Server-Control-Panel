<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Models\Account;
use App\Models\Finding;
use App\Models\FindingNotification;
use App\Models\FindingResolution;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Channel;
use App\Support\Notify\Channels;
use App\Support\Notify\Notices;
use App\Support\Notify\NotifyTarget;
use App\Support\Notify\ResolvingChannel;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * Was gemeldet wurde, wird auch abgemeldet — B1, `docs/129 §7`.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `NotificationLedgerTest` misst, dass **höchstens einmal** gemeldet wird, und
 * {@see NoticeAudienceTest}, **an wen**. Über das Ende eines Befundes sagte bis
 * zum 24. September 2026 keiner von beiden etwas — es gab es nicht:
 * {@see FindingLog::forgetMissing()} löschte die Zeile, und mit ihr die
 * Erinnerung an die Zustellung.
 *
 * > **Ein Kanal, der nur meldet, dass etwas kaputt ist, erzieht seinen Leser
 * > dazu, ihn zu ignorieren.**
 *
 * ## Und jede Richtung einzeln
 *
 * „Eine Entwarnung geht hinaus" allein erfüllte auch ein Panel, das für jeden
 * verschwundenen Befund eine schickt — auch für den, von dem nie jemand gehört
 * hat. Jeder Fall hier misst deshalb beides: dass der gemeldete Befund
 * abgemeldet wird **und** dass der ungemeldete es nicht wird.
 *
 * ## Was er nicht kann
 *
 * Ob ein **neuer** Kanal entwarnen soll, hängt daran, ob sein Empfänger einen
 * Zustand führt — das ist ein Urteil und keine Eigenschaft des Quelltextes.
 * {@see self::test_the_question_separates_the_channels()} hält deshalb nur,
 * dass die Frage die Kanäle überhaupt **trennt**: Sobald alle Umsetzungen
 * dasselbe antworten, ist sie keine Frage mehr, sondern eine Zeile, die
 * abgeschrieben wird. Genau daran ist `Channel::carries()` gestorben.
 */
final class NoticeResolveTest extends TestCase
{
    use RefreshDatabase;

    /** Eine Prüfung, die dem Betreiber gehört — beide Kanäle tragen sie. */
    private const SERVER = FindingCheck::UnitState;

    private const DIENST = 'srvpanel-metrics.service';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->relais();
        $this->betreiber();
    }

    /**
     * Der Fall, für den es die Tabelle gibt.
     *
     * Zwei Nächte mit dem Befund, eine dritte ohne ihn — und der Empfänger
     * erfährt, dass der Vorfall zu ist.
     */
    public function test_a_reported_finding_that_disappears_is_announced(): void
    {
        $ziel = $this->ziel();

        $dritte = $this->gemeldetUndVerschwunden();
        // Ein frisches Doppel statt einer geleerten Liste: Was jetzt darin
        // steht, ist von dieser Nacht und nicht der Rest der vorigen.
        $ziel = $this->ziel();

        $bilanz = $this->notices()->send($dritte);

        self::assertSame(1, $bilanz['webhook']['resolved']);
        self::assertCount(1, $ziel->sent);
        self::assertSame('resolved', $ziel->sent[0]['kind']);
        self::assertSame(self::DIENST, $ziel->sent[0]['subject']);

        // Und die Warteschlange ist danach leer — sonst käme die Entwarnung
        // in jeder folgenden Nacht noch einmal.
        self::assertSame(0, FindingResolution::query()->count());
    }

    /**
     * Eine Entwarnung zählt als Zustellung.
     *
     * **„Zuletzt erfolgreich zugestellt" beantwortet die Frage, ob dieser Weg
     * noch trägt** — und dafür ist gleichgültig, was in der Nachricht stand.
     * Zählte nur die Meldung, stünde auf der Einstellungsseite ein Datum von
     * gestern, während heute nacht etwas angekommen ist.
     *
     * Gemessen in beide Richtungen: der Stand vor dem Versand und danach.
     */
    public function test_an_announcement_counts_as_a_delivery(): void
    {
        $this->ziel();

        $dritte = $this->gemeldetUndVerschwunden();
        app(Settings::class)->saveNoticeSent('webhook', '2026-01-01 00:00:00');

        $vorher = $this->notices()->lastDelivered('webhook');
        $this->notices()->send($dritte);

        self::assertNotSame($vorher, $this->notices()->lastDelivered('webhook'),
            'In dieser Nacht ging nur eine Entwarnung hinaus — und sie ist angekommen.');
    }

    /**
     * Und die Gegenrichtung: Was nie hinausging, wird nicht zurückgenommen.
     *
     * Ein Befund, der **innerhalb** der Haltezeit wieder verschwindet, hat
     * niemanden erreicht. {@see Notices::HOLD_HOURS} ist genau dafür da.
     *
     * > **Eine Entwarnung ohne vorangegangene Warnung ist eine Meldung über
     * > nichts.**
     */
    public function test_a_finding_nobody_heard_of_is_not_announced(): void
    {
        $ziel = $this->ziel();

        $erste = Carbon::parse('2026-09-24 03:00:00');
        $this->lauf([self::DIENST], $erste);

        // Noch vor der Haltezeit wieder fort — es ist nie etwas hinausgegangen.
        $zweite = $erste->copy()->addHours(2);
        $this->lauf([], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(0, FindingNotification::query()->count(), 'Der Befund war nie fällig.');
        self::assertSame(0, FindingResolution::query()->count());
        self::assertSame(0, $bilanz['webhook']['resolved']);
        self::assertSame([], $ziel->sent);
    }

    /**
     * Ein Fehlschlag verbraucht die Zeile nicht.
     *
     * **Derselbe Grund wie bei {@see FindingNotification}:** Eine Zeile, die
     * nach einem Fehlschlag verschwindet, nimmt der Entwarnung ihre
     * Fälligkeit — und der Vorfall bliebe beim Empfänger für immer offen.
     *
     * Gemessen in beide Richtungen: erst der Fehlschlag mit liegengebliebener
     * Zeile, dann derselbe Bestand über ein Ziel, das trägt.
     */
    public function test_a_failed_delivery_keeps_the_resolution_pending(): void
    {
        $this->ziel();

        $dritte = $this->gemeldetUndVerschwunden();

        // Das Ziel weist erst jetzt ab — die Meldung war schon draussen.
        $this->ziel(ScriptedNotifyTarget::broken());
        $bilanz = $this->notices()->send($dritte);

        self::assertSame(0, $bilanz['webhook']['resolved']);
        self::assertSame(1, $bilanz['webhook']['failed']);
        self::assertSame(1, FindingResolution::query()->where('channel', 'webhook')->count());

        $ziel = $this->ziel();
        $bilanz = $this->notices()->send($dritte->copy()->addDay());

        self::assertSame(1, $bilanz['webhook']['resolved']);
        self::assertCount(1, $ziel->sent);
        self::assertSame(0, FindingResolution::query()->count());
    }

    /**
     * Die Entwarnung nennt dieselbe Prüfung und denselben Satz wie die Meldung.
     *
     * **Sonst muss der Leser zwei Formulierungen auf dieselbe Sache beziehen.**
     * Der Satz kommt aus {@see FindingCheck::sentence()} und nicht aus einer
     * zweiten Liste — dieselbe Stelle, aus der ihn die Meldung geholt hat.
     */
    public function test_the_announcement_repeats_check_reason_and_sentence(): void
    {
        $ziel = $this->ziel();

        $dritte = $this->gemeldetUndVerschwunden();
        $gemeldet = $ziel->sent[0]['findings'][0];
        // Ein frisches Doppel statt einer geleerten Liste: Was jetzt darin
        // steht, ist von dieser Nacht und nicht der Rest der vorigen.
        $ziel = $this->ziel();

        $this->notices()->send($dritte);

        $entwarnt = $ziel->sent[0]['findings'][0];

        self::assertSame($gemeldet['check'], $entwarnt['check']);
        self::assertSame($gemeldet['reason'], $entwarnt['reason']);
        self::assertSame($gemeldet['label'], $entwarnt['label']);

        /*
         * **Ohne `state`, ohne `detail`, ohne `since`.** Ein Zustand, den es
         * nicht mehr gibt, hat kein Urteil; „steht seit" wäre eine Angabe über
         * eine Zeile, die gelöscht ist.
         */
        self::assertSame(['check', 'reason', 'label'], array_keys($entwarnt));
    }

    /**
     * Ein Kanal, der nicht entwarnt, lässt keine Zeilen liegen.
     *
     * **Und der Fall misst beide Seiten in einem Bestand.** Der Mailkanal ist
     * eingerichtet und entwarnt nicht — seine Zeile ist danach fort. Der
     * Webhook entwarnt und ist **nicht** eingerichtet — seine Zeile bleibt
     * liegen und wird zugestellt, sobald ein Ziel dasteht.
     *
     * Ohne die zweite Hälfte wäre auch ein Panel grün, das die Warteschlange
     * jede Nacht leerräumt.
     */
    public function test_a_channel_without_resolutions_leaves_no_rows(): void
    {
        $this->ziel(ScriptedNotifyTarget::empty());

        $dritte = $this->gemeldetUndVerschwunden(webhookZiel: false);

        self::assertSame(1, FindingResolution::query()->where('channel', 'mail')->count(),
            'Der Mailkanal hat gemeldet — also steht seine Zeile da, bis jemand sie verbraucht.');

        $this->notices()->send($dritte);

        self::assertSame(0, FindingResolution::query()->where('channel', 'mail')->count());
        self::assertSame(0, FindingResolution::query()->where('channel', 'webhook')->count(),
            'Ohne Ziel hat der Webhook nie gemeldet — dann gibt es auch nichts abzumelden.');
    }

    /**
     * Und ein eingerichteter, entwarnender Kanal behält seine Zeile, solange er
     * nicht durchkommt.
     *
     * Das ist die Gegenprobe zum Fall darüber: Dort war der Webhook gar nicht
     * eingerichtet, hier ist er es und das Ziel schweigt.
     *
     * **Und er wird nicht einmal befragt.** Ein nicht eingerichteter Kanal ist
     * kein Fehlschlag — gezählt würde er sonst jede Nacht, und die Unit stünde
     * rot für einen Server, der schlicht kein Meldeziel hat. Ohne diese Zahl
     * hielten den Fall **zwei** Wände: die Frage nach dem Ziel und der
     * Fehlschlag der Zustellung, die dieselben Zeilen liegenlässt.
     *
     * > **Ein Prüfkörper, den zwei Wände halten, sagt über keine von beiden
     * > etwas.**
     */
    public function test_an_unusable_channel_keeps_its_rows(): void
    {
        $this->ziel();

        $dritte = $this->gemeldetUndVerschwunden();

        // Das Ziel verschwindet zwischen Messung und Versand.
        $this->ziel(ScriptedNotifyTarget::silent());

        $bilanz = $this->notices()->send($dritte);

        self::assertSame(1, FindingResolution::query()->where('channel', 'webhook')->count());
        self::assertSame(0, $bilanz['webhook']['failed'],
            'Ein Kanal ohne Ziel wird nicht befragt — und ein nicht gestellter Versuch ist kein Fehlschlag.');
    }

    /**
     * Der Zeitpunkt ist der der Messung und nicht der der Zustellung.
     *
     * **Behoben war es, als der Lauf es nicht mehr fand.** Gemessen mit einer
     * festgesetzten Uhr, die um Tage danebenliegt — sonst wäre ein `now()` von
     * einem `measuredAt` nicht zu unterscheiden.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     */
    public function test_the_moment_is_the_measurement_and_not_the_delivery(): void
    {
        $this->ziel();
        Carbon::setTestNow('2026-10-08 12:00:00');

        $dritte = $this->gemeldetUndVerschwunden();

        $zeile = FindingResolution::query()->where('channel', 'webhook')->firstOrFail();

        self::assertSame($dritte->toDateTimeString(), $zeile->resolved_at->toDateTimeString());
        self::assertNotSame(Carbon::now()->toDateTimeString(), $zeile->resolved_at->toDateTimeString());
    }

    /**
     * Die Entwarnung geht vor der Meldung hinaus.
     *
     * Beide betreffen denselben Empfänger; kommt die Entwarnung hinterher,
     * liest sie sich wie die Rücknahme dessen, was gerade gemeldet wurde.
     *
     * > **Zwei Meldungen über denselben Gegenstand haben eine richtige
     * > Reihenfolge, und sie ist nicht die, in der sie entstanden sind.**
     */
    public function test_the_resolution_leaves_before_the_new_finding(): void
    {
        $ziel = $this->ziel();

        $erste = Carbon::parse('2026-09-24 03:00:00');
        $this->lauf([self::DIENST], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf([self::DIENST], $zweite);
        $this->notices()->send($zweite);

        // Der alte Dienst ist heil, ein neuer ist kaputt — und der ist in
        // derselben Nacht faellig, weil er seit der ersten Messung steht.
        $this->lauf(['nginx.service'], $erste);
        $this->lauf(['nginx.service'], $zweite);
        $dritte = $zweite->copy()->addHours(24);
        $this->lauf(['nginx.service'], $dritte);

        // Ein frisches Doppel statt einer geleerten Liste: Was jetzt darin
        // steht, ist von dieser Nacht und nicht der Rest der vorigen.
        $ziel = $this->ziel();
        $bilanz = $this->notices()->send($dritte);

        self::assertSame(1, $bilanz['webhook']['resolved']);
        self::assertSame(1, $bilanz['webhook']['sent']);
        self::assertCount(2, $ziel->sent);
        self::assertSame(['resolved', 'findings'], array_map(
            static fn (array $e): string => (string) $e['kind'],
            $ziel->sent,
        ));
    }

    /**
     * Die Frage trennt die Kanäle — sonst ist sie keine.
     *
     * **Das ist die Lehre aus `Channel::carries()`**, das am 24. September
     * verschwand, weil zwei von zwei Umsetzungen dasselbe antworteten. Was
     * dort eine Erinnerung war, ist hier ein Wächter.
     *
     * > **Eine Frage, die alle Umsetzungen gleich beantworten, ist keine
     * > Frage.**
     */
    public function test_the_question_separates_the_channels(): void
    {
        $this->ziel();

        $alle = app(Channels::class)->all();

        $entwarnend = array_values(array_filter($alle, static fn (Channel $c): bool => $c instanceof ResolvingChannel));
        $stumm = array_values(array_filter($alle, static fn (Channel $c): bool => ! $c instanceof ResolvingChannel));

        self::assertNotSame([], $entwarnend, 'Kein Kanal entwarnt — dann ist `finding_resolutions` eine Tabelle ohne Leser.');
        self::assertNotSame([], $stumm, 'Jeder Kanal entwarnt — dann ist die Unterscheidung eine Zeile, die abgeschrieben wird.');
    }

    /**
     * Zwei Gründe an einem Gegenstand sind **eine** Entwarnung.
     *
     * Der Webhook bündelt je Gegenstand — beim Melden wie beim Abmelden.
     * Zwei Meldungen über denselben Dienst wären beim Empfänger zwei Vorfälle,
     * und einer von beiden bliebe offen.
     */
    public function test_two_reasons_on_one_subject_are_one_announcement(): void
    {
        $ziel = $this->ziel();

        $erste = Carbon::parse('2026-09-24 03:00:00');
        $zeilen = [
            ['subject' => self::DIENST, 'reason' => 'inactive'],
            ['subject' => self::DIENST, 'reason' => 'failed'],
        ];

        $this->laufMitGruenden($zeilen, $erste);
        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->laufMitGruenden($zeilen, $zweite);
        $this->notices()->send($zweite);

        $dritte = $zweite->copy()->addHours(24);
        $this->laufMitGruenden([], $dritte);

        self::assertSame(2, FindingResolution::query()->where('channel', 'webhook')->count(),
            'Zwei Gründe ergeben zwei Zeilen — zugestellt werden sie zusammen.');

        // Ein frisches Doppel statt einer geleerten Liste: Was jetzt darin
        // steht, ist von dieser Nacht und nicht der Rest der vorigen.
        $ziel = $this->ziel();
        $bilanz = $this->notices()->send($dritte);

        self::assertSame(1, $bilanz['webhook']['resolved']);
        self::assertCount(1, $ziel->sent);
        self::assertCount(2, $ziel->sent[0]['findings']);
    }

    /* ------------------------------------------------------------------ */

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

    private function betreiber(): void
    {
        app(Tenancy::class)->withoutRestriction(
            static fn (): Account => Account::factory()->admin()->create(['email' => 'betreiber@example.org']),
        );
    }

    /**
     * Zwei Nächte mit dem Befund, eine dritte ohne ihn — und der Versand
     * dazwischen, damit die Meldung wirklich hinausgegangen ist.
     *
     * @return Carbon der Zeitpunkt der dritten Messung
     */
    private function gemeldetUndVerschwunden(bool $webhookZiel = true): Carbon
    {
        $erste = Carbon::parse('2026-09-24 03:00:00');
        $this->lauf([self::DIENST], $erste);

        $zweite = $erste->copy()->addHours(Notices::HOLD_HOURS + 3);
        $this->lauf([self::DIENST], $zweite);

        $bilanz = $this->notices()->send($zweite);

        self::assertSame(1, $bilanz['mail']['sent'], 'Ohne Meldung gibt es nichts abzumelden.');
        self::assertSame($webhookZiel ? 1 : 0, $bilanz['webhook']['sent']);

        $dritte = $zweite->copy()->addHours(24);
        $this->lauf([], $dritte);

        return $dritte;
    }

    /**
     * Einen Lauf fahren — über {@see FindingLog} und nicht mit einem
     * `Finding::create()`.
     *
     * Der Prüfkörper geht damit durch dieselbe Stelle wie der Nachtlauf, und
     * das ist hier mehr als Sorgfalt: Die Entwarnung **entsteht** dort.
     *
     * @param  list<string>  $dienste
     */
    private function lauf(array $dienste, Carbon $at): void
    {
        $this->laufMitGruenden(
            array_map(static fn (string $d): array => ['subject' => $d, 'reason' => 'inactive'], $dienste),
            $at,
        );
    }

    /** @param  list<array{subject: string, reason: string}>  $zeilen */
    private function laufMitGruenden(array $zeilen, Carbon $at): void
    {
        app(FindingLog::class)->replace(
            self::SERVER,
            array_map(static fn (array $z): array => [
                'subject' => $z['subject'],
                'reason' => $z['reason'],
                'detail' => 'gemessener Wortlaut',
            ], $zeilen),
            $at,
        );

        self::assertSame(
            count($zeilen),
            Finding::query()->where('check', self::SERVER->value)->count(),
            'Der Prüfkörper hat den Bestand nicht hergestellt.',
        );
    }
}
