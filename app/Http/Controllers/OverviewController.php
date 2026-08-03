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

        return Inertia::render('Overview', [
            'server' => $this->server($agent),
            'tiles' => $this->tiles($store),
            'services' => $this->services($agent),
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
