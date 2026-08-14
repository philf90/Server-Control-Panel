<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Packer;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Einen Baum im Abonnement zu einem Zip packen.
 *
 * **Zip und nicht Tar**, und der Grund ist eine Eigenschaft dieser Umgebung:
 * `phar.readonly` steht auf `1` — die Voreinstellung jeder Distribution —, und
 * damit kann `PharData` lesen und nicht schreiben. Das umzustellen hiesse, dem
 * Agenten das Schreiben von Phar-Archiven überhaupt zu erlauben, und das ist
 * eine weitere Vollmacht für einen kleineren Gewinn als ein Dateiformat.
 *
 * Entpacken kann trotzdem beides ({@see FilesExtract}) — `phar.readonly` sperrt
 * nur den Schreibweg.
 */
final class FilesCompress implements Op
{
    public static function name(): string
    {
        return 'files.compress';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $source = Workspace::path($args['path'] ?? null);
        $target = Workspace::path($args['target'] ?? null, 'target');

        if ($source === '/') {
            throw AgentException::badRequest('Die Wurzel des Abonnements wird nicht gepackt.');
        }

        $context->progress(10, 'packen');

        $result = $workspace->run(static function () use ($source, $target): array {
            if (Entry::of($source) === null) {
                throw new AgentException(AgentException::NOT_FOUND, 'Die Quelle gibt es nicht.', ['path' => $source]);
            }

            if (Entry::of($target) !== null) {
                throw AgentException::badRequest('Am Ziel steht schon etwas.', ['target' => $target]);
            }

            return Packer::zip($source, $target);
        });

        $context->progress(100, 'fertig');

        return ['path' => $source, 'target' => $target] + $result;
    }
}
