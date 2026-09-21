<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\DailyMetric;
use App\Enums\FindingCheck;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Cron\ServerZone;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;

/**
 * Liegt ein Abonnement über einem seiner Kontingente — `quota.exceeded` (B5).
 *
 * ## Drei Kontingente und nicht vierzehn
 *
 * Der Katalog führt vierzehn, und die meisten davon **können gar nicht
 * überschritten werden**: Domains, Datenbanken, Cronjobs, FTP-Zugänge und
 * Sicherungen werden beim Anlegen geprüft, und was nicht entsteht, steht auch
 * nicht über der Grenze. Was überschreitbar ist, sind die Kontingente, die ein
 * **gemessener** Wert füllt und kein Klick: Platz, Datenbankgrösse, Verkehr.
 *
 * > **Ein Kontingent, das beim Anlegen geprüft wird, braucht keine Überwachung
 * > — es braucht seine Prüfung.**
 *
 * ## `null` heisst „nicht gemessen" und nicht „null belegt"
 *
 * Ein Abonnement ohne gemessenen Platz erzeugt **keinen** Befund. Der Rückfall
 * auf 0 wäre die bequemere von zwei falschen Auskünften: Er sagte „alles in
 * Ordnung" über etwas, das niemand nachgesehen hat — derselbe Fehler, den
 * `docs/44` für `catch (Throwable) { return []; }` festhält.
 *
 * ## Der Verkehr — zwei Entscheidungen, die hier stehen und nicht verstreut
 *
 * **Der Kalendermonat und kein rollendes Fenster.** Das Kontingent heisst
 * {@see Quota::TrafficGb} und trägt die Beschriftung „Traffic je Monat"; ein
 * rollendes Fenster von dreissig Tagen ist etwas anderes und ergäbe eine
 * andere Zahl.
 *
 * > **Ein rollendes Fenster ist kein Kalendermonat — und ein Kontingent, das je
 * > Monat gilt, ist an einem rollenden Fenster gemessen ein anderes
 * > Kontingent.**
 *
 * **Und es ist eine Untergrenze, nicht die Summe.** B3 hebt dreissig Tage auf
 * (`Daily::RETENTION_DAYS`); ein Monat hat bis zu einunddreissig. Am letzten
 * Tag eines langen Monats fehlt deshalb der erste. Für eine Warnung ist das
 * die richtige Richtung — sie meldet eher zu spät als zu früh —, und sie steht
 * hier, damit niemand die Zahl später für die volle hält.
 *
 * **Gezählt wird, was hinausgeht.** `$bytes_sent` ist die Richtung, die eine
 * Website kostet und die bei einem Webserver zuerst an die Grenze stösst; der
 * eingehende Verkehr steht daneben auf der Kachel und zählt hier nicht mit.
 * Das ist eine Entscheidung und keine Messung — sie gehört dem Betreiber, und
 * sie steht an dieser einen Stelle.
 *
 * ## Was diese Prüfung nicht sagt
 *
 * Sie sagt nicht, ob die Grenze **greift**. Ob das Dateisystem die Quota
 * erzwingt, fragt {@see Quotas} über
 * `quota.state`; der Hinweis an `Quota::TrafficGb` sagt für den Verkehr
 * ausdrücklich „gemessen, nicht erzwungen".
 */
final class QuotaOverrun implements Check
{
    /**
     * Die Gründe, die diese Prüfung ausspricht.
     *
     * `DiagnoseSeamTest` hält beide Richtungen aneinander: Was hier steht, muss
     * der Katalog kennen, und was der Katalog kennt, muss jemand aussprechen.
     * Ein Grund ohne Sprecher ist ein toter Eintrag, der bei einer Umbenennung
     * entsteht.
     *
     * @var array<string, list<string>>
     */
    public const REASONS = [
        'quota.exceeded' => ['disk_over', 'databases_over', 'traffic_over', 'traffic_unknown'],
    ];

    public function __construct(private readonly Tenancy $tenancy) {}

    /** @return list<FindingCheck> */
    public function writes(): array
    {
        return [FindingCheck::QuotaExceeded];
    }

    /**
     * **Die Klammer wird genau einmal gelöst, und zwar hier.**
     *
     * Der Nachtlauf läuft ohne angemeldetes Konto; im Grundzustand steht jede
     * Abfrage auf `whereRaw('0 = 1')`. Der erste Wurf dieser Klasse löste sie
     * nur für die Liste der Abonnements — und `Subscription::databaseUsedMb()`
     * fragt eine zweite Tabelle. Die hätte **wortlos null Zeilen** ergeben,
     * also „nicht gemessen", also keinen Befund: ein Kunde über seinem
     * Datenbankkontingent wäre nie aufgefallen.
     *
     * > **Zwei Stellen, die dieselbe Ausnahme brauchen, und nur eine hat sie:
     * > Die andere fällt nicht auf, weil sie leise das Richtige tut — nämlich
     * > nichts.** Derselbe Fehler wie `Cron::store()` in P6.
     */
    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        /** @var list<array{subject: string, reason: string, detail: string}> $findings */
        $findings = [];

        $this->tenancy->withoutRestriction(function () use ($measuredAt, &$findings): void {
            foreach ($this->subscriptions() as $subscription) {
                $name = (string) $subscription->name;

                foreach ($this->overruns($subscription, $measuredAt) as $finding) {
                    $findings[] = ['subject' => $name] + $finding;
                }
            }
        });

