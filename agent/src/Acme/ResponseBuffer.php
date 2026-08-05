<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Was von einer Antwort ankommt, während sie ankommt.
 *
 * curl liefert Kopfzeilen und Rumpf stückweise über zwei Rückrufe. Die
 * naheliegende Fassung fängt beides in Variablen der umgebenden Methode auf und
 * bindet sie mit `&` in die Closures. Das funktioniert und liest sich schlecht:
 * Drei Zustände, zwei Rückrufe, und die Regel für den Deckel steht mitten in
 * einer Konfigurationsablage.
 *
 * **Hier steht sie einmal und ist damit prüfbar.** Der Deckel war vorher eine
 * Zusage ohne Wächter — genau das Muster, das dieses Projekt teuer bezahlt hat.
 * Jetzt ist er eine Methode mit einem Rückgabewert, den ein Test befragen kann,
 * ohne einen Webserver zu brauchen.
 */
final class ResponseBuffer
{
    /** @var array<string, string> */
    private array $headers = [];

    private string $body = '';

    private bool $truncated = false;

    public function __construct(private readonly int $limit) {}

    /**
     * Eine Kopfzeile aufnehmen, so wie curl sie übergibt.
     *
     * Der Name wird kleingeschrieben abgelegt: HTTP/2 schickt ihn so, HTTP/1.1
     * in beliebiger Schreibweise, und ein `Replay-Nonce`, den man unter
     * `replay-nonce` sucht, ist einer, den man nicht findet. Die Statuszeile
     * („HTTP/2 200") trägt keinen Doppelpunkt mit Namen davor und fällt damit
     * von selbst heraus.
     */
    public function header(string $line): void
    {
        $parts = explode(':', $line, 2);

        if (count($parts) === 2) {
            $this->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    /**
     * Ein Stück des Rumpfes aufnehmen.
     *
     * **Der Deckel greift beim Schreiben und nicht danach.** Erst alles zu
     * holen und dann die Länge zu messen, wäre kein Deckel — der Speicher wäre
     * bereits voll. Eine andere Zahl als die übergebene Länge bricht die
     * Übertragung ab; das ist der von curl vorgesehene Weg.
     *
     * @return int Wieviele Bytes angenommen wurden
     */
    public function write(string $chunk): int
    {
        if (strlen($this->body) + strlen($chunk) > $this->limit) {
            $this->truncated = true;

            return 0;
        }

        $this->body .= $chunk;

        return strlen($chunk);
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function response(int $status): Response
    {
        return new Response($status, $this->headers, $this->body);
    }
}
