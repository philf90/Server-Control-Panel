<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use CurlHandle;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Runner;
use SrvPanel\Agent\Version;

/**
 * Der einzige Ort, an dem der Agent nach draussen spricht.
 *
 * Der Agent ist ein Prozess, der einen Unix-Socket bedient und Programme von
 * einer Positivliste startet. Mit ACME baut er als root eine Verbindung zu
 * einem fremden Rechner auf, und das ist eine eigene Art von Oberfläche. Sie
 * steht deshalb an genau einer Stelle, mit vier Zusagen, die nirgends sonst
 * eingelöst werden — dieselbe Aufteilung wie bei {@see Runner} für Programme:
 *
 * 1. **Nur https.** Eine Adresse ohne TLS wird abgelehnt, bevor curl sie
 *    sieht. Das Verzeichnis nennt die Folgeadressen selbst; ohne diese Schranke
 *    genügte eine davon auf `http://`, um jede signierte Anfrage im Klartext zu
 *    schicken.
 * 2. **Keine Umleitungen.** ACME leitet nicht um. Wer `FOLLOWLOCATION`
 *    einschaltet, schickt eine für Adresse A signierte Anfrage an Adresse B —
 *    und die Signatur schützt den Inhalt, nicht das Ziel. Für einen
 *    DNS-Anbieter gilt dasselbe mit anderem Grund: Eine Umleitung trüge das
 *    Token mit, und zwar an eine Adresse, die niemand hinterlegt hat.
 * 3. **Gedeckelte Antwort.** Eine Antwort, die nicht aufhört, kann den
 *    Speicher des Agenten nicht füllen. Der Deckel greift beim Schreiben und
 *    nicht danach: Erst alles zu holen und dann zu messen, wäre kein Deckel.
 * 4. **Zeitlimit auf Verbindung und Gesamtdauer.** Eine Gegenstelle, die
 *    annimmt und dann schweigt, hält sonst einen Vorgang bis zu seinem eigenen
 *    Zeitlimit fest.
 *
 * **Warum das seit Schritt 9 eine eigene Klasse ist.** Die vier Zusagen standen
 * in {@see CurlTransport}, und dort waren sie richtig, solange die
 * Zertifizierungsstelle die einzige Gegenstelle war. Mit den DNS-Anbietern kam
 * eine zweite dazu — und eine zweite Stelle, die dieselben vier Optionen
 * setzt, ist genau das Muster, an dem dieses Projekt am häufigsten verloren
 * hat: Die zweite ist die, in der eine davon irgendwann fehlt. Oben liegen
 * jetzt zwei Formen — die ACME-förmige und die der Anbieter —, darunter eine
 * Grenze.
 *
 * **curl und nicht die Stream-Wrapper von PHP:** `php8.4-curl` ist eine
 * Abhängigkeit des Pakets, die Prüfung des Zertifikats ist die Vorgabe, und
 * `ca-certificates` steht ebenfalls im Paket. Mit `allow_url_fopen` hinge
 * dasselbe an einer ini-Einstellung, die jemand aus guten Gründen abschaltet.
 */
final class Curl implements Outbound
{
    /** Mehr schickt keine Zertifizierungsstelle — eine Kette liegt bei wenigen KiB. */
    public const RESPONSE_MAX = 512 * 1024;

    public const CONNECT_TIMEOUT = 10;

    public const TIMEOUT = 30;

    /**
     * Eine Anfrage nach draussen — mit den vier Zusagen von oben.
     *
     * @param  string  $method  `GET`, `POST`, `DELETE` — was der Gegenstelle
     *                          entspricht; ACME kennt nur die ersten beiden.
     * @param  list<string>  $headers  Fertige Kopfzeilen, `Name: Wert`
     * @param  string|null  $body  `null` heisst: kein Rumpf
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): Response
    {
        if (! str_starts_with($url, 'https://')) {
            throw AgentException::denied('Nach draussen spricht der Agent nur über https.');
        }

        if (! function_exists('curl_init')) {
            throw AgentException::execFailed('Die PHP-Erweiterung curl fehlt — ohne sie geht nichts nach draussen.');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw AgentException::execFailed('Die Verbindung ließ sich nicht vorbereiten.');
        }

        $buffer = new ResponseBuffer(self::RESPONSE_MAX);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'srvpanel/'.Version::AGENT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use ($buffer): int {
                $buffer->header($line);

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static fn (CurlHandle $handle, string $chunk): int => $buffer->write($chunk),
        ];

        // **`CUSTOMREQUEST` und nicht `POST`.** Beides zusammen gesetzt ist
        // eine Falle: `CURLOPT_POST` schreibt die Methode ein zweites Mal, und
        // welche gewinnt, hängt an der Reihenfolge im Array. Der Rumpf hängt
        // deshalb allein an `POSTFIELDS`, die Methode allein an dieser Zeile.
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $error = curl_error($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        // Zuerst der Deckel: Ein abgeschnittener Rumpf lässt curl mit
        // „write error" abbrechen, und diese Meldung erklärt nichts.
        if ($buffer->truncated()) {
            throw AgentException::execFailed('Die Antwort der Gegenstelle ist unerwartet gross.');
        }

        if ($ok === false) {
            throw AgentException::execFailed('Die Gegenstelle ist nicht erreichbar: '.$error);
        }

        return $buffer->response(is_int($status) ? $status : 0);
    }
}
