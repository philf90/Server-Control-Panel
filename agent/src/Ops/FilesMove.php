<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Umbenennen und Verschieben — beides ist dieselbe Bewegung.
 *
 * **Nicht überschreiben.** `rename()` ersetzt das Ziel wortlos, wenn es
 * existiert; über einen Dateimanager wäre das ein verlorener Inhalt ohne
 * Rückfrage. Geprüft wird vorher, und ja, zwischen Prüfung und `rename` liegt
 * ein Zeitfenster — hier ist es harmlos: Beide Seiten liegen im Chroot, und wer
 * es ausnutzt, überschreibt sich seine eigene Datei.
 *
 * > **Ein Zeitfenster ist erst dann ein Loch, wenn dahinter etwas liegt, das
 * > einem anderen gehört.**
 */
final class FilesMove implements Op
{
    public static function name(): string
    {
        return 'files.move';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $from = Workspace::path($args['from'] ?? null, 'from');
        $to = Workspace::path($args['to'] ?? null, 'to');

        if ($from === '/' || $to === '/') {
            throw AgentException::denied('Die Wurzel des Abonnements wird nicht verschoben.');
        }

        if ($from === $to) {
            throw AgentException::badRequest('Quelle und Ziel sind dasselbe.', ['path' => $from]);
        }

        // Ein Verzeichnis in sich selbst zu verschieben ist der Fehler, der
        // einen Baum unerreichbar macht statt ihn zu bewegen.
        if (str_starts_with($to.'/', $from.'/')) {
            throw AgentException::badRequest('Ein Verzeichnis lässt sich nicht in sich selbst verschieben.', [
                'from' => $from,
                'to' => $to,
            ]);
        }

        return $workspace->run(static function () use ($from, $to): array {
            if (Entry::of($from) === null) {
                throw new AgentException(AgentException::NOT_FOUND, 'Die Quelle gibt es nicht.', ['from' => $from]);
            }

            if (Entry::of($to) !== null) {
                throw AgentException::badRequest('Am Ziel steht schon etwas.', ['to' => $to]);
            }

            if (! is_dir(dirname($to))) {
                throw new AgentException(AgentException::NOT_FOUND, 'Das Zielverzeichnis gibt es nicht.', [
                    'to' => dirname($to),
                ]);
            }

            if (! @rename($from, $to)) {
                throw AgentException::denied('Der Eintrag liess sich nicht verschieben.');
            }

            clearstatcache(true, $to);

            return ['entry' => Entry::of($to), 'from' => $from];
        });
    }
}
