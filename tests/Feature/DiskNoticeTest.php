<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Mail\DiagnoseReport;
use App\Models\Account;
use App\Models\Finding;
use App\Models\FindingResolution;
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
 * „Platte voll" meldet nach zehn Minuten — und lässt die Nacht in Ruhe
 * (`docs/136 §4` und §5).
 *
 * ## Die Haltezeit hängt am Schlüssel
 *
 * `HOLD_HOURS` ist auf den Nachtlauf zugeschnitten: zwei Nächte hintereinander.
 * Für `disk.space` wären zwanzig Stunden sinnlos — bei voller Platte stürzt
 * MariaDB beim nächsten Wachsen einer Tabelle ab (`docs/136 §3` M5). Der
 * Betreiber hat zehn Minuten entschieden, also drei Läufe im Fünfminutentakt.
 *
 * ## Und der Lauf alle fünf Minuten ist beschränkt
 *
 * Die Unit „Platte voll" ruft `srvpanel:notices --check=disk.space`. Meldete
 * sie alles, ginge ein Befund der Nacht nach zwanzig Stunden hinaus, **bevor**
 * die zweite Nacht ihn bestätigt hat. Jeder Fall hier steht deshalb neben seiner
 * Gegenprobe ohne Beschränkung: Eine Beschränkung, die nur daran gemessen ist,
 * dass nichts hinausgeht, hielte auch ein Lauf ein, der gar nichts meldet.
 */