        $log->replace(FindingCheck::QuotaExceeded, $findings, $measuredAt);
    }

    /**
     * Die Überschreitungen eines Abonnements.
     *
     * @return list<array{reason: string, detail: string}>
     */
    private function overruns(Subscription $subscription, Carbon $measuredAt): array
    {
        $out = [];

        $platte = $this->over($subscription->disk_used_mb, $subscription->quota(Quota::DiskMb->value));

        if ($platte !== null) {
            $out[] = ['reason' => 'disk_over', 'detail' => $this->megabytes(...$platte)];
        }

        $datenbanken = $this->over($subscription->databaseUsedMb(), $subscription->quota(Quota::DatabaseMb->value));

        if ($datenbanken !== null) {
            $out[] = ['reason' => 'databases_over', 'detail' => $this->megabytes(...$datenbanken)];
        }

        $gesendet = $this->trafficThisMonth($subscription, $measuredAt);
        $grenze = $subscription->quota(Quota::TrafficGb->value);

        /*
         * **Nicht beurteilt ist etwas anderes als in Ordnung.** Ohne die Zone
         * des Servers steht hier ein Befund und nicht nichts — sonst sähe
         * „konnte nicht" aus wie „nichts gefunden", und das ist der Fehler, den
         * `docs/44` bezahlt hat. Gemeldet wird nur, wo überhaupt eine Grenze
         * gilt: Wer unbegrenzten Verkehr hat, dem fehlt nichts.
         */
        if ($gesendet === null) {
            return is_numeric($grenze) && (float) $grenze > 0.0
                ? [...$out, ['reason' => 'traffic_unknown', 'detail' => 'Die Zeitzone des Servers ist nicht lesbar.']]
                : $out;
        }

        $verkehr = $this->over($gesendet / 1_000_000_000.0, $grenze);

        if ($verkehr !== null) {
            $out[] = ['reason' => 'traffic_over', 'detail' => sprintf(
                '%s GB von %s GB in diesem Monat',
                number_format($verkehr[0], 1, ',', '.'),
                number_format($verkehr[1], 0, ',', '.'),
            )];
        }

        return $out;
    }

    /**
     * Liegt ein gemessener Wert über seiner Grenze?
     *
     * `null` bei **jeder** Unklarheit: kein Messwert, keine Grenze, oder eine
     * Grenze von 0 oder weniger. Eine Grenze von 0 heisst im Katalog
     * „unbegrenzt" oder „nicht angeboten" (siehe `Quota::allowsUnlimited()`);
     * gegen sie zu vergleichen machte aus jedem Kunden einen Überschreiter.
     *
     * @return array{0: float, 1: float}|null gemessen und Grenze
     */
    private function over(int|float|null $used, mixed $limit): ?array
    {
        if ($used === null || ! is_numeric($limit) || (float) $limit <= 0.0) {
            return null;
        }

        return (float) $used > (float) $limit ? [(float) $used, (float) $limit] : null;
    }

    private function megabytes(float $used, float $limit): string
    {
        return sprintf(
            '%s MB von %s MB',
            number_format($used, 0, ',', '.'),
            number_format($limit, 0, ',', '.'),
        );
    }

    /**
     * Was dieses Abonnement seit dem Monatsersten hinausgeschickt hat, in Byte.
     *
     * Die Tage kommen aus der verdichteten Tabelle von B3 und nicht aus den
     * rohen Protokollen: Die hält `logrotate` vierzehn Tage, diese dreissig,
     * und ein Monat braucht mehr als vierzehn.
     */
    private function trafficThisMonth(Subscription $subscription, Carbon $measuredAt): ?float
    {
        $zone = ServerZone::current();

        /*
         * **Ohne die Zone des Servers wird der Verkehr nicht geprüft.**
         *
         * Die Tage in der Tabelle sind Kalendertage des **Servers** (B2), die
         * Anwendung läuft in UTC. Auf einem Server in UTC+02:00 liegt am
         * Monatsersten zwischen 00:00 und 02:00 Uhr der UTC-Monat noch einen
         * zurück — summiert würde dann ein ganzer Monat zu viel, und der Kunde
         * bekäme eine Mail über eine Überschreitung, die es nicht gibt.
         *
         * `null` heisst hier „nicht geprüft" und erzeugt keinen Befund. Eine
         * ausgefallene Warnung ist die bessere der beiden falschen Auskünfte;
         * `docs/108` hat die andere ein Jahr lang bezahlt.
         *
         * > **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine
         * > falsche Auskunft.**
         */
        if ($zone === null) {
            return null;
        }

        $erster = $measuredAt->copy()->setTimezone($zone)->startOfMonth()->toDateString();

        return (float) SubscriptionMetric::query()
            ->where('subscription_id', (int) $subscription->id)
            ->where('metric', DailyMetric::TrafficSentBytes->value)
            ->where('day', '>=', $erster)
            ->sum('value');
    }

    /**
     * Die Abonnements, die es angeht.
     *
     * Ein zurückgebautes oder gesperrtes Abonnement über sein Kontingent zu
     * melden hiesse, dem Kunden eine Mail über etwas zu schicken, das er nicht
     * mehr benutzt.
     *
     * @return list<Subscription>
     */
    private function subscriptions(): array
    {
        return Subscription::query()
            ->whereIn('status', SubscriptionStatus::usableValues())
            ->orderBy('id')
            ->get()
            ->all();
    }
}
