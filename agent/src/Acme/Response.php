<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;

/**
 * Was die Zertifizierungsstelle geantwortet hat.
 *
 * **Die Kopfzeilen zählen hier mehr als der Rumpf.** ACME führt drei Angaben
 * ausschliesslich im Kopf: den `Replay-Nonce` für die nächste Anfrage, die
 * `Location` mit der Kontonummer oder der Adresse einer Bestellung, und über
 * `Content-Type` die Auskunft, ob der Rumpf eine Antwort oder ein Fehler ist.
 * Ein Transport, der nur den Rumpf durchreicht, macht aus jeder dieser drei
 * Angaben eine, die man neu erfinden muss.
 *
 * Die Namen sind durchgängig kleingeschrieben abgelegt: HTTP/2 schickt sie so,
 * HTTP/1.1 in beliebiger Schreibweise, und ein `Replay-Nonce`, den man unter
 * `replay-nonce` sucht, ist einer, den man nicht findet.
 */
final class Response
{
    /**
     * @param  array<string, string>  $headers  Namen kleingeschrieben
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Der Rumpf als Ablage.
     *
     * **Die Schleife am Ende ist keine Zierde.** `json_decode` liefert für eine
     * JSON-Liste eine Ablage mit Zahlen als Schlüssel; die Zusage dieser
     * Methode ist aber eine mit Zeichenketten. Sie hier einzulösen kostet vier
     * Zeilen — sie im Vorbeigehen zu behaupten, hiesse, jeden Aufrufer mit
     * einem Typ arbeiten zu lassen, den er nicht bekommt.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        if (! is_array($decoded)) {
            throw AgentException::execFailed('Die Zertifizierungsstelle hat keine lesbare Antwort geschickt.');
        }

        $fields = [];

        foreach ($decoded as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }
}
