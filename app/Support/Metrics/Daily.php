<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\DailyMetric;
use App\Models\Database;
use App\Models\Domain;
use App\Models\DomainMetric;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\AccessCounts;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Was der Nachtlauf gezählt hat, in die verdichtete Tabelle (B3, `docs/129 §6`).
 *
 * **Überschreibend und nicht addierend**, und das ist keine Vorsichtsmassnahme,
 * sondern die Bedingung dafür, dass die Zahlen stimmen: Derselbe Tag kann mehr
 * als einmal vorbeikommen — ein Lauf von Hand, ein nachgeholter über
 * `Persistent=true`.
 *
 * > **Ein Lauf, der denselben Tag mehrfach sieht, darf ihn nicht mehrfach
 * > zählen.**
 *
 * **Und Überschreiben ist nur richtig, wenn jede Sicht vollständig ist.** Bis
 * zum 24. September 2026 kam ein Tag an **mehreren Nächten** vorbei, und die
 * spätere Sicht war die unvollständigere: Sein Kopf lag da schon in einer
 * Datei, die niemand las (`docs/134 §0` Punkt 2). Seitdem liest der Agent drei
 * Dateien, und das Panel legt nur den Vortag ab (`AccessCounts::split()`);
 * `TrafficRotationTest` misst es in allen vier Reihenfolgen zweier Nächte.
 *
 * > **Ein Lauf, der denselben Tag mehrfach sieht und überschreibt, behält die
 * > letzte Sicht — und die letzte ist nicht die vollständigste.**
 *
 * Geschrieben wird mit `upsert()` über den eindeutigen Schlüssel der Tabelle.
 * Dass der trägt, hängt daran, dass keine seiner Spalten `NULL` sein kann — die
 * Messung dazu steht in der Migration.
 *
 * **Der Agent kennt Verzeichnisnamen, das Panel kennt Zeilen.** Er zählt, was
 * unter `/var/www/vhosts` wirklich dasteht; welches Abonnement und welche
 * Domain das sind, weiss nur die Datenbank. Was sich nicht auflösen lässt, wird
 * **gemeldet und nicht übergangen**: Ein Verzeichnis ohne Zeile ist entweder
 * ein Rest eines Rückbaus oder ein Abonnement, das dem Panel fehlt, und beides
 * gehört auf den Tisch.
 *
 * > **Ein übersprungener Eintrag, den niemand zählt, ist von einem, den es nie
 * > gab, nicht zu unterscheiden.**
 *
 * **Die Klammer wird hier ausdrücklich gelöst.** Der Nachtlauf läuft ohne
 * angemeldetes Konto; ohne {@see Tenancy::withoutRestriction()} stünde jede
 * Abfrage auf `whereRaw('0 = 1')` und der Lauf schriebe **wortlos nichts** —
 * derselbe Fehler, den `Cron::store()` in P6 hatte.
 *
 * ## Zwei Schreiber, und bis zum 28. September 2026 gab es einen
 *
 * {@see DailyMetric::ofASubscription()} nennt sechs Kennzahlen, und
 * {@see self::record()} schreibt vier — die, die das Zugriffsprotokoll
 * hergibt. Platz und Datenbanken las {@see History} für zwei der fünf
 * Kacheln, und niemand legte sie ab: Gemessen wurden sie alle fünfzehn
 * Minuten, aber nur als gegenwärtiger Wert (`docs/138 §0` Punkt 1). Kein
 * Wächter hat es gesehen, weil jeder seine Seite hielt — `DailyMetricsTest`
 * den Schreiber an seinen vier, `DailyHistoryTest` den Leser an Zeilen, die er
 * selbst anlegt.
 *
 * > **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst
 * > geschriebenen Wert füttern, prüfen die Naht nicht.**
 *
 * Seitdem schreibt {@see self::levels()} die beiden anderen, gerufen von
 * `srvpanel:usage`, und `DailyWriterTest` hält, dass jede Kennzahl, die gelesen
 * wird, auch einen Schreiber hat.
 *
 * **Die beiden unterscheiden sich im Tag, und das ist Absicht.** Ein **Fluss**
 * — Verkehr, Anfragen — ist erst am Ende des Tages eine Zahl; abgelegt wird
 * deshalb der Vortag. Ein **Stand** — belegter Platz — gilt ab der Messung;
 * abgelegt wird der laufende Tag, überschreibend, und am Abend steht dort die
 * letzte Messung des Tages. Über der Kachel „Speicherplatz" zeigt die Seite
 * den gegenwärtigen Wert, und eine Kachel darunter, die den von gestern nennt,
 * zeigte dieselbe Grösse in zwei Fassungen.
 */
