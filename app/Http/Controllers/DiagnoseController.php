<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Support\Diagnose\RunLog;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Was an diesem Server nicht stimmt (A10 Schritt 7, `docs/98 §8`).
 *
 * ## Die Seite fragt nichts — sie liest, was der Nachtlauf geschrieben hat
 *
 * Kein Aufruf an den Agenten, keine Messung beim Öffnen. Der Lauf hat eine
 * Frist von 1800 Sekunden; was so lange dauern darf, gehört an einen Timer und
 * nicht an eine Anfrage, auf die jemand wartet.
 *
 * ## Der Wortlaut der Werkzeuge gehört dem Betreiber
 *
 * `docs/98 §9` Frage 5, mit **b** entschieden: Der Administrator sieht die
 * Liste — `subject` nennt den Ort, und der Satz zum Grund ist unsere
 * Formulierung. `detail` trägt den ungekürzten Wortlaut: bei php-fpm Poolnamen
 * und Pfade, bei nginx Zertifikatspfade, und in einem verwalteten Bereich die
 * Regeln fremder Kunden. Das ist dieselbe Art Inhalt, deretwegen `/logs` dem
 * Betreiber allein gehört.
 *
 * **Gefiltert wird hier und nicht auf der Seite.** Ein `v-if` im Browser
 * verbärge den Text; senden würde ihn das Panel trotzdem, und er stünde im
 * Payload jeder Antwort.
 *
 * > **Was der Betrachter nicht sehen darf, wird nicht ausgeblendet, sondern
 * > nicht geschickt.**
 */
final class DiagnoseController extends Controller
{
    public function show(RunLog $runs): Response
    {
        // **`operate-server` und nicht der Kontotyp.** Dieselbe Policy, die
        // `/logs` bewacht — eine zweite Fassung wäre die, die veraltet.
        $wortlaut = Gate::allows('operate-server');

        return Inertia::render('Diagnose/Index', [
            'findings' => fn (): array => $this->findings($wortlaut),

            /*
             * **Wann zuletzt gemessen wurde, kommt nicht aus den Befunden.**
             * Ein `ok` erzeugt keine Zeile; auf einem heilen Server ist die
             * Tabelle leer, und dann gäbe es kein `measured_at`. Punkt 1 des
             * Abnahmekriteriums verlangt die Angabe aber genau für diesen Fall.
             */
            'ran_at' => fn (): ?string => Clock::displayText($runs->lastRunAt()),

            // Ob der Betrachter den Wortlaut überhaupt bekommt. Die Seite sagt
            // es dem Administrator, statt eine leere Spalte zu zeigen: Eine
            // Lücke ohne Erklärung liest sich wie ein Fehler.
            'verbatim' => $wortlaut,
        ]);
    }

    /**
     * Die Befunde, nach Schwere und dann nach Ort.
     *
     * **Sortiert wird in PHP und nicht in SQL.** Die Schwere hängt am Grund und
     * steht mit Absicht in keiner Spalte (`FindingCheck::state()`); ein
     * `ORDER BY` darüber bräuchte sie als Feld, und das wäre die zweite Fassung
     * derselben Regel.
     *
     * @return list<array<string, mixed>>
     */
    private function findings(bool $wortlaut): array
    {
        $zeilen = [];

        foreach (Finding::query()->orderBy('check')->orderBy('subject')->get() as $finding) {
            $check = $finding->check;
            $state = $finding->state();

            $zeile = [
                'id' => $finding->id,
                'check' => $check->value,
                'check_label' => $check->label(),
                'subject' => $finding->subject,
                'subject_label' => $check->subjectLabel(),
                'reason' => $finding->reason,
                'sentence' => $finding->sentence(),
                'state' => $state->value,
                'state_label' => $state->label(),
                'badge' => $state->badge(),
                'rank' => $state->rank(),

                // **„Steht seit" und nicht „gefunden am".** Der Wert bleibt
                // beim ersten Lauf stehen, in dem der Schaden auftrat — auch
                // wenn der Wortlaut des Werkzeugs sich seitdem geändert hat
                // (Punkt 8 des Abnahmekriteriums).
                'first_seen_at' => Clock::display($finding->first_seen_at),
                'measured_at' => Clock::display($finding->measured_at),
            ];

            if ($wortlaut) {
                $zeile['detail'] = $finding->detail;
            }

            $zeilen[] = $zeile;
        }

        usort($zeilen, static function (array $a, array $b): int {
            return [$b['rank'], $a['check'], $a['subject']] <=> [$a['rank'], $b['check'], $b['subject']];
        });

        return $zeilen;
    }
}
