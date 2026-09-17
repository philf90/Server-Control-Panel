<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use App\Models\Finding;
use App\Support\Diagnose\Run;
use App\Support\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Die Sicherungen prüfen (`docs/117 §6` Schritt 6).
 *
 * `srvpanel-backup-verify.timer` ruft dieses Kommando; von Hand fährt es der
 * Betreiber, wenn er einer Sicherung nicht traut.
 *
 * ## Warum es ein eigenes Kommando ist und nicht `srvpanel:diagnose`
 *
 * Weil es eine eigene Unit ist, und die hat einen eigenen Takt, eine eigene
 * Frist und eine eigene Speichergrenze. Der Bestandslauf kostet gemessen
 * **391 ms** (`docs/100` M19); dieser hier liest jedes Archiv des Servers von
 * der Platte.
 *
 * > **Ein `check`, der den Bestand des Kunden liest, gehört nicht in denselben
 * > Lauf wie einer, der eine Konfigurationsdatei prüft.** (`docs/81 §11`)
 *
 * ## Der Rückgabewert sagt etwas über den Lauf und nicht über die Sicherungen
 *
 * Eine kaputte Sicherung ist kein gescheiterter Lauf — sonst stünde die Unit
 * dauerhaft auf `failed`, und die Prüfung meldete sich selbst als Schaden.
 * Dieselbe Regel und derselbe Grund wie in {@see Diagnose}.
 *
 * > **Ein Rückgabewert, der einen gefundenen Schaden als Fehlschlag meldet,
 * > macht aus dem Boten den Schuldigen.**
 */
final class VerifyBackups extends Command
{
    protected $signature = 'srvpanel:backup-verify';

    protected $description = 'Prüft die Sicherungen auf Lesbarkeit und Vollständigkeit';

    public function handle(Run $run): int
    {
        /*
         * **`Run` kommt hier mit den Sicherungsprüfungen und nicht mit denen
         * der Bestandsdiagnose.** Der Unterschied steht in
         * `SrvPanelServiceProvider` als kontextuelle Bindung; dass sie auch bei
         * der Injektion in `handle()` greift, ist gemessen und wird von
         * `DiagnoseWiringTest` an der **Wirkung** gehalten — nicht von diesem
         * Kommentar.
         *
         * > **Ein Kommentar, der eine Zusage des Frameworks behauptet, ist
         * > keine Prüfung — er ist eine Zeile, die aussieht wie eine.**
         */
        $ergebnis = $run->all(Carbon::now());

        $this->line(sprintf(
            '%s, %s.',
            count($ergebnis['ran']) === 1 ? '1 Prüfung gefahren' : sprintf('%d Prüfungen gefahren', count($ergebnis['ran'])),
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
     * Was jetzt zu den Sicherungen dasteht, nach Schwere.
     *
     * **Nur die eigenen Befunde und nicht alle.** {@see Diagnose} zählt den
     * ganzen Bestand, weil es ihn eben gemessen hat; dieser Lauf hat die
     * übrigen Schlüssel nicht angefasst, und sie mitzuzählen hiesse, eine Zahl
     * auszugeben, für die er nicht geradestehen kann.
     *
     * > **Eine Zahl neben einer Messung sieht aus wie gemessen.**
     *
     * @return list<string>
     */
    private function zusammenfassung(): array
    {
        $nach = [];

        foreach (Finding::query()->where('check', FindingCheck::BackupFile->value)->get() as $finding) {
            $zustand = $finding->state();
            $nach[$zustand->value] = ($nach[$zustand->value] ?? 0) + 1;
        }

        if ($nach === []) {
            return ['Keine Befunde an den Sicherungen.'];
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
