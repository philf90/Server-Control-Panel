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
 * **Die eine Ausnahme, und warum sie eng ist (P7).** PowerDNS bedient seine
 * HTTP-API im Klartext; die Fassung 4.8.3 kennt für ihren Webserver keine
 * Option für ein Zertifikat, keine für einen Schlüssel und keine für einen
 * Unix-Socket (gemessen, `docs/71 §4.1`). Damit stösst Zusage 1 auf eine
 * Vorgabe aus `docs/20 §9`, und der Betreiber hat die benannte Ausnahme
 * gewählt (`docs/70 §13`).
 *
 * Sie ist **kein Schalter an der Klasse, sondern ein Wert am Aufruf**:
 * `new Curl(loopbackPort: 8081)`. Ohne dieses Argument gibt es die Ausnahme
 * nicht — jeder bestehende Weg, die Zertifizierungsstelle und alle acht
 * DNS-Anbieter, baut `new Curl` ohne Argument und kann sie deshalb gar nicht
 * benutzen. Eine Ausnahme, die überall gilt, wäre keine.
 *
 * **Verglichen wird der zerlegte Wirt und nicht der Anfang der Zeichenkette.**
 * `str_starts_with($url, 'http://127.0.0.1')` liesse
 * `http://127.0.0.1.angreifer.invalid/` durch — der Name beginnt mit derselben
 * Zeichenkette und zeigt woandershin. Das ist die Fehlerklasse, gegen die
 * dieses Repo `AnchoredPatternTest` hat.
 *
 * **Und kein Name, auch nicht `localhost`.** Ein Name kommt aus einer
 * Auflösung, und eine Auflösung ist etwas, das jemand ändern kann — in
 * `/etc/hosts`, im Systemauflöser, über eine Suchdomäne. Die Ausnahme gilt für
 * eine Adresse und nicht für ein Versprechen darauf.
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
     * Die zwei Adressen, für die die Ausnahme gilt.
     *
     * **`[::1]` mit Klammern, und das ist keine Schreibweise, sondern das
     * Ergebnis einer Messung.** `parse_url('http://[::1]:8081/x')` gibt den
     * Wirt als `[::1]` zurück, nicht als `::1`. Wer gegen die klammerlose Form
     * vergleicht, baut eine Ausnahme, die für IPv6 nie greift — und die sieht
     * gebaut aus.
     *
     * Die klammerlose Form steht bewusst **nicht** hier: `http://::1:8081/x`
     * zerlegt sich zu Wirt `::1` und Port 8081, obwohl es keine gültige Adresse
     * dieser Form gibt. Was diese Klasse annimmt, baut der Agent selbst; die
     * missgebildete Form braucht er nicht.
     *
     * @var list<string>
     */
    public const LOOPBACK = ['127.0.0.1', '[::1]'];

    /**
     * @param  int|null  $loopbackPort  Der Port, für den die Ausnahme gilt —
     *                                  `null` heisst: keine Ausnahme.
     */
    public function __construct(private readonly ?int $loopbackPort = null) {}

    /**
     * Darf diese Adresse überhaupt gewählt werden?
     *
     * Getrennt von {@see send}, weil eine Zusage, die man prüfen will, eine
     * Frage sein muss und keine Anweisung mitten in einem Ablauf.
     */
    public function permitted(string $url): bool
    {
        if (str_starts_with($url, 'https://')) {
            return true;
        }

        if ($this->loopbackPort === null) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'http'
            && in_array($parts['host'] ?? '', self::LOOPBACK, true)
            && ($parts['port'] ?? null) === $this->loopbackPort;
    }

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
        if (! $this->permitted($url)) {
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
