<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Notify\Target;
use SrvPanel\Agent\Op;

/**
 * Das Meldeziel dieses Servers hinterlegen — B1, `docs/129 §7`.
 *
 * **Der eine Weg, auf dem Adresse und Geheimnis den Socket überqueren, und der
 * einzige.** Danach kennt die Anwendung nur noch den Rechnernamen; zurück kommt
 * hier schon nichts anderes.
 *
 * **Kein Vorgang in der Warteschlange.** Ein eingereihter Vorgang legt seine
 * Argumente in `operations.payload` ab — dieselbe Überlegung wie bei
 * {@see DnsCredentialStore} und {@see CertificateUpload}. Der Aufrufer ruft
 * unmittelbar über `Client::call`.
 */
final class NotifyTargetStore implements Op
{
    public function __construct(private readonly Target $target = new Target) {}

    public static function name(): string
    {
        return 'notify.target.store';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $this->target->store($args['url'] ?? null, $args['secret'] ?? null);

        // Zurück geht, was auch auf der Seite stehen darf — siehe
        // {@see Target::describe()}. Die Adresse ist ein halbes Geheimnis und
        // bleibt hier.
        return ['stored' => true, ...($this->target->describe() ?? [])];
    }
}
