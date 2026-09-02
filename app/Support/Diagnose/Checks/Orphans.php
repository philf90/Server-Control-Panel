<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\FindingCheck;
use App\Models\CronJob;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Diagnose\Host;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificatePrune;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Cron\CronFile;

/**
 * H · Verwaiste Zeilen — `orphan.row` (`docs/98 §3 H`).
 *
 * Drei Reste, **gemeldet und nicht gelöscht**: Zertifikate ohne deckende
 * Domain, reservierte Systembenutzer, deren Konto noch da ist, und
 * Cron-Dateien ohne Job. Ein Diagnoselauf, der aufräumt, ist der nächste
 * Schreiber in derselben Datei (`docs/98 §5.1`); wer aufräumen will, nimmt
 * `srvpanel tls --prune`.
 *
 * ## Zertifikate: dieselbe Regel wie beim Aufräumen, nicht eine zweite
 *
 * Welche Zeile noch gebraucht wird, entscheidet {@see CertificatePrune} — die
 * eine Stelle, an der die Sicherheit des Aufräumens hängt, und die nach der
 * **Deckung** fragt und nicht nach der Zuordnung (`docs/78`). Diese Prüfung
 * liest ihren Plan und schreibt ihn ab; eine eigene Abfrage über
 * `subscription_id` wäre die zweite Fassung der Regel, und die zweite meldete
 * den Schlüssel unter einer laufenden Website als Rest.
 *
 * ## Systembenutzer: die Reservierung ist kein Rest — das Konto ist einer
 *
 * `system_users` führt jede Nummer für immer (`docs/35`): Eine Zeile ohne
 * Abonnement ist der Normalzustand nach jedem Rückbau, und sie jede Nacht zu
 * melden wäre die Falle aus `docs/98 §4`. Ein Rest ist erst, wenn das
 * **Unix-Konto** zu einer Nummer noch existiert, die kein Abonnement mehr
 * trägt — dann hat `subscription.remove` sein `userdel` nicht getan, und die
 * uid wartet darauf, dass `useradd` sie dem nächsten Kunden gibt.
 *
 * ## Cron-Dateien: die Datei gibt es nur, solange ein Job aktiv ist
 *
 * `cron.apply` entfernt `/etc/cron.d/srvpanel-<benutzer>`, sobald kein Job
 * mehr aktiv ist. Eine Datei, zu deren Benutzer kein Abonnement mit aktivem Job
 * gehört, ist deshalb ein Rest — gleich, ob das Abonnement fort oder gesperrt
 * ist: Ein gesperrtes behält seine Jobs, und dann steht die Datei zu Recht da.
 */
final class Orphans implements Check
{
    /** Die Gründe, die diese Prüfung ausspricht. */
    public const REASONS = [
        'orphan.row' => ['certificate', 'system_user', 'cron_file'],
    ];

    public function __construct(
        private readonly CertificatePrune $prune,
        private readonly Host $host,
        private readonly Tenancy $tenancy,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::OrphanRow];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $log->replace(
            FindingCheck::OrphanRow,
            [...$this->certificates(), ...$this->systemUsers(), ...$this->cronFiles()],
            $measuredAt,
        );
    }

    /** @return list<array{subject: string, reason: string, detail: null|string}> */
    private function certificates(): array
    {
        $plan = $this->prune->plan();
        $findings = [];

        foreach ($plan['removable'] as $storage) {
            $findings[] = [
                'subject' => $storage,
                'reason' => 'certificate',
                'detail' => $plan['reasons'][$storage] ?? null,
            ];
        }

        return $findings;
    }

    /** @return list<array{subject: string, reason: string, detail: null|string}> */
    private function systemUsers(): array
    {
        return $this->tenancy->withoutRestriction(function (): array {
            $held = array_fill_keys(
                Subscription::query()->whereNotNull('system_user')->pluck('system_user')->all(),
                true,
            );
            $findings = [];

            foreach (SystemUser::query()->orderBy('number')->get() as $row) {
                $user = Lifecycle::userName((int) $row->number);

                if (isset($held[$user]) || $this->host->uidOf($user) === null) {
                    continue;
                }

                $findings[] = [
                    'subject' => $user,
                    'reason' => 'system_user',
                    'detail' => sprintf(
                        'Konto vorhanden; reserviert für %s am %s',
                        $row->subscription ?? '–',
                        $row->claimed_at?->format('Y-m-d') ?? '–',
                    ),
                ];
            }

            return $findings;
        });
    }

    /** @return list<array{subject: string, reason: string, detail: null|string}> */
    private function cronFiles(): array
    {
        $files = $this->host->cronFiles();

        if ($files === []) {
            return [];
        }

        $withJobs = $this->tenancy->withoutRestriction(static function (): array {
            $ids = CronJob::query()->where('active', true)->distinct()->pluck('subscription_id')->all();

            return $ids === []
                ? []
                : Subscription::query()->whereIn('id', $ids)->whereNotNull('system_user')->pluck('system_user')->all();
        });
        $known = array_fill_keys($withJobs, true);
        $findings = [];

        foreach ($files as $path) {
            $user = substr(basename($path), strlen(CronFile::PREFIX));

            if ($user === '' || isset($known[$user])) {
                continue;
            }

            $findings[] = ['subject' => $path, 'reason' => 'cron_file', 'detail' => null];
        }

        return $findings;
    }
}
