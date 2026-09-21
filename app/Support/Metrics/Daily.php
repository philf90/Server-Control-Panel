<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\DailyMetric;
use App\Models\Domain;
use App\Models\DomainMetric;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;

/**
 * Was der Nachtlauf gezählt hat, in die verdichtete Tabelle (B3, `docs/129 §6`).
 *
 * **Überschreibend und nicht addierend**, und das ist keine Vorsichtsmassnahme,
 * sondern die Bedingung dafür, dass die Zahlen stimmen: `web.access.count`
 * liest `access.log` **und** `access.log.1`, weil `logrotate` in einem Fenster
 * läuft und nicht zu einer Uhrzeit. Derselbe Tag kommt deshalb an mehreren
 * Nächten vorbei.
 *
 * > **Ein Lauf, der denselben Tag mehrfach sieht, darf ihn nicht mehrfach
 * > zählen.**
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
     * Die zählbaren Tage aus `web.access.count` ablegen.
     *
     * @param  list<array{subscription:string, domain:string, day:string, requests:int, sent:int, received:int, errors:int}>  $countable
     * @return array{domains:int, subscriptions:int, unknown:list<array{subscription:string, domain:string}>}
     */
    public function record(array $countable): array
    {
        if ($countable === []) {
            return ['domains' => 0, 'subscriptions' => 0, 'unknown' => []];
        }

        return $this->tenancy->withoutRestriction(function () use ($countable): array {
            $subscriptions = Subscription::query()->pluck('id', 'name');
            $domains = Domain::query()->get(['id', 'subscription_id', 'name']);

            $domainRows = [];
            $sums = [];
            $unknown = [];

            foreach ($countable as $eintrag) {
                $subscriptionId = $subscriptions[$eintrag['subscription']] ?? null;
                $domain = $domains->first(
                    fn (Domain $d): bool => $d->name === $eintrag['domain']
                        && $d->subscription_id === $subscriptionId,
                );

                if ($subscriptionId === null || $domain === null) {
                    $unknown[] = [
                        'subscription' => $eintrag['subscription'],
                        'domain' => $eintrag['domain'],
                    ];

                    continue;
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
}
