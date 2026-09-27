<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FindingCheck;
use App\Models\Finding;
use App\Support\Diagnose\Run;
use App\Support\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nachsehen, wie voll die Dateisysteme sind (`docs/136`).
 *
 * `srvpanel-disk.timer` ruft dieses Kommando alle fünf Minuten; von Hand fährt
 * es der Betreiber, wenn er nach dem Aufräumen wissen will, ob der Befund fort
 * ist.
 *
 * ## Warum es ein eigenes Kommando ist und nicht `srvpanel:diagnose`
 *
 * Weil es eine eigene Unit ist, mit eigenem Takt: Die Bestandsdiagnose läuft
 * nachts, und bei voller Platte stürzt MariaDB beim nächsten Wachsen einer
 * Tabelle ab (`docs/136 §3` M5). Ein Nachtlauf erführe davon bis zu einen Tag
 * zu spät. Dasselbe Muster wie {@see VerifyBackups}, aus dem umgekehrten Grund:
 * Jene Prüfung ist teuer, diese dringend.
 *
 * ## Der Rückgabewert sagt etwas über den Lauf und nicht über die Platten
 *
 * Eine volle Platte ist kein gescheiterter Lauf — sonst stünde die Unit genau
 * dann auf `failed`, wenn sie ihre Arbeit tut. Dieselbe Regel und derselbe
 * Grund wie in {@see Diagnose}.
 *
 * > **Ein Rückgabewert, der einen gefundenen Schaden als Fehlschlag meldet,
 * > macht aus dem Boten den Schuldigen.**
 */
final class WatchDisks extends Command
{
    protected $signature = 'srvpanel:disk';

    protected $description = 'Prüft, wie voll die Dateisysteme sind — Platz und Inodes';

    public function handle(Run $run): int
    {
        /*
         * **`Run` kommt hier mit `Catalog::DISK_CHECKS`** und nicht mit den
         * Prüfungen der Nacht — über eine kontextuelle Bindung in
         * `SrvPanelServiceProvider`, wie bei {@see VerifyBackups}.
         * `DiagnoseWiringTest` hält es an der Wirkung.
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
     * Was jetzt zu den Dateisystemen dasteht — je Befund eine Zeile.
     *
     * **Nur die eigenen Befunde.** Dieser Lauf hat die übrigen Schlüssel nicht
     * angefasst; sie mitzuzählen hiesse, eine Zahl auszugeben, für die er nicht
     * geradestehen kann.
     *
     * @return list<string>
     */
    private function zusammenfassung(): array
    {
        $befunde = Finding::query()
            ->where('check', FindingCheck::DiskSpace->value)
            ->orderBy('subject')
            ->orderBy('reason')
            ->get();

        if ($befunde->isEmpty()) {
            return ['Keine Befunde an den Dateisystemen.'];
        }

        return $befunde
            ->map(static fn (Finding $f): string => sprintf(
                '%s: %s — %s',
                $f->state()->label(),
                $f->subject,
                $f->detail ?? $f->check->sentence($f->reason),
            ))
            ->values()
            ->all();
    }
}
