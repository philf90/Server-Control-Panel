<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/**
 * Was eine Operation zur Verfügung hat: den Runner, das Protokoll und einen
 * Rückkanal für Fortschritt und Ausgabe.
 *
 * Der Rückkanal ist der Grund, warum ein Vorgang im Panel zusehen kann, statt
 * am Ende ein Ergebnis vorgesetzt zu bekommen. Er ist bewusst ein Callback und
 * kein Rückgabewert: Eine Operation, die zehn Minuten läuft, soll nach zehn
 * Sekunden etwas gesagt haben.
 */
final class Kontext
{
    /** @param callable(array<string,mixed>):void $senden */
    public function __construct(
        public readonly Runner $runner,
        public readonly Journal $journal,
        private $senden,
    ) {}

    public function fortschritt(int $prozent, string $text): void
    {
        ($this->senden)([
            'type' => 'progress',
            'pct' => max(0, min(100, $prozent)),
            'text' => $text,
        ]);
    }

    public function ausgabe(string $kanal, string $zeile): void
    {
        ($this->senden)([
            'type' => 'log',
            'stream' => $kanal === 'stderr' ? 'stderr' : 'stdout',
            'line' => $zeile,
        ]);
    }

    /** Ein Runner, dessen Ausgabe unterwegs an die Anwendung geht. */
    public function laufend(string $programm, array $argumente, int $zeitlimit = 60): Ergebnis
    {
        return $this->runner->run(
            $programm,
            $argumente,
            $zeitlimit,
            fn (string $kanal, string $zeile) => $this->ausgabe($kanal, $zeile),
        );
    }
}
