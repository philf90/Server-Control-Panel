<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Support\Tls\DnsCredentials;
use SrvPanel\Agent\Notify\Target;

/**
 * Das Meldeziel dieses Servers, wie das Panel es kennen darf.
 *
 * **Warum eine Schnittstelle und nicht nur {@see AgentNotifyTarget}.** Dasselbe
 * wie bei {@see DnsCredentials}: Die drei Zustände, auf die es
 * ankommt — es ist eines hinterlegt, es ist keines hinterlegt, der Agent
 * antwortet gar nicht — entscheiden, was auf der Seite steht, und der dritte
 * lässt sich an einem laufenden Agenten nicht herstellen, ohne ihn anzuhalten.
 *
 * **Die Adresse steht hier nicht.** Was zurückkommt, ist der Rechnername; der
 * Grund steht in {@see Target}: Bei den meisten
 * Eingangshaken berechtigt die Adresse *allein* zur Zustellung.
 */
interface NotifyTarget
{
    /**
     * Was über das Ziel gesagt werden darf — oder `null`, wenn keines steht.
     *
     * @return array{host: string, stored_at: int, signed: bool}|null
     */
    public function describe(): ?array;

    /**
     * Antwortet der Agent überhaupt?
     *
     * **Der dritte Zustand, und er ist keine Spitzfindigkeit.** Ohne ihn zeigte
     * die Seite „kein Meldeziel", während in Wahrheit eines meldet — und der
     * Betreiber trüge ein zweites ein.
     *
     * > **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
     * > behauptet etwas, das sie nicht weiss.**
     */
    public function reachable(): bool;

    /** Adresse und Geheimnis hinterlegen. Das Geheimnis darf fehlen. */
    public function store(string $url, ?string $secret): void;

    /** Das Ziel wieder entfernen. */
    public function forget(): bool;

    /**
     * Eine Meldung zustellen.
     *
     * Wirft, wenn es nicht angekommen ist — der Aufrufer entscheidet, was das
     * bedeutet. {@see WebhookChannel} macht daraus ein {@see Delivery::Failed}
     * und keinen Abbruch des Nachtlaufs.
     *
     * @param  array<string, mixed>  $event
     */
    public function send(array $event): void;
}
