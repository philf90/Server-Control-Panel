<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Subscription;
use App\Support\Metrics\Store;
use Illuminate\Http\Request;
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
            'tiles' => $this->tiles($store),
            'services' => $this->services($agent),
            'filesystems' => $this->filesystems($info),
            'processes' => $this->processes($info),
        ]);
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

    /** @return list<array<string,mixed>> */
    private function tiles(Store $store): array
    {
        $cpu = $store->series('cpu', 2, 0, 60, ' %', 0);
        $ram = $store->series('ram', 2, 0, 60, ' %', 0);
        $load = $store->series('load', 3, 0, 60, '', 2);
        // Spalte 1 ist bei beiden die abgehende Richtung. Gezeigt wird die
        // eingehende — sie ist die, die man auf einem Webserver zuerst
        // ansieht; der Verlauf beider steht in derselben Datei.
        $network = $store->series('network', 2, 0, 60, '', 0);
        $io = $store->series('disk_io', 2, 1, 60, '', 0);

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
                'value' => $this->latest($network, '—'),
                'unit' => 'B/s',
                'subline' => 'eingehend',
                'series' => $network,
            ],
            [
                'key' => 'disk_io',
                'label' => 'IO',
                'value' => $this->latest($io, '—'),
                'unit' => 'B/s',
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