final class Daily
{
    /**
     * Wie lange die verdichteten Zahlen bleiben.
     *
     * Entscheidung des Betreibers vom 20. September 2026 (`docs/129 §2`): roh
     * 14 Tage, verdichtet 30. Die rohen Dateien hält `logrotate` mit
     * `rotate 14`; diese Zahl hier ist die andere Hälfte.
     */
    public const RETENTION_DAYS = 30;

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Die zählbaren und die ruhigen Tage aus `web.access.count` ablegen.
     *
     * `$quiet` sind Domains, die der Agent ganz gelesen hat und die am Tag
     * keine einzige Zeile trugen ({@see AccessCounts::split()}). **Sie gehen
     * denselben Weg wie ein zählbarer Tag, mit vier Nullen** — ein ruhiger Tag
     * ist ein zählbarer mit null Anfragen und kein eigener Fall. Überschrieben
     * wird genauso, und dieselbe Grenze gilt: Zwei Rotationen an einem Tag
     * schieben einen Tag aus dem, was gelesen wird (`TrafficRotationTest`).
     *
     * `$gaps` sind die Domains, die an dem Tag gelesen und **nicht** gezählt
     * wurden — übersprungen oder nicht ganz gelesen. Sie legen nichts ab und
     * entscheiden eines: ob ein Abonnement, das an dem Tag nichts Zählbares
     * hatte, seine Null bekommt (siehe unten).
     *
     * @param  list<array{subscription:string, domain:string, day:string, requests:int, sent:int, received:int, errors:int}>  $countable
     * @param  list<array{subscription:string, domain:string, day:string}>  $quiet
     * @param  list<array{subscription:string, domain:string, day:string}>  $gaps
     * @return array{domains:int, subscriptions:int, unknown:list<array{subscription:string, domain:string}>}
     */
    public function record(array $countable, array $quiet = [], array $gaps = []): array
    {
        if ($countable === [] && $quiet === []) {
            return ['domains' => 0, 'subscriptions' => 0, 'unknown' => []];
        }

        return $this->tenancy->withoutRestriction(function () use ($countable, $quiet, $gaps): array {
            $subscriptions = Subscription::query()->pluck('id', 'name');
            $domains = Domain::query()->get(['id', 'subscription_id', 'name', 'created_at']);

            $domainRows = [];
            $sums = [];
            $unknown = [];

            /*
             * Je Abonnement und Tag: ob etwas Zählbares dabei war, und ob eine
             * seiner Domains gelesen und nicht gezählt wurde. Eine Lücke in
             * einem Verzeichnis, das das Panel nicht kennt, ist keine Lücke
             * eines Abonnements — sie gehört niemandem.
             */
            $gezaehlt = [];
            $luecke = [];

            foreach ($gaps as $eintrag) {
                $domain = self::resolve($eintrag, $subscriptions, $domains);

                if ($domain !== null) {
                    $luecke[$domain->subscription_id.'|'.$eintrag['day']] = true;
                }
            }

            $eintraege = [];

            foreach ($countable as $eintrag) {
                $eintraege[] = [$eintrag, false];
            }

            foreach ($quiet as $eintrag) {
                $eintraege[] = [$eintrag + ['requests' => 0, 'sent' => 0, 'received' => 0, 'errors' => 0], true];
            }

            foreach ($eintraege as [$eintrag, $ruhig]) {
                $domain = self::resolve($eintrag, $subscriptions, $domains);

                if ($domain === null) {
                    $unknown[] = [
                        'subscription' => $eintrag['subscription'],
                        'domain' => $eintrag['domain'],
                    ];

                    continue;
                }

                $subscriptionId = $domain->subscription_id;

                /*
                 * **Eine Null nur für einen Tag, an dem es die Domain schon
                 * gab.** Eine Domain, die nach dem Tag angelegt wurde, hat
                 * ein Protokoll ohne ihn — ruhig sieht das aus, gewesen ist es
                 * nicht. Eine Null für diesen Tag wäre der Anfang einer Kurve
                 * vor dem Anfang der Domain.
                 *
                 * Gefragt wird in UTC, ohne die Zone des Servers, und der
                 * Vorbehalt fällt in die sichere Richtung: Eine Domain, die am
                 * Tag selbst angelegt wurde, bekommt für ihn keine Null, auch
                 * wenn sie einen Teil davon erlebt hat — eine fehlende Null
                 * ist die bessere der beiden falschen Auskünfte. Eine, die
                 * nach dem Tag entstand, bekommt nie eine, in keiner Zone.
                 */
                if ($ruhig && ! self::existedBefore($domain, $eintrag['day'])) {
                    continue;
                }

                if (! $ruhig) {
                    $gezaehlt[$subscriptionId.'|'.$eintrag['day']] = true;
                }

                foreach ($this->values($eintrag) as $metric => $value) {
                    $domainRows[] = [
                        'subscription_id' => (int) $subscriptionId,
                        'domain_id' => $domain->id,
                        'day' => $eintrag['day'],
                        'metric' => $metric,
                        'value' => $value,
                    ];

                    /*
                     * **Das Abonnement ist die Summe über seine Domains und
                     * keine zweite Messung.** Es gibt keinen Zähler, der es
                     * unmittelbar zählte — nginx protokolliert je Domain. Wer
                     * hier eine zweite Quelle aufmachte, hätte zwei Zahlen über
                     * dieselbe Grösse, und die zweite liefe irgendwann weg.
                     */
                    $schluessel = $subscriptionId.'|'.$eintrag['day'].'|'.$metric;
                    $sums[$schluessel] = ($sums[$schluessel] ?? 0) + $value;
                }
            }

            $subscriptionRows = [];

            foreach ($sums as $schluessel => $value) {
                [$subscriptionId, $day, $metric] = explode('|', (string) $schluessel, 3);

                /*
                 * **Die Null eines Abonnements steht nur da, wo keine seiner
                 * Domains an dem Tag eine Lücke hatte.** Seine Summe über
                 * ruhige Domains ist null; war daneben eine übersprungen oder
                 * nicht ganz gelesen, sagt diese Null „nichts gewesen" über
                 * einen Tag, an dem etwas gewesen sein kann.
                 *
                 * Eine Summe mit Zählbarem bleibt dagegen stehen, wie sie vor
                 * der Null stand, auch neben einer Lücke. Wer sie wegen einer
                 * Lücke fallen liesse, verlöre bei einer einzigen kaputten
                 * Datei in einer ruhigen Domain — sie wird nie wieder gedreht
                 * und bleibt im Lesebereich — jeden weiteren Tag des ganzen
                 * Abonnements.
                 */
                $tag = $subscriptionId.'|'.$day;

                if (! isset($gezaehlt[$tag]) && isset($luecke[$tag])) {
                    continue;
                }

                $subscriptionRows[] = [
                    'subscription_id' => (int) $subscriptionId,
                    'day' => $day,
                    'metric' => $metric,
                    'value' => $value,
                ];
            }

            if ($domainRows !== []) {
                DomainMetric::query()->upsert($domainRows, ['domain_id', 'day', 'metric'], ['value']);
            }

            if ($subscriptionRows !== []) {
                SubscriptionMetric::query()->upsert($subscriptionRows, ['subscription_id', 'day', 'metric'], ['value']);
            }

            return [
                'domains' => count($domainRows),
                'subscriptions' => count($subscriptionRows),
                'unknown' => $unknown,
            ];
        });
    }

