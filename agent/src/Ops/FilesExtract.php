<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Archive;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Ein Archiv im Abonnement entpacken.
 *
 * **Das Archiv liegt bereits im Abonnement** und wird nicht mitgeschickt. Damit
 * steht in `operations.payload` ein Pfad und kein Inhalt — dieselbe Regel wie
 * beim Datenbankmanagement (`docs/46 §12`), und der Grund, warum dieser Vorgang
 * über die Warteschlange laufen darf, obwohl die acht anderen es nicht tun.
 *
 * **Zip-Slip verlässt das Abonnement nicht** — das hält das Chroot, nicht diese
 * Datei. Was {@see Archive::normalise()} verhindert, ist die Verlegung
 * *innerhalb*: Ein Archiv, das nach `httpdocs/` entpackt wird, hat in `.ssh/`
 * nichts zu suchen. Übersprungene Einträge werden **gemeldet** und nicht
 * verschwiegen; ein Archiv, von dem die Hälfte fehlt, ohne dass es jemand
 * sagt, ist schlimmer als eines, das gar nicht entpackt.
 */
final class FilesExtract implements Op
{
    public static function name(): string
    {
        return 'files.extract';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? null);
        $target = Workspace::path($args['target'] ?? dirname($path), 'target');

        $context->progress(10, 'Archiv prüfen');

        $result = $workspace->run(static function () use ($path, $target): array {
            $entry = Entry::of($path);

            if ($entry === null) {
                throw new AgentException(AgentException::NOT_FOUND, 'Das Archiv gibt es nicht.', ['path' => $path]);
            }

            if ($entry['type'] !== 'file') {
                throw AgentException::badRequest('Nur eine Datei lässt sich entpacken.', ['path' => $path]);
            }

            return Archive::extract($path, $target);
        });

        $context->progress(100, 'fertig');

        return [
            'path' => $path,
            'target' => $target,
            'entries' => $result['entries'],
            'written' => $result['written'],
            'bytes' => $result['bytes'],

            // Die übersprungenen Einträge stehen mit Namen da. Sie sind der
            // Befund, den ein Kunde braucht, um zu verstehen, warum sein
            // Archiv unvollständig ausgepackt wurde.
            'skipped' => $result['skipped'],

            // Und die, die an einem Verweis im Weg hängengeblieben sind. Sie
            // standen zuerst in keiner der beiden Listen — der Lauf meldete
            // „0 geschrieben, 0 übersprungen", und das ist keine Auskunft,
            // sondern ein Rätsel.
            'redirected' => $result['redirected'],
        ];
    }
}
