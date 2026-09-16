<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\FindingCheck;
use App\Models\Backup;
use App\Models\Finding;
use App\Models\Subscription;
use App\Support\Diagnose\Checks\Backups;
use App\Support\Diagnose\Checks\Orphans;
use App\Support\Diagnose\FindingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Ops\BackupVerify;
use Tests\TestCase;
use Tests\Unit\BackupVerifyTest;

/**
 * Die Naht zwischen `backup.verify` und dem Bestand der Befunde.
 *
 * {@see BackupVerifyTest} misst, **was** die Operation an einem
 * beschädigten Archiv findet. Hier steht die andere Hälfte: Ob das Panel
 * überhaupt zu ihr kommt, und was es aus ihrer Antwort macht.
 */
final class BackupDiagnoseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Der Lauf sieht die Sicherungen, obwohl niemand angemeldet ist.**
     *
     * Das ist der Befund, den `Cron::store()` in P6 gekostet hat: „88
     * eingesammelt, 0 eingepflegt". Ein Nachtlauf hat kein Konto; ohne
     * `withoutRestriction()` steht die Mandantenklammer auf `whereRaw('0 = 1')`
     * und gibt eine leere Liste zurück — wortlos, und der Lauf meldete „keine
     * Befunde".
     *
     * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer
     * > leeren Liste und nicht mit einem Fehler.** (`docs/78`)
     *
     * **Gemessen am schweigenden Agenten**, und das ist hier kein Behelf,
     * sondern der schärfere Prüfkörper: In diesem Container antwortet keiner,
     * also muss eine `unreachable`-Zeile entstehen. Bliebe die Klammer zu,
     * entstünde **gar keine** — und die beiden Zustände sind genau die, die man
     * auseinanderhalten will.
     */
    public function test_the_nightly_run_sees_the_backups_without_an_account(): void
    {
        $subscription = Subscription::factory()->create(['name' => 'shop']);

        Backup::query()->create([
            'subscription_id' => $subscription->id,
            'subscription_name' => 'shop',
            'storage_name' => 'shop-20260916-120000-abcdef01',
            'status' => BackupStatus::Ready,
        ]);

        $this->assertGuest();

        app(Backups::class)->run(Carbon::now(), app(FindingLog::class));

        $zeilen = Finding::query()->where('check', FindingCheck::BackupFile->value)->get();

        $this->assertCount(1, $zeilen, 'Der Lauf hat die Sicherung nicht gesehen — steht die Mandantenklammer offen?');
        $this->assertSame('shop-20260916-120000-abcdef01', $zeilen[0]->subject);
        $this->assertSame(FindingCheck::UNREACHABLE, $zeilen[0]->reason);
    }

    /**
     * Eine laufende und eine gescheiterte Sicherung werden **nicht** angesehen.
     *
     * Beide Auslassungen haben einen Grund, und keiner heisst Sparsamkeit: Eine
     * laufende wird in diesem Augenblick geschrieben, eine gescheiterte ist
     * bekanntermassen keine — und die Seite sagt es bereits.
     *
     * > **Ein Befund über einen Zustand, den die Seite schon nennt, ist keine
     * > Auskunft — er ist eine zweite Stimme, die irgendwann anders klingt.**
     */
    public function test_a_pending_or_failed_backup_is_left_alone(): void
    {
        $subscription = Subscription::factory()->create(['name' => 'shop']);

        foreach ([BackupStatus::Pending, BackupStatus::Failed] as $status) {
            Backup::query()->create([
                'subscription_id' => $subscription->id,
                'subscription_name' => 'shop',
                'storage_name' => 'shop-'.$status->value,
                'status' => $status,
            ]);
        }

        app(Backups::class)->run(Carbon::now(), app(FindingLog::class));

        $this->assertSame(0, Finding::query()->where('check', FindingCheck::BackupFile->value)->count());
    }

    /**
     * Eine Zeile ohne Abonnementnamen wird **gemeldet** und nicht übersprungen.
     *
     * Zu ihr lässt sich kein Pfad bauen, und damit gibt es keine Frage, die der
     * Agent beantworten könnte. Hier stand zuerst ein stilles `continue` mit dem
     * Hinweis, `orphan.row` fange den Fall — nachgesehen fängt es ihn nicht:
     * {@see Orphans} kennt Zertifikate,
     * Systembenutzer und Cron-Dateien und keine Sicherungen.
     *
     * > **Ein Prüfkörper, der überspringt, meldet das Überspringen nicht.**
     */
    public function test_a_row_without_a_subscription_name_is_reported(): void
    {
        Backup::query()->create([
            'subscription_id' => null,
            'subscription_name' => '',
            'storage_name' => 'namenlos-20260916-120000-abcdef01',
            'status' => BackupStatus::Ready,
        ]);

        app(Backups::class)->run(Carbon::now(), app(FindingLog::class));

        $zeilen = Finding::query()->where('check', FindingCheck::BackupFile->value)->get();

        $this->assertCount(1, $zeilen, 'Die Zeile wurde übersprungen, und niemand erfährt davon.');
        $this->assertSame(BackupVerify::MISSING, $zeilen[0]->reason);
        $this->assertSame('namenlos-20260916-120000-abcdef01', $zeilen[0]->subject);
    }

    /**
     * Und ein Grund, den dieses Panel nicht kennt, wird **nicht** durchgereicht.
     *
     * Käme aus dem Agenten ein Wort, das der Katalog nicht führt, würfe
     * {@see FindingCheck::state()} — nachts, in einem Lauf, den niemand sieht,
     * und der ganze Lauf wäre fort. Hier wird daraus eine Zeile, die sagt, dass
     * die Fassungen auseinandergehen.
     *
     * > **Ein Grund, den der Agent ausspricht und das Panel nicht kennt, ist
     * > kein Befund über den Server — er ist einer über die Installation.**
     */
    public function test_a_reason_the_panel_does_not_know_becomes_a_finding_and_not_a_crash(): void
    {
        $findings = Backups::findingsOf([
            'findings' => [
                ['subject' => 'shop-x', 'reason' => 'aus-der-zukunft', 'detail' => 'was auch immer'],
            ],
        ], 'shop-x');

        $this->assertSame([BackupVerify::UNREADABLE], array_column($findings, 'reason'));
        $this->assertSame('shop-x', $findings[0]['subject']);

        // Und die Gegenprobe: Ein bekannter Grund kommt unverändert durch.
        $durch = Backups::findingsOf([
            'findings' => [
                ['subject' => 'shop-x', 'reason' => BackupVerify::CORRUPT, 'detail' => 'Die Bytes von a stimmen nicht.'],
            ],
        ], 'shop-x');

        $this->assertSame([BackupVerify::CORRUPT], array_column($durch, 'reason'));
        $this->assertSame('Die Bytes von a stimmen nicht.', $durch[0]['detail']);
    }

    /**
     * Eine Antwort ohne Befundliste ist ein Befund und keine Entwarnung.
     *
     * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
     * > beiden.**
     */
    public function test_an_answer_without_a_list_is_not_an_all_clear(): void
    {
        $this->assertSame(
            [BackupVerify::UNREADABLE],
            array_column(Backups::findingsOf([], 'shop-x'), 'reason'),
        );

        // Die Gegenprobe: eine **leere** Liste ist Entwarnung, und zwar zu Recht.
        $this->assertSame([], Backups::findingsOf(['findings' => []], 'shop-x'));
    }

    /**
     * Jeder Grund des Agenten steht in {@see Backups::REASONS}.
     *
     * **Aus der Aufzählung des Agenten abgeleitet und nicht abgeschrieben.**
     * Eine zweite Liste hier wäre die, die veraltet — genau der Fall, den
     * `DiagnoseSeamTest` für den Katalog in beide Richtungen hält.
     */
    public function test_the_reasons_come_from_the_agent_and_not_from_a_second_list(): void
    {
        $gemeldet = Backups::REASONS['backup.file'];

        foreach (BackupVerify::REASONS as $reason) {
            $this->assertContains($reason, $gemeldet, sprintf(
                'Der Agent spricht %s aus, und die Prüfung des Panels führt den Grund nicht.',
                $reason,
            ));
        }

        $this->assertContains(FindingCheck::UNREACHABLE, $gemeldet, 'Ohne `unreachable` hätte ein ausgefallener Lauf keinen Grund.');
        $this->assertCount(count(BackupVerify::REASONS) + 1, $gemeldet, 'Die Prüfung führt einen Grund, den der Agent nicht ausspricht.');
    }
}
