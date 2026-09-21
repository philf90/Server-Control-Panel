<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Notify\Delivery;
use SrvPanel\Agent\Op;

/**
 * Eine Meldung an das Meldeziel zustellen — der zweite Kanal aus `docs/129 §7`.
 *
 * **Die Adresse kommt nicht von aussen, sondern aus der Ablage des Agenten.**
 * Das ist der ganze Grund, aus dem der Webhook hier liegt und nicht im Panel:
 * Wer eine Adresse nach draussen wählen darf, wählt sonst auch
 * `http://127.0.0.1:…` — und das Panel spräche als `srvpanel` mit jedem Dienst
 * dieses Servers (Grenze 1).
 *
 * **Kein Vorgang in der Warteschlange, und diesmal aus einem zweiten Grund.**
 * Der erste ist der gewohnte: `$args` landete in `operations.payload`. Der
 * zweite ist der Nachtlauf selbst — er meldet, was er gerade gemessen hat, und
 * ein eingereihter Vorgang zöge die Zustellung hinter den nächsten
 * Warteschlangendurchlauf. Ein Befund, der eine Stunde später zugestellt wird,
 * ist als Meldung über einen toten Dienst eine Stunde zu spät.
 *
 * **`mutating() === true`, obwohl auf diesem Server nichts anders wird.** Die
 * Fahne steuert die Protokollierung, und eine Meldung nach draussen ist genau
 * das, was in `agent.log` stehen muss: Sie ist von aussen nicht mehr
 * zurückzunehmen.
 *
 * > **Ein Vorgang, der nichts am System ändert und trotzdem nicht
 * > zurückzunehmen ist, gehört ins Protokoll.**
 */
final class NotifySend implements Op
{
    public function __construct(private readonly Delivery $delivery = new Delivery) {}

    public static function name(): string
    {
        return 'notify.send';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $event = $args['event'] ?? [];

        return $this->delivery->send(is_array($event) ? $event : []);
    }
}
