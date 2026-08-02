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
final class Context
{
    /** @param callable(array<string,mixed>):void $send */
    public function __construct(
        public readonly Runner $runner,
        public readonly Journal $journal,
        private $send,
    ) {}

    public function progress(int $percent, string $text): void
    {
        ($this->send)([
            'type' => 'progress',
            'pct' => max(0, min(100, $percent)),
            'text' => $text,
        ]);
    }

    public function output(string $channel, string $line): void
    {
        ($this->send)([
            'type' => 'log',
            'stream' => $channel === 'stderr' ? 'stderr' : 'stdout',
            'line' => $line,
        ]);
    }

    /** Ein Runner, dessen Ausgabe unterwegs an die Anwendung geht. */
    public function stream(string $program, array $args, int $timeout = 60): Result
    {
        return $this->runner->run(
            $program,
            $args,
            $timeout,
            fn (string $channel, string $line) => $this->output($channel, $line),
        );
    }
}
