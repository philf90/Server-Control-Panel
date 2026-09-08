<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Ports\ServerPorts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die Zeitpläne dieses Servers — A6 (`docs/111`).
 *
 * ## Was die Seite beantwortet
 *
 * Das Panel schreibt selbst nach `/etc/cron.d/srvpanel-*` und betreibt fünf
 * Timer. Was **sonst** auf diesem Server zeitgesteuert läuft, stand bis dahin
 * an keiner Stelle — und drei Zustände sehen auf der Platte gleich aus wie ein
 * heiler: ein Skript mit Punkt im Namen, ein Verzeichnis ohne Zeile in
 * `/etc/crontab`, eine Zeile mit anacron-Vorbehalt.
 *
 * ## Warum `operate-server` und nicht `inspect-server`
 *
 * `docs/111 §2` Frage 1, entschieden vom Betreiber am 7. September 2026. Anders
 * als eine Portnummer oder eine Paketfassung ist eine Cron-Zeile **beliebiger
 * Text, den root geschrieben hat** — ein Pfad, ein Skriptname, im schlechtesten
 * Fall ein Zugangsdatum in einem Argument. Das ist dieselbe Art Inhalt,
 * deretwegen `/logs` dem Betreiber allein gehört.
 *
 * Damit fällt der Schnitt in der Nutzlast weg, den `/services` für die
 * Prozessspalte braucht ({@see ServerPorts}): Wer diese
 * Seite überhaupt sieht, darf alles darauf sehen.
 *
 * ## Warum eine eigene Seite und kein Bereich auf `/services`
 *
 * Dort stehen seit A2 Dienste und Timer und seit A3 Ports und Regelwerk; ein
 * vierter und fünfter Bereich machten aus der Seite eine Halde. Und „was läuft
 * zeitgesteuert" ist eine andere Frage als „was läuft".
 */
final class SchedulesController extends Controller
{
    public function show(Request $request, Client $agent): Response
    {
        // Ein Verschluss, damit ein partielles Nachladen nur holt, was es
        // verlangt — dieselbe Regel wie auf der Übersicht, und
        // `PartialReloadTest` hält sie.
        $antwort = null;

        $lesen = function () use ($agent, &$antwort): array {
            if ($antwort === null) {
                try {
                    $antwort = $agent->call('system.cron', []);
                } catch (AgentException $fehler) {
                    /*
                     * **`readable: false` und nicht eine leere Liste.** Ein
                     * stiller Agent und ein Server ohne Zeitpläne sähen sonst
                     * gleich aus, und der zweite kommt nicht vor: `/etc/crontab`
                     * gehört zum Paket.
                     */
                    $antwort = [
                        'readable' => false,
                        'reason' => 'unreachable',
                        'anacron' => false,
                        'tables' => [],
                        'directories' => [],
                    ];
                }
            }

            return $antwort;
        };

        return Inertia::render('Schedules/Index', [
            'cron' => fn (): array => $lesen(),
        ]);
    }
}
