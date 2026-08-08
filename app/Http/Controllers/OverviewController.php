<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Enums\DatabaseStatus;
use App\Enums\DomainStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Metrics\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die Übersicht — zwei verschiedene Seiten unter einer Adresse.
 *
 * **Die Verzweigung steht hier, nicht in der Vorlage.** Eine gemeinsame Seite
 * mit `v-if="istAdmin"` sähe kürzer aus und wäre die schlechtere Lösung: Die
 * Serverwerte — Rechnername, Kernel, Auslastung, Dienstzustände — würden dann
 * an jeden Browser geschickt und dort nur nicht angezeigt. Wer die Antwort
 * ansieht, läse sie trotzdem.
 *
 * Deshalb entscheidet der Server, was er überhaupt erhebt. Ein Kunde bekommt
 * die Daten nicht ausgeblendet, sondern gar nicht erst.
 */
final class OverviewController extends Controller
{
    public function __invoke(Request $request, Client $agent, Store $store): Response
    {
        $account = $request->user();

        if ($account instanceof Account && ! $account->isAdmin()) {
            return $this->forCustomer($account);
        }

        // Ein Aufruf, nicht drei: `system.info` liefert alles auf einmal, und
        // jeder weitere wäre ein Verbindungsaufbau zum Agenten für Werte, die
        // schon dastehen.
        $info = $this->systemInfo($agent);

        return Inertia::render('Overview', [
            'server' => $this->server($info),
            'hosting' => $this->hosting(),
            'tiles' => $this->tiles($store, $this->cores($info)),
            'services' => $this->services($agent),
            'filesystems' => $this->filesystems($info),
            'processes' => $this->processes($info),
        ]);
    }

    /**
     * Der Bestand: Kunden, Abonnements, Domains, Datenbanken, Verbrauch.
     *
     * **Warum das auf die Übersicht gehört.** Sie zeigte bis August 2026
     * ausschliesslich die Maschine — Auslastung, Dienste, Dateisysteme,
     * Prozesse. Das ist die halbe Auskunft: Ein Betreiber öffnet sein Panel
     * nicht, um zu erfahren, wie viel RAM belegt ist, sondern um zu sehen, ob
     * mit dem, was er hostet, etwas nicht stimmt. Kunden und Abonnements gab
     * es nur auf ihren eigenen Listenseiten, und dort sieht man sie erst,
     * wenn man den Verdacht schon hat.
     *
     * **Gezählt wird in der Datenbank und nicht in PHP.** Bei zwanzig
     * Abonnements ist das gleichgültig, bei zweitausend nicht — und die
     * Übersicht ist die Seite, die jeder Aufruf des Panels zuerst lädt. Vier
     * Zählungen sind vier `GROUP BY` und nicht vier geladene Tabellen.
     *
     * **Domains und Datenbanken kamen mit P5 dazu**, und der Grund ist derselbe
     * wie für die beiden anderen: Ein Betreiber sieht auf der Übersicht, was er
     * hostet. Bis dahin standen Kunden und Abonnements da, und das Gehostete
     * selbst — die Namen, unter denen jemand erreichbar ist, und die Daten
     * dahinter — fand man nur, wenn man den Verdacht schon hatte.
     *
     * @return array<string, mixed>
     */
    private function hosting(): array
    {
        $customers = $this->countByStatus(Customer::query());
        $subscriptions = $this->countByStatus(Subscription::query());
        $domains = $this->countByStatus(Domain::query());
        $databases = $this->countByStatus(Database::query());

        return [
            'customers' => [
                'total' => (int) $customers->sum(),
                'suspended' => (int) ($customers[CustomerStatus::Suspended->value] ?? 0),
            ],
            'subscriptions' => [
                'total' => (int) $subscriptions->sum(),
                'active' => (int) ($subscriptions[SubscriptionStatus::Active->value] ?? 0),
                'suspended' => (int) ($subscriptions[SubscriptionStatus::Suspended->value] ?? 0),
                'provisioning' => (int) ($subscriptions[SubscriptionStatus::Provisioning->value] ?? 0),
            ],

            /*
             * **Gezählt wird alles, was die verlinkte Liste auch zeigt.** Beide
             * Seiten führen auch, was zu keinem Abonnement mehr gehört — eine
             * Datenbank, deren Rückbau steckengeblieben ist, steht dort als
             * verwaist (docs/36 §5). Sie hier auszunehmen hiesse: Die Zahl auf
             * der Übersicht und die Zahl der Zeilen dahinter gehen auseinander,
             * und zwar genau dann, wenn etwas nicht stimmt.
             */
            'domains' => [
                'total' => (int) $domains->sum(),
                'active' => (int) ($domains[DomainStatus::Active->value] ?? 0),
                'suspended' => (int) ($domains[DomainStatus::Suspended->value] ?? 0),
                'provisioning' => (int) ($domains[DomainStatus::Provisioning->value] ?? 0),
            ],
            'databases' => [
                'total' => (int) $databases->sum(),
                'active' => (int) ($databases[DatabaseStatus::Active->value] ?? 0),
                'provisioning' => (int) ($databases[DatabaseStatus::Provisioning->value] ?? 0),
                'removing' => (int) ($databases[DatabaseStatus::Removing->value] ?? 0),
            ],

            'storage' => $this->storage(),
        ];
    }

