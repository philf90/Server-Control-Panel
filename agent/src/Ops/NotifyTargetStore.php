<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Notify\Providers;
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
        /*
         * **Alle vier Angaben, und das stand hier bis zum 21. September 2026
         * nicht so.** `provider` reiste vom Formular bis hierher und wurde
         * verworfen; {@see Target::store()} fiel auf seinen Vorgabewert zurück,
         * und wer Slack wählte, bekam die JSON-Form und von Slack ein `400`.
         * Gemessen durch diese Operation: `provider: slack` hinein,
         * `provider: generic` abgelegt.
         *
         * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so
         * > gut wie keine.**
         *
         * Kein Wächter konnte es sehen: {@see \Tests\Unit\WebhookTransportTest}
         * ruft `Target::store()` unmittelbar und kommt hier nie vorbei.
         *
         * > **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst
         * > geschriebenen Wert füttern, prüfen die Naht nicht.**
         */
        $this->target->store(
            $args['url'] ?? null,
            $args['secret'] ?? null,
            $args['provider'] ?? Providers::GENERIC,
            $args['config'] ?? [],
        );

        // Zurück geht, was auch auf der Seite stehen darf — siehe
        // {@see Target::describe()}. Die Adresse ist ein halbes Geheimnis und
        // bleibt hier.
        return ['stored' => true, ...($this->target->describe() ?? [])];
    }
}
