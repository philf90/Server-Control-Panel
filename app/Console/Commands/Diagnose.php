<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FindingState;
use App\Models\Finding;
use App\Support\Diagnose\Run;
use App\Support\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Die Bestandsdiagnose fahren (A10 Schritt 6, `docs/98 §4`).
 *
 * Der Nachtlauf ruft dieses Kommando über `srvpanel-diagnose.timer`; von Hand
 * fährt es der Betreiber, wenn er wissen will, ob eine Behebung gewirkt hat.
 *
 * **Der Rückgabewert sagt etwas über den Lauf und nicht über den Server.** Ein
 * Server mit Befunden ist kein gescheiterter Lauf — sonst stünde die Unit nach
 * dem ersten `fail` dauerhaft auf `failed`, und die Diagnose meldete sich
 * selbst als Schaden. `1` bedeutet: Eine Prüfung ist gar nicht durchgelaufen.
 *
 * > **Ein Rückgabewert, der einen gefundenen Schaden als Fehlschlag meldet,
 * > macht aus dem Boten den Schuldigen.**
 */
final class Diagnose extends Command
{
    protected $signature = 'srvpanel:diagnose';

    protected $description = 'Prüft den Bestand des Servers und schreibt die Befunde fort';

    public function handle(Run $run): int
    {
        // **Der Zeitpunkt entsteht hier und nicht in den Prüfungen.** Alle
        // Befunde eines Laufs tragen denselben; „zuletzt gemessen" ist damit
        // eine Frage mit einer Antwort.
        $ergebnis = $run->all(Carbon::now());

        $this->line(sprintf(
            '%d Prüfung(en) gefahren, %s.',
            count($ergebnis['ran']),
            Clock::display($ergebnis['measured_at']) ?? '–',
        ));

        foreach ($this->zusammenfassung() as $zeile) {
            $this->line($zeile);
        }

        if ($ergebnis['failed'] === []) {
            return self::SUCCESS;
        }

        foreach ($ergebnis['failed'] as $pruefung => $meldung) {
            $this->error(sprintf('%s ist nicht durchgelaufen: %s', class_basename($pruefung), $meldung));
        }

        return self::FAILURE;
    }

    /**
     * Was jetzt dasteht, nach Schwere.
     *
     * Gezählt wird über {@see FindingCheck::state()} und nicht über eine Spalte
     * — die gibt es nicht, und zwar mit Absicht: Sie wäre die zweite Fassung
     * derselben Regel.
     *
     * @return list<string>
     */
    private function zusammenfassung(): array
    {
        $nach = [];

        foreach (Finding::query()->get() as $finding) {
            $zustand = $finding->state();
            $nach[$zustand->value] = ($nach[$zustand->value] ?? 0) + 1;
        }

        if ($nach === []) {
            return ['Keine Befunde.'];
        }

        $zeilen = [];

        foreach (FindingState::cases() as $zustand) {
            $anzahl = $nach[$zustand->value] ?? 0;

            if ($anzahl > 0) {
                $zeilen[] = sprintf('%s: %d', $zustand->label(), $anzahl);
            }
        }

        return $zeilen;
    }
}
