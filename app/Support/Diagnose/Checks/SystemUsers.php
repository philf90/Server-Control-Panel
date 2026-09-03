<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\FindingCheck;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Diagnose\Host;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * G · Systembenutzer — `system.user` (`docs/98 §3 G`).
 *
 * Für jedes lebende Abonnement: Gibt es den Systembenutzer, den `system_users`
 * reserviert hat, und gehört ihm sein Dokumentenverzeichnis?
 *
 * ## Warum das Dokumentenverzeichnis und nicht die Wurzel
 *
 * `docs/98 §3 G` sagt „gehört ihm seine Wurzel unter `/var/www/vhosts`" — und
 * die Wurzel gehört **absichtlich nicht ihm**. `SubscriptionProvision::tree()`
 * legt `/var/www/vhosts/<abo>` als `root:root 0755` an: Das Zugriffsbit dort
 * ist der Schalter, mit dem `subscription.suspend` das ganze Abonnement
 * abschaltet (`SubscriptionState`). Dem Kunden gehört, was darunter liegt —
 * `httpdocs` mit `www-data` als Gruppe. Gefragt wird deshalb dort, wo die
 * Vorlage den Benutzer wirklich einträgt.
 *
 * > **Eine Erwartung, die man an ein Verzeichnis aufschreibt, liest vorher
 * > nach, wem die Vorlage es gibt.**
 *
 * ## Ohne den Agenten
 *
 * `/etc/passwd` liest jeder, und für `stat` auf `httpdocs` genügt das
 * Suchrecht auf `/var/www/vhosts` und der Abo-Wurzel — beide `0755`. Gesperrte
 * Abonnements stehen auf `0750` und ihr Konto ist abgelaufen; sie werden nicht
 * gefragt, weil dort beides so sein soll.
 */
final class SystemUsers implements Check
{
    /** Die Gründe, die diese Prüfung ausspricht. */
    public const REASONS = [
        'system.user' => ['missing', 'wrong_owner', 'root_missing'],
    ];

    public function __construct(
        private readonly Host $host,
        private readonly Tenancy $tenancy,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::SystemUser];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $findings = [];

        foreach ($this->subscriptions() as $subscription) {
            $name = (string) $subscription->name;
            $verdict = self::judge($name, (string) $subscription->system_user, $this->host);

            if ($verdict !== null) {
                $findings[] = ['subject' => $name] + $verdict;
            }
        }

        $log->replace(FindingCheck::SystemUser, $findings, $measuredAt);
    }

    /**
     * Das Urteil über ein Abonnement — gegen das, was die Maschine sagt.
     *
     * Die Reihenfolge trägt: Ohne Konto gibt es keine uid, mit der sich ein
     * Eigentümer vergleichen liesse, und ohne Verzeichnis keinen Eigentümer.
     *
     * @return null|array{reason: string, detail: null|string}
     */
    public static function judge(string $subscription, string $user, Host $host): ?array
    {
        $uid = $host->uidOf($user);

        if ($uid === null) {
            return ['reason' => 'missing', 'detail' => $user];
        }

        $root = SubscriptionProvision::VHOSTS.'/'.$subscription.'/'.SubscriptionProvision::DOCUMENT_ROOT;
        $owner = $host->ownerOf($root);

        if ($owner === null) {
            return ['reason' => 'root_missing', 'detail' => $root];
        }

        if ($owner !== $uid) {
            return [
                'reason' => 'wrong_owner',
                'detail' => sprintf('%s gehört uid %d; %s hat uid %d', $root, $owner, $user, $uid),
            ];
        }

        return null;
    }

    /**
     * Die lebenden Abonnements mit Systembenutzer.
     *
     * **Ohne Mandantenklammer, begründet:** Der Nachtlauf hat kein Konto, und
     * die Frage gilt dem ganzen Server — dieselbe Lage wie bei `Cron::ingest()`
     * und `CertificatePrune`.
     *
     * @return list<Subscription>
     */
    private function subscriptions(): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = [];

        $this->tenancy->withoutRestriction(static function () use (&$subscriptions): void {
            $subscriptions = Subscription::query()
                ->whereIn('status', SubscriptionStatus::usableValues())
                ->whereNotNull('system_user')
                ->orderBy('id')
                ->get()
                ->all();
        });

        return $subscriptions;
    }
}
