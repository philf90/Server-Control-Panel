<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Disks;
use SrvPanel\Agent\Op;

/**
 * Wie voll die Platten sind — Platz und Inodes (`docs/136`).
 *
 * Gefragt von der Prüfung „Platte voll" alle fünf Minuten. **`system.info`
 * bleibt dafür unberührt**: Der Kennzahlensammler fragt es alle zehn Sekunden,
 * und dort gilt „kein Programmaufruf". Die Inodes kosten einen — 1,9 ms für drei
 * Pfade (`docs/136 §3` M6) —, und den zahlt nur, wer sie braucht.
 *
 * **Die Auswahl der Platten ist dieselbe wie in der Übersicht**, weil beide
 * {@see Disks} fragen. Eine Platte, die die Prüfung meldet und die Übersicht
 * nicht zeigt, gäbe es sonst irgendwann.
 */
final class SystemFilesystems implements Op
{
    public function __construct(private readonly string $procRoot = '/proc') {}

    public static function name(): string
    {
        return 'system.filesystems';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $lines = @file($this->procRoot.'/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            // Ohne die Liste der Einhängungen ist nichts gemessen — und eine
            // leere Antwort hiesse „keine Platte". Die Prüfung macht daraus
            // `unreachable` und nicht „alles in Ordnung".
            throw new AgentException(AgentException::NOT_FOUND, 'Die Liste der Einhängungen ist nicht lesbar.');
        }

        $platten = Disks::usage($lines);
        $inodes = $this->inodes($platten, $context);

        return [
            'filesystems' => array_map(
                static fn (array $platte): array => [...$platte, 'inodes' => $inodes[$platte['mount']] ?? null],
                $platten,
            ),
        ];
    }

    /**
     * Die Inodes aller Platten aus einem Aufruf.
     *
     * **Scheitert der Aufruf, bleiben die Inodes offen und der Platz nicht.**
     * Eine Platte, deren Belegung gemessen ist, verliert sie nicht dadurch, dass
     * die zweite Frage keine Antwort bekam; `null` heisst „nicht gemessen" und
     * nicht „0 %". Der Wortlaut bleibt im Protokoll des Agenten.
     *
     * @param  list<array{mount: string}>  $platten
     * @return array<string, array{total: int, free: int, percent: float}>
     */
    private function inodes(array $platten, Context $context): array
    {
        if ($platten === []) {
            return [];
        }

        try {
            $stat = $context->runner->run(
                'stat',
                ['-f', '-c', '%c %d %n', '--', ...array_column($platten, 'mount')],
                10,
            );
        } catch (AgentException $fehler) {
            $context->journal->write('stat -f nicht gefahren', ['message' => $fehler->getMessage()]);

            return [];
        }

        return Disks::inodes($stat);
    }
}
