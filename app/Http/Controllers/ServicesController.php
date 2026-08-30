<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die Dienste und Timer dieses Servers — A2.
 *
 * ## Was die Seite beantwortet, und was die Übersicht nicht konnte
 *
 * Die Titelseite zeigt drei tragende Dienste. Der Katalog kennt sechzehn, und
 * neun der eigenen zwölf tauchten bis zum 30. August 2026 in keiner Anzeige
 * auf — `srvpanel-worker` konnte stillstehen, und man sah es an hängenden
 * Vorgängen statt an einer Zeile.
 *
 * ## Die Timer eigens
 *
 * Ein Timer ist kein Dienst mit weniger Feldern, sondern etwas anderes: Er hat
 * keine PID, keinen Neustartzähler und keinen Startzeitpunkt, dafür einen
 * nächsten Termin. In einer gemeinsamen Tabelle stünden bei ihm drei Spalten
 * leer und eine bei allen anderen — deshalb zwei Bereiche.
 *
 * Der Zustand, um den es geht: **`ActiveState` steht beim gesunden wie beim
 * kaputten Timer auf `active`** (`docs/89 §3`). Ein Timer ohne nächsten Termin
 * ist abgeschaltet und sieht aus wie eingeschaltet; die Seite sagt das mit
 * Worten und nicht mit einer Zahl, die man deuten muss.
 *
 * ## Warum `inspect-server` und nicht `operate-server`
 *
 * Dieselbe Teilung wie auf der Updates-Seite: Der Administrator **sieht** den
 * Zustand, gedreht wird mit `operate-server`. Hier wird nichts gedreht — diese
 * Stufe liest —, und was zu sehen ist, trägt kein Geheimnis des Betreibers:
 * Unitnamen stehen im Katalog, die Beschreibungen kommen von systemd.
 */
final class ServicesController extends Controller
{
    public function show(Client $agent): Response
    {
        // Jede Angabe als Verschluss, damit ein Nachladen nur holt, was es
        // verlangt — dieselbe Regel wie auf der Übersicht, und
        // `PartialReloadTest` hält sie.
        $antwort = null;

        $lesen = function () use ($agent, &$antwort): array {
            if ($antwort === null) {
                try {
                    $antwort = $agent->call('system.units.list', []);
                } catch (AgentException $fehler) {
                    $antwort = ['units' => [], 'error' => $fehler->getMessage()];
                }
            }

            return $antwort;
        };

        return Inertia::render('Services/Index', [
            'services' => fn (): array => $this->von($lesen(), 'service'),
            'timers' => fn (): array => $this->von($lesen(), 'timer'),

            // `live` sagt, ob überhaupt jemand geantwortet hat. Ohne das wäre
            // eine leere Liste von „nichts installiert" nicht zu unterscheiden.
            'live' => fn (): bool => ! isset($lesen()['error']),
            'error' => fn (): ?string => is_string($lesen()['error'] ?? null) ? $lesen()['error'] : null,
        ]);
    }

    /**
     * Die Zeilen einer Art, in der Reihenfolge des Katalogs.
     *
     * @param  array<string,mixed>  $antwort
     * @return list<array<string,mixed>>
     */
    private function von(array $antwort, string $art): array
    {
        $zeilen = is_array($antwort['units'] ?? null) ? $antwort['units'] : [];

        $gefiltert = array_values(array_filter(
            $zeilen,
            static fn (mixed $z): bool => is_array($z) && ($z['kind'] ?? null) === $art,
        ));

        return array_map(static function (array $z): array {
            // **Der Termin wird hier zu Text und nicht im Browser.** `Clock` ist
            // die einzige Stelle, die aus UTC eine Anzeige macht (`docs/40`);
            // ein `toLocaleString` auf der Seite nähme die Zone des Betrachters
            // und wäre eine zweite Fassung dieser Entscheidung.
            $sekunden = $z['next_elapse'] ?? null;

            $z['next_elapse'] = is_int($sekunden)
                ? Clock::display(CarbonImmutable::createFromTimestampUTC($sekunden))
                : null;

            return $z;
        }, $gefiltert);
    }
}