final class DiskNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        app(Settings::class)->saveMail(new MailSettings(
            host: 'relay.example.org',
            port: 587,
            encryption: 'tls',
            username: '',
            password: '',
            from_address: 'panel@example.org',
            from_name: 'SrvPanel',
        ));

        // Ohne Doppel fragte der Webhook-Kanal den echten Agenten.
        $this->app->instance(NotifyTarget::class, ScriptedNotifyTarget::empty());

        app(Tenancy::class)->withoutRestriction(
            static fn (): Account => Account::factory()->admin()->create(['email' => 'betreiber@example.org']),
        );
    }

    private function notices(): Notices
    {
        return app(Notices::class);
    }

    /** Ein Lauf über {@see FindingLog}, wie ihn die Prüfung fährt. */
    private function lauf(FindingCheck $check, string $subject, string $reason, Carbon $at): void
    {
        app(FindingLog::class)->replace($check, [['subject' => $subject, 'reason' => $reason, 'detail' => 'gemessen']], $at);
    }

    public function test_the_hold_belongs_to_the_key(): void
    {
        self::assertSame(Notices::DISK_HOLD_MINUTES, Notices::holdMinutes(FindingCheck::DiskSpace));
        self::assertSame(Notices::HOLD_HOURS * 60, Notices::holdMinutes(FindingCheck::UnitState),
            'Die Nacht behält ihre zwanzig Stunden.');
    }

    /**
     * **Gemeldet beim dritten Lauf — auch wenn er eine halbe Minute zu früh kommt.**
     *
     * Der Zeitgeber streut um bis zu dreissig Sekunden je Lauf. Die Läufe hier
     * stehen deshalb an den ungünstigsten Stellen: der zweite so spät wie
     * möglich (er darf noch nicht melden), der dritte so früh wie möglich (er
     * muss). Mit einer Haltezeit genau auf zwei Takten, dem ersten Wurf, meldete
     * erst der vierte.
     */
    public function test_a_full_disk_is_reported_at_the_third_run_and_not_before(): void
    {
        $erste = Carbon::parse('2026-09-27 01:35:00');
        $this->lauf(FindingCheck::DiskSpace, '/', 'space_full', $erste);

        $zweite = $erste->copy()->addSeconds(5 * 60 + 31);
        $this->lauf(FindingCheck::DiskSpace, '/', 'space_full', $zweite);
        self::assertSame(0, $this->notices()->send($zweite, [FindingCheck::DiskSpace])['mail']['sent'],
            'Der zweite Lauf meldet nicht — sonst reichten zwei.');

        $dritte = $erste->copy()->addSeconds(10 * 60 - 31);
        $this->lauf(FindingCheck::DiskSpace, '/', 'space_full', $dritte);
        self::assertSame(1, $this->notices()->send($dritte, [FindingCheck::DiskSpace])['mail']['sent'],
            'Der dritte Lauf meldet, auch wenn er ein wenig früher kommt als zehn Minuten nach dem ersten.');

        Mail::assertSent(DiagnoseReport::class, static fn (DiagnoseReport $m): bool => $m->hasTo('betreiber@example.org'));
    }

    /**
     * **Der beschränkte Lauf lässt einen Befund der Nacht stehen**, auch wenn
     * seine zwanzig Stunden um sind. Die Gegenprobe ohne Beschränkung meldet
     * ihn — sonst wäre „nichts verschickt" auch die Auskunft eines Laufs, der
     * gar nicht meldet.
     */
    public function test_the_disk_run_leaves_a_finding_of_the_night_alone(): void
    {
        $nacht = Carbon::parse('2026-09-26 00:44:00');
        $this->lauf(FindingCheck::UnitState, 'srvpanel-worker.service', 'inactive', $nacht);

        $spaeter = $nacht->copy()->addHours(Notices::HOLD_HOURS)->addMinutes(5);

        self::assertSame(0, $this->notices()->send($spaeter, [FindingCheck::DiskSpace])['mail']['sent'],
            'Ein Befund der Nacht ginge sonst hinaus, bevor die zweite Nacht ihn bestätigt hat.');
        self::assertSame(1, $this->notices()->send($spaeter)['mail']['sent']);
    }

    /** Dasselbe für die Entwarnungen: Der beschränkte Lauf verbraucht keine der Nacht. */
    public function test_the_disk_run_leaves_the_resolutions_of_the_night_alone(): void
    {
        $finding = Finding::query()->create([
            'check' => FindingCheck::UnitState,
            'subject' => 'srvpanel-worker.service',
            'reason' => 'inactive',
            'first_seen_at' => Carbon::parse('2026-09-25 00:44:00'),
            'measured_at' => Carbon::parse('2026-09-26 00:44:00'),
        ]);
        FindingResolution::record($finding, MailChannel::CHANNEL, Carbon::parse('2026-09-27 00:44:00'));

        $this->notices()->send(Carbon::parse('2026-09-27 00:50:00'), [FindingCheck::DiskSpace]);
        self::assertSame(1, FindingResolution::query()->where('channel', MailChannel::CHANNEL)->count());

        $this->notices()->send(Carbon::parse('2026-09-27 00:50:00'));
        self::assertSame(0, FindingResolution::query()->where('channel', MailChannel::CHANNEL)->count(),
            'Die Gegenprobe: Ohne Beschränkung verbraucht der Mailkanal die Zeile, denn er entwarnt nicht.');
    }

    /** Durch das Kommando, wie die Unit es ruft. */
    public function test_the_command_restricts_to_its_key(): void
    {
        $nacht = Carbon::parse('2026-09-26 00:44:00');
        $this->lauf(FindingCheck::UnitState, 'srvpanel-worker.service', 'inactive', $nacht);
        Carbon::setTestNow($nacht->copy()->addDay());

        $this->artisan('srvpanel:notices', ['--check' => ['disk.space']])->assertExitCode(0);
        Mail::assertNothingSent();

        $this->artisan('srvpanel:notices')->assertExitCode(0);
        Mail::assertSent(DiagnoseReport::class, 1);

        Carbon::setTestNow();
    }

    /**
     * **Ein Tippfehler in der Unit bricht ab**, statt still über alles zu
     * melden. Mit `--check=disk.spcae` meldete die Unit sonst alle fünf Minuten
     * die Befunde der Nacht.
     */
    public function test_an_unknown_key_sends_nothing(): void
    {
        $nacht = Carbon::parse('2026-09-26 00:44:00');
        $this->lauf(FindingCheck::UnitState, 'srvpanel-worker.service', 'inactive', $nacht);
        Carbon::setTestNow($nacht->copy()->addDay());

        $this->artisan('srvpanel:notices', ['--check' => ['disk.spcae']])->assertExitCode(1);
        Mail::assertNothingSent();

        Carbon::setTestNow();
    }
}
