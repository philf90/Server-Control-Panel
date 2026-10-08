<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\BackupStatus;
use App\Enums\FindingCheck;
use App\Enums\SubscriptionStatus;
use App\Models\Backup;
use App\Models\Subscription;
use App\Support\Backups\Retention;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Notices;
use App\Support\Plans\Feature;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use Illuminate\Support\Carbon;

/**
 * Ist die jüngste Sicherung eines Abonnements gescheitert? — `backup.latest`
 * (B9, `docs/142 §4`).
 *
 * ## Warum es diese Prüfung gibt
 *
 * **Bis zum 7. Oktober 2026 erfuhr eine gescheiterte Sicherung niemand**, auch
 * der Betreiber nicht (`docs/142 §2`, Befund 1). `docs/129 §4` führte den
 * Auslöser mit {@see Backups} als Quelle, und die fragt mit Absicht nur
 * fertige Archive. `BackupStatus::Failed` wurde an einer Stelle geschrieben
 * und an keiner gelesen; die Seite des Abonnements zeigte die Zeile, und wer
 * nicht nachsah, sah nichts.
 *
 * > **Ein Auslöser, dessen Quelle niemand nachgelesen hat, steht in einer
 * > Tabelle und nirgends sonst.**
 *
 * ## Welche Sicherung zählt
 *
 * **Die jüngste fertige, gleich wer sie angestossen hat** — entschieden vom
 * Betreiber am 7. Oktober 2026 (`docs/142 §6`, Frage 4). Eine gelungene von
 * Hand nimmt eine gescheiterte aus der Nacht zurück, und eine gescheiterte von
 * Hand meldet sich am nächsten Tag.
 *
 * - **Eine laufende zählt nicht.** Sie hat noch keinen Ausgang; neben einer
 *   gescheiterten zählt die gescheiterte, bis die laufende fertig ist.
 * - **Eine, die gerade entfernt wird, zählt als gelungen.** Sie war es, bevor
 *   jemand auf „Entfernen" drückte.
 * - **Jünger heisst später angelegt** — dieselbe Ordnung, mit der
 *   {@see Retention::isDue()} den jüngsten Stand sucht.
 *
 * ## Welche Abonnements
 *
 * **Die, die eine neue Sicherung bekommen können:** benutzbar, und ihr Plan
 * gibt Sicherungen frei. Ohne die zweite Bedingung stünde nach einem
 * Planwechsel ein Befund da, den keine Sicherung mehr ablösen kann. Ob die
 * Automatik an ist, fragt sie nicht: Auch eine gescheiterte Sicherung von Hand
 * ist eine, die fehlt.
 *
 * ## Zurück nimmt ihn die nächste gelungene
 *
 * Die Aufbewahrung räumt gescheiterte Zeilen nicht ab — *„weil sie nichts
 * aufbewahrt"* ({@see Retention}) —, und die Automatik versucht es in der
 * nächsten Nacht wieder, weil `isDue()` eine gescheiterte nicht zählt.
 *
 * ## Gemeldet in der ersten Nacht, die sie sieht
 *
 * Ohne Haltezeit ({@see Notices::holdMinutes()}), entschieden mit Frage 3.
 * Mit den zwanzig Stunden aus B1 würfelte die Meldung: Ob die Diagnose den
 * Befund ein- oder zweimal sieht, entscheidet, ob sie vor oder nach der
 * Sicherung läuft (`docs/142 §3` M3).
 *
 * ## Was sie nicht kann
 *
 * **Kein Agent, kein Archiv.** Sie liest allein die eigene Datenbank und kennt
 * deshalb kein `unreachable`. Ob eine **gelungene** Sicherung noch heil ist,
 * fragt {@see Backups} in ihrem eigenen Lauf.
 */
final class LatestBackups implements Check
{
    /** Die Gründe, die diese Prüfung ausspricht — je Schlüssel. */
    public const REASONS = [
        'backup.latest' => ['failed'],
    ];

    /**
     * Was als fertig gilt — `Pending` fehlt mit Absicht (Kopf der Klasse).
     *
     * @var list<BackupStatus>
     */
    public const FINISHED = [BackupStatus::Ready, BackupStatus::Failed, BackupStatus::Removing];

    /**
     * Was als gelungen gilt — die fertigen ohne die gescheiterten.
     *
     * @var list<BackupStatus>
     */
    public const SUCCEEDED = [BackupStatus::Ready, BackupStatus::Removing];

    public function __construct(
        private readonly Tenancy $tenancy,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::BackupLatest];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $log->replace(FindingCheck::BackupLatest, $this->findings(), $measuredAt);
    }

    /**
     * Das Urteil über die jüngste fertige Sicherung — `null` für keinen Befund.
     *
     * **Der Zeitpunkt steht mit seiner Zone**, wie bei
     * {@see MaintenanceWindow}: Ein Satz, der eine Stunde nennt, bleibt wahr,
     * auch wenn jemand die Anzeigezone später umstellt. Und **der Grund ist
     * der Satz, den der Kunde auf der Seite liest** — `last_error`, aus dem
     * Ausgang des Vorgangs.
     *
     * @return null|array{reason: string, detail: string}
     */
    public static function judge(?Backup $latest): ?array
    {
        if ($latest === null || $latest->status !== BackupStatus::Failed) {
            return null;
        }

        $erstellt = $latest->created_at?->copy()->utc()->format('Y-m-d H:i:s');
        $meldung = trim((string) $latest->last_error);

        return [
            'reason' => 'failed',
            'detail' => sprintf(
                'erstellt %s %s: %s',
                Clock::minute($erstellt) ?? '—',
                Clock::labelAt($erstellt) ?? '',
                $meldung === '' ? 'ohne Meldung des Vorgangs' : $meldung,
            ),
        ];
    }

    /**
     * Die jüngste Sicherung eines Abonnements in einem dieser Zustände.
     *
     * **Eine Stelle für die Prüfung und für die Mail.** Die Mail an den
     * Kunden fragt nach der jüngsten gelungenen und nennt die gescheiterte,
     * die diese Prüfung gefunden hat; stünde die Ordnung zweimal da, nennte
     * sie eine andere als die, über die sie berichtet.
     *
     * Ohne Mandantenklammer nur, wenn der Aufrufer sie gelöst hat — wie in
     * {@see self::findings()}.
     *
     * @param  list<BackupStatus>  $status
     */
    public static function latest(Subscription $subscription, array $status): ?Backup
    {
        return Backup::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', array_map(static fn (BackupStatus $s): string => $s->value, $status))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Die Befunde über alle Abonnements, die gesichert werden können.
     *
     * **Ohne Mandantenklammer, begründet:** Der Nachtlauf hat kein Konto und
     * fragt den ganzen Server — wie `srvpanel:backups` selbst.
     *
     * @return list<array{subject: string, reason: string, detail: string}>
     */
    private function findings(): array
    {
        /** @var list<array{subject: string, reason: string, detail: string}> $befunde */
        $befunde = [];

        $this->tenancy->withoutRestriction(static function () use (&$befunde): void {
            $abos = Subscription::query()
                ->with('plan')
                ->whereIn('status', SubscriptionStatus::usableValues())
                ->orderBy('id')
                ->get();

            /** @var Subscription $abo */
            foreach ($abos as $abo) {
                if ($abo->plan?->feature(Feature::Backups) !== true) {
                    continue;
                }

                $urteil = self::judge(self::latest($abo, self::FINISHED));

                if ($urteil !== null) {
                    $befunde[] = ['subject' => (string) $abo->name] + $urteil;
                }
            }
        });

        return $befunde;
    }
}
