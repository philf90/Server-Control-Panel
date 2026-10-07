<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Models\Account;
use App\Models\Finding;
use App\Models\FindingResolution;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Notices;
use App\Support\Notify\NotifyTarget;
use App\Support\Notify\WebhookChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * Zeilen, die eine Fassung vor B9 geschrieben hat (`docs/142 §10`).
 *
 * Bis B9 stand die Laufzeit eines Zertifikats als `tls.file / expiring` und
 * `tls.file / expired` in `findings`. Seitdem spricht keine Prüfung diese
 * Gründe aus, und nach dem Update stehen sie trotzdem da, bis der erste Lauf
 * sie ersetzt. Ohne {@see FindingCheck::retired()} warf jede Frage nach ihrem
 * Urteil: die Seite „Diagnose" einen 500er und der Meldelauf einen Abbruch.
 *
 * **Die Zeilen entstehen hier roh**, so wie die alte Fassung sie hinterlassen
 * hat. Über {@see FindingLog} kämen sie nicht mehr hinein; das hält der
 * dritte Fall.
 *
 * ## Warum nicht umgezogen wird
 *
 * Der Webhook hat den Vorfall unter `tls.file` aufgemacht, und sein Empfänger
 * ordnet die Entwarnung über Gegenstand, Prüfung und Grund zu. Eine Migration,
 * die die Zeile nach `tls.expiry` umzieht, liesse den alten Vorfall für immer
 * offen. Der zweite Fall misst deshalb den ganzen Weg: alte Zeile, erster Lauf
 * mit den echten Regeln der Prüfung, Meldelauf, und beim Empfänger kommt die
 * Entwarnung unter dem alten Schlüssel mit dem alten Satz an.
 */
final class RetiredReasonTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'kunde.example';

    /** Eine Zeile, wie sie eine Fassung vor B9 hinterlassen hat. */
    private function alteZeile(string $grund, Carbon $seit): int
    {
        return DB::table('findings')->insertGetId([
            'check' => 'tls.file',
            'subject' => self::DOMAIN,
            'reason' => $grund,
            'detail' => 'gültig bis 2026-10-01 00:00 UTC',
            'first_seen_at' => $seit,
            'measured_at' => $seit,
            'created_at' => $seit,
            'updated_at' => $seit,
        ]);
    }

    public function test_the_diagnose_page_still_shows_an_old_row(): void
    {
        $this->alteZeile('expired', Carbon::parse('2026-10-05 01:00:00', 'UTC'));

        $this->actingAs(Account::factory()->admin()->create())
            ->get('/diagnose')
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->where('findings.0.check', 'tls.file')
                ->where('findings.0.reason', 'expired')
                ->where('findings.0.state', 'fail')
                ->where('findings.0.sentence', 'Das Zertifikat ist abgelaufen.'));
    }

    /**
     * Der erste Lauf nach dem Update schliesst den alten Vorfall unter seinem
     * eigenen Schlüssel.
     *
     * Das Urteil kommt aus {@see Certificates::judge()}, also aus den Regeln,
     * die der Nachtlauf anwendet; geschrieben wird über denselben
     * {@see FindingLog}. Den Agenten selbst kann ein Test nicht fragen
     * (`SrvPanel\Agent\Client` ist `final`), seine Antwort steht deshalb hier.
     */
    public function test_the_first_run_after_the_update_closes_the_old_incident_under_its_own_key(): void
    {
        Mail::fake();
        $ziel = new ScriptedNotifyTarget;
        $this->app?->instance(NotifyTarget::class, $ziel);

        $id = $this->alteZeile('expired', Carbon::parse('2026-10-05 01:00:00', 'UTC'));
        DB::table('finding_notifications')->insert([
            'finding_id' => $id,
            'channel' => WebhookChannel::CHANNEL,
            'notified_at' => Carbon::parse('2026-10-06 01:00:00', 'UTC'),
            'created_at' => Carbon::parse('2026-10-06 01:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-10-06 01:00:00', 'UTC'),
        ]);

        $nacht = Carbon::parse('2026-10-08 01:00:00', 'UTC');
        $urteil = Certificates::judge(
            [['name' => self::DOMAIN, 'names' => [self::DOMAIN], 'storage' => self::DOMAIN, 'renewed' => true]],
            [self::DOMAIN => [
                'present' => true,
                'valid_from' => Carbon::parse('2026-07-03 00:00:00', 'UTC')->getTimestamp(),
                'valid_to' => Carbon::parse('2026-10-01 00:00:00', 'UTC')->getTimestamp(),
                'names' => [self::DOMAIN],
                'fingerprint' => str_repeat('AB', 32),
            ]],
            static fn (): ?string => null,
            $nacht,
        );

        $log = new FindingLog;
        $log->replace(FindingCheck::TlsFile, $urteil['file'], $nacht);
        $log->replace(FindingCheck::TlsExpiry, $urteil['expiry'], $nacht);

        self::assertNull(Finding::query()->find($id), 'Die alte Zeile steht noch — dann hat der Lauf sie nicht ersetzt.');
        self::assertSame(['expired', 'expiring'], Finding::query()->where('check', 'tls.expiry')->orderBy('reason')->pluck('reason')->all(),
            'Unter dem neuen Schlüssel steht die Laufzeit, und `expiring` bleibt neben `expired` stehen.');

        app(Notices::class)->send($nacht);

        self::assertSame([[
            'kind' => 'resolved',
            'subject' => self::DOMAIN,
            'findings' => [['check' => 'tls.file', 'reason' => 'expired', 'label' => 'Das Zertifikat ist abgelaufen.']],
        ]], $ziel->sent, 'Beim Empfänger kommt genau die Entwarnung für den alten Vorfall an, unter dem Schlüssel, unter dem er ihn aufgemacht hat. Die neuen Befunde warten ihre Haltezeit ab.');
        self::assertSame(0, FindingResolution::query()->count(), 'Die Entwarnung ist angekommen und gehört nicht mehr in die Warteschlange.');
    }

    /**
     * Ein abgelöster Grund wird gelesen und nie wieder geschrieben.
     *
     * {@see FindingCheck::state()} kennt ihn, damit eine alte Zeile ein Urteil
     * hat. Fragte der Schreibweg dieselbe Stelle, liesse er ihn wieder durch,
     * und eine neue Zeile sähe aus wie eine von vorher.
     */
    public function test_a_retired_reason_is_never_written_again(): void
    {
        self::assertSame('Das Zertifikat ist abgelaufen.', FindingCheck::TlsFile->sentence('expired'),
            'Gelesen wird der Grund — sonst sagt die Abweisung darunter nichts.');

        $this->expectException(InvalidArgumentException::class);

        (new FindingLog)->replace(FindingCheck::TlsFile, [['subject' => self::DOMAIN, 'reason' => 'expired']], Carbon::parse('2026-10-08 01:00:00', 'UTC'));
    }
}