    /**
     * Die Stände eines Tages ablegen — Platz und Datenbanken (`docs/138 §6`
     * Frage 1, entschieden am 28. September 2026).
     *
     * Gerufen von `srvpanel:usage` nach jeder Messung, alle fünfzehn Minuten.
     * **Abgelegt wird der laufende Tag und überschreibend**: Bis zum Abend
     * steht dort die jüngste Messung, danach die letzte des Tages — dieselbe
     * Zahl, die die Seite über der Kachel als gegenwärtigen Wert zeigt. Der
     * Tag kommt vom Aufrufer, gerechnet in der Zone des Servers, wie beim
     * Nachtlauf.
     *
     * **Ein Tag bekommt nur, was an ihm gemessen wurde.** Gefragt wird der
     * Zeitpunkt der Messung, der an jedem Wert steht, umgerechnet in die Zone
     * des Servers. Fällt die Messung aus — keine Quota, ein Datenbankserver,
     * der nicht antwortet —, bleibt der Tag ohne Zeile, statt den Wert von
     * gestern als heutigen abzulegen:
     *
     * > **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine
     * > falsche Auskunft.**
     *
     * Für die Datenbanken heisst das: **jede** Datenbank des Abonnements an
     * diesem Tag gemessen, oder keine Zeile. Eine Summe aus einem frischen und
     * einem alten Wert wäre eine Zahl, die es nie gab. Ein Abonnement ohne
     * Datenbank bekommt eine Null — sie ist keine Messung, sie folgt aus der
     * Tabelle, und sie ist wahr.
     *
     * **Und die Summe ist hier kein zweiter Wahrheitsort.**
     * `App\Support\Databases\Usage` legt sie mit Absicht nicht ab, weil eine
     * abgelegte Summe auseinanderläuft, sobald eine Datenbank verschwindet.
     * Diese Zeile ist ein Verlauf: Sie sagt, was an jenem Tag galt, und der
     * laufende Tag wird mit der nächsten Messung neu geschrieben.
     *
     * @return array{disk:int, databases:int}
     */
    public function levels(string $day, DateTimeZone $zone): array
    {
        return $this->tenancy->withoutRestriction(function () use ($day, $zone): array {
            $amTag = static fn (?CarbonInterface $zeitpunkt): bool => $zeitpunkt !== null
                && $zeitpunkt->copy()->setTimezone($zone)->toDateString() === $day;

            $datenbanken = Database::query()
                ->get(['subscription_id', 'size_bytes', 'size_measured_at'])
                ->groupBy('subscription_id');

            $rows = [];
            $platz = 0;
            $summen = 0;

            foreach (Subscription::query()->get(['id', 'disk_used_mb', 'disk_usage_measured_at']) as $subscription) {
                if ($subscription->disk_used_mb !== null && $amTag($subscription->disk_usage_measured_at)) {
                    $rows[] = [
                        'subscription_id' => (int) $subscription->id,
                        'day' => $day,
                        'metric' => DailyMetric::DiskMb->value,
                        'value' => max(0, (int) $subscription->disk_used_mb),
                    ];

                    $platz++;
                }

                $eigene = $datenbanken->get($subscription->id) ?? collect();

                if ($eigene->every(fn (Database $d): bool => $d->size_bytes !== null && $amTag($d->size_measured_at))) {
                    $rows[] = [
                        'subscription_id' => (int) $subscription->id,
                        'day' => $day,
                        'metric' => DailyMetric::DatabaseBytes->value,
                        'value' => max(0, (int) $eigene->sum('size_bytes')),
                    ];

                    $summen++;
                }
            }

            if ($rows !== []) {
                SubscriptionMetric::query()->upsert($rows, ['subscription_id', 'day', 'metric'], ['value']);
            }

            return ['disk' => $platz, 'databases' => $summen];
        });
    }