    /**
     * Wie viele je Zustand — eine Abfrage statt einer geladenen Tabelle.
     *
     * Herausgelöst, als aus zwei Zählungen vier wurden: Viermal dieselben drei
     * Zeilen nebeneinander sind die Sorte Abschrift, bei der die vierte
     * irgendwann `count(*)` ohne `groupBy` macht und niemandem auffällt.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<array-key, mixed>
     */
    private function countByStatus(Builder $query): Collection
    {
        return $query
            ->selectRaw('status, count(*) as anzahl')
            ->groupBy('status')
            ->pluck('anzahl', 'status');
    }

    /**
     * Die Abonnements, die ihrer Speichergrenze am nächsten sind.
     *
     * **Nicht die grössten, sondern die vollsten.** Ein Abonnement mit 40 GB
     * Verbrauch und 200 GB Kontingent ist unauffällig; eines mit 4,8 GB und 5
     * GB ist der Anruf von morgen. Sortiert wird deshalb nach dem Verhältnis,
     * und das kennt erst {@see Subscription::diskUsagePercent()} — es hängt an
     * `quota_overrides` und am Plan und lässt sich in SQL nicht ohne Weiteres
     * ausdrücken.
     *
     * Deshalb kommt hier die einzige Stelle, an der doch in PHP gerechnet
     * wird: Geladen werden nur Abonnements, für die überhaupt eine Messung
     * vorliegt, mit ihrem Plan — und aus denen die fünf vollsten. Der
     * Unterschied zu „alles laden" ist, dass ein Server ohne Messung nichts
     * lädt.
     *
     * @return list<array<string, mixed>>
     */
    private function storage(): array
    {
        return Subscription::query()
            ->with('plan')
            ->whereNotNull('disk_used_mb')
            ->orderByDesc('disk_used_mb')
            ->limit(50)
            ->get()
            ->map(static fn (Subscription $subscription): array => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
                'used_mb' => (int) $subscription->disk_used_mb,
                'percent' => $subscription->diskUsagePercent(),
                'measured_at' => $subscription->disk_usage_measured_at?->toDateTimeString(),
            ])
            ->filter(static fn (array $row): bool => $row['percent'] !== null)
            ->sortByDesc('percent')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * Die Kundenübersicht.
     *
     * In P1 zeigt sie die Abonnements — und die Liste ist leer, solange keine
     * angelegt sind. Genau das steht in der Abnahmebedingung: Der Kunde meldet
     * sich an und sieht seine (leere) Übersicht. Eine leere Liste mit einem
     * Satz dazu ist eine Auskunft; eine weiße Fläche wäre keine.
     */
    private function forCustomer(Account $account): Response
    {
        $subscriptions = Subscription::query()
            ->whereIn('id', $account->accessibleSubscriptionIds())
            ->orderBy('name')
            ->get()
            ->map(static fn (Subscription $subscription): array => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
                'main_domain' => $subscription->main_domain,
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
            ])
            ->all();

