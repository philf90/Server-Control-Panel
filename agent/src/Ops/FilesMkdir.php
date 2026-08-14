<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Ein Verzeichnis im Abonnement anlegen.
 *
 * Ohne `recursive`: Wer zwei Ebenen auf einmal anlegen will, hat sich in der
 * Regel vertippt, und ein versehentlich angelegter Zwischenpfad ist schwerer zu
 * bemerken als eine Fehlermeldung.
 */
final class FilesMkdir implements Op
{
    public static function name(): string
    {
        return 'files.mkdir';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? null);

        if ($path === '/') {
            throw AgentException::badRequest('Die Wurzel des Abonnements gibt es schon.');
        }

        return $workspace->run(static function () use ($path): array {
            if (Entry::of($path) !== null) {
                throw AgentException::badRequest('Dort steht schon etwas.', ['path' => $path]);
            }

            if (! is_dir(dirname($path))) {
                throw new AgentException(AgentException::NOT_FOUND, 'Das übergeordnete Verzeichnis gibt es nicht.', [
                    'path' => dirname($path),
                ]);
            }

            if (! @mkdir($path, 0o750)) {
                throw AgentException::denied('Das Verzeichnis liess sich nicht anlegen.');
            }

            return ['entry' => Entry::of($path)];
        });
    }
}