    /**
     * Was älter ist als die Aufbewahrung, geht.
     *
     * **Gerechnet wird vom übergebenen Tag und nicht von `now()`.** Derselbe
     * Bestand an zwei Läufen desselben Tages muss dasselbe Ergebnis geben;
     * `MaintenanceOverdue` hat denselben Griff aus demselben Grund.
     *
     * @return array{subscriptions:int, domains:int}
     */
    public function forget(string $today): array
    {
        $grenze = Carbon::parse($today)->subDays(self::RETENTION_DAYS)->toDateString();

        return $this->tenancy->withoutRestriction(fn (): array => [
            'subscriptions' => SubscriptionMetric::query()->where('day', '<', $grenze)->delete(),
            'domains' => DomainMetric::query()->where('day', '<', $grenze)->delete(),
        ]);
    }

    /**
     * Die vier Kennzahlen, die eine Zeile des Zählers hergibt.
     *
     * **Die Fehlerquote steht nicht dabei**, und das ist Absicht: Sie ist keine
     * ganze Zahl, und aus `requests` und `errors` lässt sie sich jederzeit
     * rechnen. Zwei abgelegte Zahlen, aus denen die dritte folgt, sind besser
     * als drei, von denen eine veralten kann.
     *
     * @param  array{requests:int, sent:int, received:int, errors:int}  $eintrag
     * @return array<string, int>
     */
    private function values(array $eintrag): array
    {
        return [
            DailyMetric::TrafficSentBytes->value => $eintrag['sent'],
            DailyMetric::TrafficReceivedBytes->value => $eintrag['received'],
            DailyMetric::Requests->value => $eintrag['requests'],
            DailyMetric::Errors->value => $eintrag['errors'],
        ];
    }

