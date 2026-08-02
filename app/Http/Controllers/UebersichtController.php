<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Kennzahlen\Speicher;
use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Client;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Adminübersicht — die erste Fläche, die es gibt, und der Nachweis, dass
 * der Weg Browser → Anwendung → Agent → System steht.
 *
 * Sie hat in P0 noch keine Rechteprüfung, weil es noch keine Konten gibt; das
 * kommt in P1 und bringt Policies für jede Route mit.
 */
final class UebersichtController extends Controller
{
    public function __invoke(Client $agent, Speicher $speicher): Response
    {
        return Inertia::render('Uebersicht', [
            'server' => $this->server($agent),
            'kacheln' => $this->kacheln($speicher),
            'dienste' => $this->dienste($agent),
        ]);
    }

    /** @return array<string,mixed> */
    private function server(Client $agent): array
    {
        try {
            $info = $agent->ruf('system.info');
        } catch (AgentException $fehler) {
            // Die Übersicht bleibt bedienbar, wenn der Agent schweigt — sie
            // sagt dann, dass er schweigt. Eine weiße Seite mit Stacktrace
            // wäre die schlechtere Auskunft über denselben Zustand.
            return ['erreichbar' => false, 'fehler' => $fehler->getMessage()];
        }

        $distribution = is_array($info['distribution'] ?? null) ? $info['distribution'] : [];

        return [
            'erreichbar' => true,
            'hostname' => $info['hostname'] ?? '',
            'distribution' => trim(($distribution['name'] ?? '').' '.($distribution['version'] ?? '')),
            'kernel' => $info['kernel'] ?? '',
            'uptime_s' => (int) ($info['uptime_s'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function kacheln(Speicher $speicher): array
    {
        $cpu = $speicher->verlauf('cpu', 2, 0, 60, ' %', 0);
        $ram = $speicher->verlauf('ram', 2, 0, 60, ' %', 0);
        $load = $speicher->verlauf('load', 3, 0, 60, '', 2);

        return [
            [
                'schluessel' => 'cpu',
                'label' => 'CPU',
                'wert' => $this->letzter($cpu, '—'),
                'einheit' => '%',
                'unterzeile' => 'Auslastung insgesamt',
                'verlauf' => $cpu,
            ],
            [
                'schluessel' => 'ram',
                'label' => 'RAM',
                'wert' => $this->letzter($ram, '—'),
                'einheit' => '%',
                'unterzeile' => 'belegt',
                'verlauf' => $ram,
            ],
            [
                'schluessel' => 'load',
                'label' => 'Load',
                'wert' => $this->letzter($load, '—'),
                'einheit' => '',
                'unterzeile' => 'eine Minute',
                'verlauf' => $load,
            ],
        ];
    }

    /** @param array{hat:bool,punkte:list<array{x:float,y:float,t:string,v:string}>} $verlauf */
    private function letzter(array $verlauf, string $ersatz): string
    {
        if (! $verlauf['hat'] || $verlauf['punkte'] === []) {
            return $ersatz;
        }

        $letzter = $verlauf['punkte'][count($verlauf['punkte']) - 1];

        return trim(str_replace(['%', ' '], '', $letzter['v'])) === '' ? $ersatz : trim(explode(' ', $letzter['v'])[0]);
    }

    /** @return list<array<string,mixed>> */
    private function dienste(Client $agent): array
    {
        $units = ['cloudsrv-agentd.service', 'nginx.service', 'mariadb.service'];
        $zeilen = [];

        foreach ($units as $unit) {
            try {
                $zeilen[] = $agent->ruf('service.status', ['unit' => $unit]);
            } catch (AgentException $fehler) {
                $zeilen[] = [
                    'unit' => $unit,
                    'vorhanden' => false,
                    'aktiv' => 'unbekannt',
                    'beschreibung' => $fehler->getMessage(),
                ];
            }
        }

        return $zeilen;
    }
}