        return Inertia::render('CustomerOverview', [
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * `system.info` einmal holen — oder die Begründung, warum nicht.
     *
     * @return array{ok:bool,data:array<string,mixed>,error:string}
     */
    private function systemInfo(Client $agent): array
    {
        try {
            return ['ok' => true, 'data' => $agent->call('system.info'), 'error' => ''];
        } catch (AgentException $error) {
            // Die Übersicht bleibt bedienbar, wenn der Agent schweigt — sie
            // sagt dann, dass er schweigt. Eine weiße Seite mit Stacktrace
            // wäre die schlechtere Auskunft über denselben Zustand.
            return ['ok' => false, 'data' => [], 'error' => $error->getMessage()];
        }
    }

    /**
     * @param  array{ok:bool,data:array<string,mixed>,error:string}  $result
     * @return array<string,mixed>
     */
    private function server(array $result): array
    {
        if (! $result['ok']) {
            return ['reachable' => false, 'error' => $result['error']];
        }

        $info = $result['data'];
        $distribution = is_array($info['distribution'] ?? null) ? $info['distribution'] : [];

        return [
            'reachable' => true,
            'hostname' => $info['hostname'] ?? '',
            'distribution' => trim(($distribution['name'] ?? '').' '.($distribution['version'] ?? '')),
            'kernel' => $info['kernel'] ?? '',
            'uptime_s' => (int) ($info['uptime_s'] ?? 0),
        ];
    }

    /**
     * Wie viele Kerne der Server hat — für die Schwelle der Load.
     *
     * **Der Agent zählt sie längst** (`SystemInfo::cpu()['cores']`), und
     * benutzt hat sie bisher niemand. Eine feste Zahl wäre hier besonders
     * falsch: Load 4 heisst auf vier Kernen „ausgelastet" und auf
     * zweiunddreissig „langweilt sich". Ohne Angabe des Agenten die vier aus
     * dem Muster (docs/entwuerfe/31) — dort hatte der erfundene Server vier.
     *
     * @param  array{ok:bool,data:array<string,mixed>,error:string}  $result
     */
    private function cores(array $result): int
    {
        $cpu = $result['ok'] && is_array($result['data']['cpu'] ?? null) ? $result['data']['cpu'] : [];

        return max(1, (int) ($cpu['cores'] ?? 4));
    }

    /**
     * Die fünf Kacheln — und ab wann ihre Kurve warnt.
     *
     * **Die Schwellen stehen hier und nicht in der Komponente.** Dieselbe
     * Begründung wie beim `tight` der Dateisysteme weiter unten: Ab wann eine
     * Auslastung eng ist, ist eine Aussage über den Betrieb und keine über die
     * Darstellung. Die Zahlen sind die des bedienten Musters
     * (docs/entwuerfe/31-kontor-mockup.html), das der Betreiber abgenommen
     * hat; die Load rechnet zusätzlich mit der wirklichen Kernzahl.
     *
     * **Der Schreibdurchsatz bekommt keine.** Es gibt für ihn keine Zahl, die
     * auf zwei Servern dasselbe bedeutet: Eine NVMe schreibt zwei Gigabyte je
     * Sekunde, ein Netzlaufwerk hundert Megabyte. Eine Schwelle, die überall
     * gilt, warnt entweder ständig oder nie — und das ist schlechter als
     * keine. Deshalb `null`, und deshalb steht es hier als Satz und nicht als
     * fehlende Zeile.
     *
     * @param  int  $cores  Kerne des Servers; die Load teilt sich durch sie auf
     * @return list<array<string,mixed>>
     */
    private function tiles(Store $store, int $cores): array
    {
        // 900 Mbit/s aus dem Muster, in Byte je Sekunde — die Kennzahl wird in
        // Byte gemessen, und ein Vergleich zweier Einheiten ist keiner.
        $networkLimit = 900 * 1_000_000 / 8;

        $cpu = $store->series('cpu', 2, 0, 60, ' %', 0, 85.0);
        $ram = $store->series('ram', 2, 0, 60, ' %', 0, 85.0);
        $load = $store->series('load', 3, 0, 60, '', 2, (float) $cores);

        /*
         * Beide Richtungen, aus derselben Datei: Spalte 0 ist eingehend,
         * Spalte 1 ausgehend.
         *
         * **Ausgehend stand hier neun Monate ungenutzt.** Der Sammler schreibt
         * seit P0 beide Spalten, die Kachel zeigte eine — und die Beizeile
         * „eingehend" war die einzige Stelle, an der stand, dass die andere
         * fehlt. Auf einem Webserver ist ausgehend ausserdem die Richtung, die
         * zuerst an die Grenze stösst: Eine Seite auszuliefern kostet ein
         * Vielfaches dessen, was ihre Anforderung kostet. Gezeigt wurde also
         * die ruhigere der beiden.
         *
         * **Dieselbe Schwelle für beide, getrennt gerechnet.** Die Leitung ist
         * dieselbe, aber sie kann in eine Richtung voll und in die andere leer
         * sein; eine gemeinsame Warnung liesse offen, welche Richtung sie
         * meint.
         *
         * Die Einheit steht jetzt an den Stützstellen. Ohne sie las sich die
         * Ablesung zweier Kurven als „09:14 · 5.730 · 1.204" — drei Zahlen
         * ohne Angabe, was gemessen wurde.
         */
        $network = $store->pair('network', 2, 0, 1, 60, $networkLimit);

        // Auch der Schreibdurchsatz in Grössenordnungen — sonst stünde auf
        // derselben Reihe „62,9 MB/s" neben „1.160.000 B/s". Seine Schwelle
        // bleibt `null`, aus dem Grund weiter oben.
        $io = $store->series('disk_io', 2, 1, 60, '', 0, null, bytes: true);

        return [
            [
                'key' => 'cpu',
                'label' => 'CPU',
                'value' => $this->latest($cpu, '—'),
                'unit' => '%',
                'subline' => 'Auslastung insgesamt',
                'series' => $cpu,
            ],
            [
                'key' => 'ram',
                'label' => 'RAM',
                'value' => $this->latest($ram, '—'),
                'unit' => '%',
                'subline' => 'belegt',
                'series' => $ram,
            ],
            [
                'key' => 'load',
                'label' => 'Load',
                'value' => $this->latest($load, '—'),
                'unit' => '',
                'subline' => 'eine Minute',
                'series' => $load,
            ],
            [
                'key' => 'network',
                'label' => 'Netz',
                'value' => $this->latest($network['first'], '—'),
                'unit' => $network['first']['unit'],
                'subline' => 'eingehend',
                'series' => $network['first'],
                'second' => [
                    'label' => 'ausgehend',
                    'value' => $this->latest($network['second'], '—'),
                    'unit' => $network['second']['unit'],
                    'series' => $network['second'],
                ],
            ],
            [
                'key' => 'disk_io',
                'label' => 'IO',
                'value' => $this->latest($io, '—'),
                'unit' => $io['unit'],
                'subline' => 'geschrieben',
                'series' => $io,
            ],
        ];
    }

    /**
     * @param  array{ok:bool,data:array<string,mixed>,error:string}  $result
     * @return list<array<string,mixed>>
     */
    private function filesystems(array $result): array
    {
        $rows = $result['data']['filesystems'] ?? null;
        $rows = is_array($rows) ? $rows : [];
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $out[] = [
                'mount' => (string) ($row['mount'] ?? ''),
                'device' => (string) ($row['device'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'total' => $this->bytes((int) ($row['total'] ?? 0)),
                'free' => $this->bytes((int) ($row['free'] ?? 0)),
                'percent' => (float) ($row['percent'] ?? 0),
                // Die Schwelle steht hier und nicht in der Vorlage: Wann ein
                // Dateisystem eng wird, ist eine Aussage über den Betrieb und
                // keine über die Darstellung.
                'tight' => (float) ($row['percent'] ?? 0) >= 85.0,
            ];
        }

        return $out;
    }

    /**
     * @param  array{ok:bool,data:array<string,mixed>,error:string}  $result
     * @return list<array<string,mixed>>
     */
    private function processes(array $result): array
    {
        $rows = $result['data']['processes'] ?? null;
        $rows = is_array($rows) ? $rows : [];
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $out[] = [
                'pid' => (int) ($row['pid'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'rss' => $this->bytes((int) ($row['rss'] ?? 0)),
                'state' => (string) ($row['state'] ?? ''),
                'user' => (int) ($row['user'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Bytes in etwas, das ein Mensch liest.
     *
     * Mit 1024 und den Kürzeln KiB/MiB: Ein Datenträger, den der Hersteller
     * mit 500 GB bewirbt, meldet sich beim Kernel mit 465 GiB, und wer beide
     * Einheiten mischt, erklärt die Differenz später jedem Kunden einzeln.
     */
    private function bytes(int $value): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $size = (float) $value;
        $step = 0;

        while ($size >= 1024 && $step < count($units) - 1) {
            $size /= 1024;
            $step++;
        }

        return number_format($size, $step === 0 ? 0 : 1, ',', '.').' '.$units[$step];
    }

    /** @param array{has:bool,points:list<array{x:float,y:float,t:string,v:string}>} $series */
    private function latest(array $series, string $fallback): string
    {
        if (! $series['has'] || $series['points'] === []) {
            return $fallback;
        }

        $letzter = $series['points'][count($series['points']) - 1];

        return trim(str_replace(['%', ' '], '', $letzter['v'])) === '' ? $fallback : trim(explode(' ', $letzter['v'])[0]);
    }

    /** @return list<array<string,mixed>> */
    private function services(Client $agent): array
    {
        $units = ['srvpanel-agentd.service', 'nginx.service', 'mariadb.service'];
        $rows = [];

        foreach ($units as $unit) {
            try {
                $rows[] = $agent->call('service.status', ['unit' => $unit]);
            } catch (AgentException $error) {
                $rows[] = [
                    'unit' => $unit,
                    'present' => false,
                    'active_state' => 'unbekannt',
                    'description' => $error->getMessage(),
                ];
            }
        }

        return $rows;
    }
}