    /**
     * Die Zeile zu einem Verzeichnis des Agenten — oder `null`.
     *
     * **Gesucht wird das Paar und nicht der Name.** Zwei Kunden dürfen
     * denselben Domainnamen im Verzeichnis haben, solange ihn nur einer
     * betreibt — sonst liefen fremde Zahlen ins falsche Abonnement. Eine
     * Stelle für die drei Fragen, die {@see self::record()} stellt: zählbar,
     * ruhig und Lücke. Stünde die Suche dreimal da, suchte die dritte
     * irgendwann anders.
     *
     * @param  array{subscription:string, domain:string}  $eintrag
     * @param  Collection<array-key, mixed>  $subscriptions
     * @param  Collection<int, Domain>  $domains
     */
    private static function resolve(array $eintrag, Collection $subscriptions, Collection $domains): ?Domain
    {
        $subscriptionId = $subscriptions[$eintrag['subscription']] ?? null;

        if ($subscriptionId === null) {
            return null;
        }

        return $domains->first(
            fn (Domain $d): bool => $d->name === $eintrag['domain']
                && $d->subscription_id === $subscriptionId,
        );
    }

    /**
     * Ob es die Domain vor dem Ende des Tages schon gab — gefragt in UTC.
     *
     * Die Tage der Tabelle sind Kalendertage des Servers, `created_at` steht
     * in UTC. Liegt das UTC-Datum der Anlage vor dem Tag, gab es die Domain
     * vor dessen Ende, in jeder Zone. Liegt es auf ihm oder danach, heisst die
     * Antwort nein — auch für eine Domain, die einen Teil des Tages erlebt
     * hat. Ein unbekannter Zeitpunkt heisst ebenfalls nein.
     */
    private static function existedBefore(Domain $domain, string $day): bool
    {
        return $domain->created_at !== null && $domain->created_at->toDateString() < $day;
    }
}
