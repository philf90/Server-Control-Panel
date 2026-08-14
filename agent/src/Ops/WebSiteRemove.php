<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\NginxApply;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Site;

/**
 * Eine Website vollständig entfernen: Server-Block, Include, Protokolle und —
 * auf Ansage — ihr Verzeichnis.
 *
 * **Das Verzeichnis geht immer mit, aber nur auf Ansage des Panels.** Das ist
 * kein Widerspruch: Der Betreiber hat entschieden, dass eine gelöschte Domain
 * ihre Dateien mitnimmt (sonst bliebe der Name belegt und niemand wüsste,
 * wovon). Ob *dieses* Verzeichnis aber nur zu *dieser* Domain gehört, weiss
 * allein das Panel — zwei Domains dürfen auf dasselbe DocumentRoot zeigen, und
 * dann nähme das Entfernen der einen der anderen die Dateien weg. Diese Frage
 * beantwortet der Bestand, nicht das Dateisystem; deshalb kommt sie als
 * Argument und wird hier nicht geraten.
 *
 * **Das DocumentRoot der Hauptdomain wird nie entfernt.** `httpdocs` ist Teil
 * des Verzeichnisschemas aus §4.5 und gehört dem Abonnement, nicht der Domain.
 * Es verschwindet mit `subscription.remove` und mit nichts sonst.
 *
 * **Wiederholbar.** Eine Website, die es nicht mehr gibt, ist der gewünschte
 * Zustand; der Aufruf meldet das und scheitert nicht. Sonst hinge ein
 * abgebrochener Löschvorgang für immer, weil sein zweiter Versuch an dem
 * scheitert, was der erste schon geschafft hat.
 */
final class WebSiteRemove implements Op
{
    public static function name(): string
    {
        return 'web.site.remove';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        // Ohne DocumentRoot gebaut: Beim Entfernen interessiert nur, wo die
        // Dateien liegen — und das steht in `document_root`, das hier
        // ausdrücklich fehlen darf.
        $site = Site::fromArgs([
            'subscription' => $args['subscription'] ?? null,
            'user' => $args['user'] ?? null,
            'domain' => $args['domain'] ?? null,
            'document_root' => $args['document_root'] ?? SubscriptionProvision::DOCUMENT_ROOT,
        ]);

        $removeRoot = ($args['remove_document_root'] ?? false) === true
            && ($args['document_root'] ?? null) !== null;

        $context->progress(20, 'Server-Block entfernen');

        $existed = is_file($site->confFile());

        NginxApply::commit($context, [], [$site->confFile(), $site->includeFile()]);

        $context->progress(85, 'Verzeichnisse');
        $removed = [];

        if (Filesystem::removeInside($site->logDir(), $site->subscriptionRoot(), $site->user)) {
            $removed[] = $site->logDir();
        }

        if ($removeRoot) {
            $documentRoot = $site->documentRootPath();

            if ($documentRoot === null) {
                throw AgentException::badRequest('Ohne DocumentRoot lässt sich keines entfernen.');
            }

            // Die Schranke, die das Verzeichnisschema schützt. `removeInside`
            // deckt „innerhalb des Abonnements" ab; dass `httpdocs` selbst
            // tabu ist, weiss nur diese Operation.
            if ($site->documentRoot === SubscriptionProvision::DOCUMENT_ROOT) {
                throw AgentException::denied(
                    'Das DocumentRoot der Hauptdomain gehört zum Abonnement und wird nur mit ihm entfernt.',
                );
            }

            if (Filesystem::removeInside($documentRoot, $site->subscriptionRoot(), $site->user)) {
                $removed[] = $documentRoot;
            }
        }

        $context->progress(100, 'fertig');

        return [
            'domain' => $site->domain,
            'existed' => $existed,
            'removed' => $removed,
        ];
    }
}
