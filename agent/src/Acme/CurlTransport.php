<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use CurlHandle;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Version;

/**
 * Der einzige Ort, an dem der Agent nach draussen spricht.
 *
 * Bis hierher war der Agent ein Prozess, der einen Unix-Socket bedient und
 * Programme von einer Positivliste startet. Mit ACME baut er als root eine
 * Verbindung zu einem fremden Rechner auf, und das ist eine neue Art von
 * Oberfläche. Sie steht deshalb an genau einer Stelle, mit vier Zusagen, die
 * nirgends sonst eingelöst werden — dieselbe Aufteilung wie bei
 * {@see \SrvPanel\Agent\Runner} für Programme:
 *
 * 1. **Nur https.** Eine Adresse ohne TLS wird abgelehnt, bevor curl sie
 *    sieht. Das Verzeichnis nennt die Folgeadressen selbst; ohne diese Schranke
 *    genügte eine davon auf `http://`, um jede signierte Anfrage im Klartext zu
 *    schicken.
 * 2. **Keine Umleitungen.** ACME leitet nicht um. Wer `FOLLOWLOCATION`
 *    einschaltet, schickt eine für Adresse A signierte Anfrage an Adresse B —
 *    und die Signatur schützt den Inhalt, nicht das Ziel.
 * 3. **Gedeckelte Antwort.** Eine Antwort, die nicht aufhört, kann den
 *    Speicher des Agenten nicht füllen. Der Deckel greift beim Schreiben und
 *    nicht danach: Erst alles zu holen und dann zu messen, wäre kein Deckel.
 * 4. **Zeitlimit auf Verbindung und Gesamtdauer.** Eine Gegenstelle, die
 *    annimmt und dann schweigt, hält sonst einen Vorgang bis zu seinem eigenen
 *    Zeitlimit fest.
 *
 * **curl und nicht die Stream-Wrapper von PHP:** `php8.4-curl` ist eine
 * Abhängigkeit des Pakets, die Prüfung des Zertifikats ist die Vorgabe, und
 * `ca-certificates` steht ebenfalls im Paket. Mit `allow_url_fopen` hinge
 * dasselbe an einer ini-Einstellung, die jemand aus guten Gründen abschaltet.
 */
final class CurlTransport implements Transport
{
    /** Mehr schickt keine Zertifizierungsstelle — eine Kette liegt bei wenigen KiB. */
    public const RESPONSE_MAX = 512 * 1024;

    public const CONNECT_TIMEOUT = 10;

    public const TIMEOUT = 30;

    public function get(string $url): Response
    {
        return $this->send($url, null);
    }

    public function post(string $url, string $body): Response
    {
        return $this->send($url, $body);
    }

    private function send(string $url, ?string $body): Response
    {
        if (! str_starts_with($url, 'https://')) {
            throw AgentException::denied('Die Zertifizierungsstelle wird nur über https angesprochen.');
        }

        if (! function_exists('curl_init')) {
            throw AgentException::execFailed('Die PHP-Erweiterung curl fehlt — ohne sie kein ACME.');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw AgentException::execFailed('Die Verbindung ließ sich nicht vorbereiten.');
        }

        $headers = [];
        $received = '';
        $truncated = false;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'srvpanel/'.Version::AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$received, &$truncated): int {
                if (strlen($received) + strlen($chunk) > self::RESPONSE_MAX) {
                    $truncated = true;

                    // Eine andere Länge als die geschriebene bricht die
                    // Übertragung ab — der vorgesehene Weg, einen Deckel
                    // durchzusetzen, statt den Rest noch zu holen.
                    return 0;
                }

                $received .= $chunk;

                return strlen($chunk);
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/jose+json'];
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $error = curl_error($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($truncated) {
            throw AgentException::execFailed('Die Antwort der Zertifizierungsstelle ist unerwartet gross.');
        }

        if ($ok === false) {
            throw AgentException::execFailed('Die Zertifizierungsstelle ist nicht erreichbar: '.$error);
        }

        return new Response(is_int($status) ? $status : 0, $headers, $received);
    }
}
