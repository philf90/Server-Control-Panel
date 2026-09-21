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
     * @return array{host: string, provider: string, stored_at: int, signed: bool}|null
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

    /**
     * Adresse, Empfänger, Geheimnis und die Angaben des Empfängers hinterlegen.
     *
     * Das Geheimnis darf fehlen — und bei jedem Empfänger ausser dem eigenen
     * **muss** es das: Dort liest niemand unsere Kopfzeile, und der Agent weist
     * es ab.
     *
     * **`$config` ist die vierte Angabe und nicht ein Sonderfall für
     * Telegram.** Welche Felder ein Empfänger braucht, steht in
     * `Notify\Providers::FIELDS`; wer keine braucht, bekommt ein leeres Feld
     * übergeben, und der Agent weist alles andere ab.
     *
     * @param  array<string, string>  $config
     */
    public function store(string $url, ?string $secret, string $provider, array $config = []): void;

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
