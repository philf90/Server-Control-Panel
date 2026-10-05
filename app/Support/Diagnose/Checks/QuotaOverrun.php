<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\DailyMetric;
use App\Enums\FindingCheck;
use App\Enums\SubscriptionStatus;
use App\Models\Finding;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Cron\ServerZone;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\DiskQuota;

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
 * ## Der Platz wird nicht überschritten, sondern erreicht
 *
 * **Bis zum 5. Oktober 2026 stand hier, auch der Platz sei überschreitbar**,
 * und die Prüfung fragte nach „darüber". Das ist er nicht: Die
 * Dateisystem-Quota setzt weiche und harte Grenze auf denselben Wert
 * ({@see DiskQuota::apply()}), und `repquota` zählt in
 * ganzen MB abgerundet. Der Verbrauch erreicht die Grenze also höchstens.
 * Gemeldet wurde deshalb nur, wenn jemand ein Kontingent **unter** den
 * Verbrauch herabsetzte, und nie, wenn ein Kunde seinen Platz füllte — also
 * genau dann nicht, wenn die Schreibzugriffe seiner Website zu scheitern
 * beginnen (`docs/141 §0`).
 *
 * > **Ein Kontingent, das erzwungen wird, wird nicht überschritten — es wird
 * > erreicht, und die Meldung gehört davor.**
 *
 * Entschieden hat der Betreiber am 5. Oktober 2026: **ab 95 % „fast
 * ausgeschöpft", entwarnt unter 90 %**, dieselben Zahlen wie bei „Platte
 * voll" für den Server. An der Grenze steht zusätzlich `disk_over`,
 * „ausgeschöpft" — ab hier scheitert jeder Schreibzugriff, und das ist eine
 * andere Auskunft als die Vorwarnung. Die Rückkehr unter 90 % hängt an den
 * eigenen Befunden vom vorigen Lauf, wie in {@see DiskSpace}.
 *
 * **Und die beiden werden getrennt entschieden, wie dort.** Auf der Grenze
 * stehen beide da; fällt der Platz wieder auf 92 %, geht „ausgeschöpft", und
 * die Vorwarnung bleibt mit ihrem `first_seen_at`. Beim Bauen schlossen sie
 * einander aus, und das hat das Ausschreiben des Abnahmelaufs gefunden
 * (`docs/141 §0` Befund 7): Ein voller Platz entwarnte die Vorwarnung. Das
 * Meldeziel bekam „erledigt" in dem Augenblick, in dem die Website nicht
 * mehr schreiben konnte, und wer danach Platz freiräumte, bekam die
 * Vorwarnung als neuen Befund und nach der Haltezeit eine zweite Mail.
 *
 * > **Ein Befund, den ein schwererer ablöst, ist nicht erledigt — und wer ihn
 * > dabei schliesst, meldet eine Entwarnung für einen Zustand, der schlimmer
 * > geworden ist.**
 *
 * > **Eine Haltezeit ohne Rückweg macht aus einem Wert, der an der Grenze
 * > pendelt, einen, der nie meldet** — oder einen, der jede zweite Nacht
 * > meldet, und das ist bei einer Mail an den Kunden schlimmer.
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
        'quota.exceeded' => ['disk_near_limit', 'disk_over', 'databases_over', 'traffic_over', 'traffic_unknown'],
    ];

    /** Ab hier ist der Platz eines Abonnements fast ausgeschöpft (Betreiber, 5. Oktober 2026). */
    public const DISK_WARN_PERCENT = 95;

    /** Erst darunter geht die Vorwarnung wieder — der Rückweg. */
    public const DISK_RELEASE_PERCENT = 90;

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

        /*
         * **Das Gedächtnis des Rückwegs sind die eigenen Zeilen vom letzten
         * Lauf** — und nur die der Vorwarnung. Keine Prüfung liest, was eine
         * andere geschrieben hat; dieselbe Regel wie in {@see DiskSpace}.
         * „Ausgeschöpft" braucht kein Gedächtnis: Auf der Grenze steht die
         * Vorwarnung immer daneben.
         */
        $vorher = Finding::query()
            ->where('check', FindingCheck::QuotaExceeded->value)
            ->where('reason', 'disk_near_limit')
            ->pluck('subject')
            ->map(static fn (mixed $s): string => (string) $s)
            ->all();

        $this->tenancy->withoutRestriction(function () use ($measuredAt, $vorher, &$findings): void {
            foreach ($this->subscriptions() as $subscription) {
                $name = (string) $subscription->name;

                foreach ($this->overruns($subscription, $measuredAt, in_array($name, $vorher, true)) as $finding) {
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
    private function overruns(Subscription $subscription, Carbon $measuredAt, bool $platzVorher): array
    {
        $out = self::disk($subscription->disk_used_mb, $subscription->quota(Quota::DiskMb->value), $platzVorher);

        $datenbanken = $this->over($subscription->databaseUsedMb(), $subscription->quota(Quota::DatabaseMb->value));

        if ($datenbanken !== null) {
            $out[] = ['reason' => 'databases_over', 'detail' => self::megabytes(...$datenbanken)];
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
     * Der Platz: fast ausgeschöpft, dazu ausgeschöpft — oder nichts.
     *
     * **Getrennt entschieden** (siehe Kopf): Die Vorwarnung hat ihre Schwelle
     * und ihren Rückweg, „ausgeschöpft" steht **an der Grenze und nicht erst
     * darüber**, weil die Quota den Verbrauch dort anhält. Auf der Grenze
     * stehen also beide da. Leer bei jeder Unklarheit, aus denselben Gründen
     * wie {@see self::over()}: kein Messwert ist nicht „0 belegt", und eine
     * Grenze von 0 ist keine ({@see self::limit()}).
     *
     * @param  bool  $vorher  stand für dieses Abonnement im vorigen Lauf die Vorwarnung?
     * @return list<array{reason: string, detail: string}>
     */
    public static function disk(?int $used, mixed $limit, bool $vorher): array
    {
        $grenze = self::limit($limit);

        if ($used === null || $grenze === null) {
            return [];
        }

        $prozent = $used / $grenze * 100;
        $out = [];

        if ($prozent >= self::DISK_WARN_PERCENT || ($vorher && $prozent >= self::DISK_RELEASE_PERCENT)) {
            $out[] = ['reason' => 'disk_near_limit', 'detail' => sprintf(
                '%s (%s %%)',
                self::megabytes((float) $used, $grenze),
                number_format(floor($prozent * 10) / 10, 1, ',', '.'),
            )];
        }

        if ($used >= $grenze) {
            $out[] = ['reason' => 'disk_over', 'detail' => self::megabytes((float) $used, $grenze)];
        }

        return $out;
    }

    /**
     * Liegt ein gemessener Wert über seiner Grenze?
     *
     * `null` bei **jeder** Unklarheit: kein Messwert oder keine Grenze
     * ({@see self::limit()}).
     *
     * @return array{0: float, 1: float}|null gemessen und Grenze
     */
    private function over(int|float|null $used, mixed $limit): ?array
    {
        $grenze = self::limit($limit);

        if ($grenze === null || $used === null) {
            return null;
        }

        return (float) $used > $grenze ? [(float) $used, $grenze] : null;
    }

    /**
     * Die Grenze eines Kontingents — `null`, wenn es keine ist.
     *
     * Keine Grenze ist ein fehlender Wert und eine von 0 oder weniger: Sie
     * heisst im Katalog „unbegrenzt" oder „nicht angeboten" (siehe
     * `Quota::allowsUnlimited()`), und gegen sie zu vergleichen machte aus
     * jedem Kunden einen Überschreiter.
     *
     * **Eine Stelle für den Platz und für die gemessenen Kontingente.** Seit
     * dem 5. Oktober 2026 fragen das zwei Methoden. Beim Bauen stand dieselbe
     * Bedingung in beiden wörtlich da; gefunden hat es das Bruchskript, dessen
     * Eingriff sie nicht mehr eindeutig fand.
     */
    private static function limit(mixed $limit): ?float
    {
        return is_numeric($limit) && (float) $limit > 0.0 ? (float) $limit : null;
    }

    private static function megabytes(float $used, float $limit): string
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
