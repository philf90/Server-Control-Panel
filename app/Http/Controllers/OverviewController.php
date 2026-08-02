<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Metrics\Store;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die Adminübersicht — die erste Fläche, die es gibt, und der Nachweis, dass
 * der Weg Browser → Anwendung → Agent → System steht.
 *
 * Sie hat in P0 noch keine Rechteprüfung, weil es noch keine Konten gibt; das
 * kommt in P1 und bringt Policies für jede Route mit.
 */
final class OverviewController extends Controller
{
    public function __invoke(Client $agent, Store $store): Response
    {
        return Inertia::render('Overview', [
            'server' => $this->server($agent),
            'tiles' => $this->tiles($store),
            'services' => $this->services($agent),
        ]);
    }

    /** @return array<string,mixed> */
    private function server(Client $agent): array
    {
        try {
            $info = $agent->call('system.info');
        } catch (AgentException $error) {
            // Die Übersicht bleibt bedienbar, wenn der Agent schweigt — sie
            // sagt dann, dass er schweigt. Eine weiße Seite mit Stacktrace
            // wäre die schlechtere Auskunft über denselben Zustand.
            return ['reachable' => false, 'error' => $error->getMessage()];
        }

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
        ];
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
