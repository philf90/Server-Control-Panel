<?php

declare(strict_types=1);

namespace App\Support\Notify;

/**
 * Die Kanäle, die es gibt — an **einer** Stelle.
 *
 * **Der Grund ist der Wächter und nicht die Bequemlichkeit.**
 * `ChannelReachTest` hält beide Richtungen: Jeder Kanal, den die
 * Einstellungsseite anbietet, hat eine Umsetzung, und jede Umsetzung steht auf
 * der Seite. Ohne eine Liste, die man lesen kann, wäre die zweite Richtung
 * nicht messbar — und die zweite ist die, an der ein toter Eintrag wirklich
 * entsteht: Man baut einen Kanal, trägt ihn in den Nachtlauf ein, und die Seite
 * bietet ihn nie an.
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt —
 * > und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 *
 * **Die Reihenfolge ist die Reihenfolge der Zustellung**, und sie ist nicht
 * gleichgültig: Der Kunde bekommt seine Mail, bevor der Betreiber seine Meldung
 * bekommt. Fiele der Webhook aus, hätte der Kunde seine trotzdem.
 */
final class Channels
{
    /** @var list<Channel> */
    private readonly array $channels;

    public function __construct(MailChannel $mail, WebhookChannel $webhook)
    {
        $this->channels = [$mail, $webhook];
    }

    /** @return list<Channel> */
    public function all(): array
    {
        return $this->channels;
    }

    /** Ein Kanal unter seinem Schlüssel — oder `null`. */
    public function byKey(string $key): ?Channel
    {
        foreach ($this->channels as $channel) {
            if ($channel->key() === $key) {
                return $channel;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (Channel $c): string => $c->key(), $this->channels);
    }
}
