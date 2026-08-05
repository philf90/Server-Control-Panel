<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use JsonException;
use OpenSSLAsymmetricKey;
use SrvPanel\Agent\AgentException;

/**
 * Die Signatur, mit der jede Anfrage an die Zertifizierungsstelle geht.
 *
 * ACME kennt keine Anmeldung mit Kennwort: Jede verändernde Anfrage ist ein
 * JWS, signiert mit dem Kontoschlüssel. Wer den Schlüssel hat, ist das Konto —
 * er entsteht deshalb im Agenten und verlässt ihn nie.
 *
 * **`RS256` und nicht `ES256`, und das ist keine Bequemlichkeit.** Bei einem
 * EC-Schlüssel liefert `openssl_sign` die Signatur in DER-Kodierung; JWS
 * verlangt an dieser Stelle die beiden Zahlen roh hintereinander. Zwischen
 * beidem liegt ein kleiner ASN.1-Parser — dreissig Zeilen an der heikelsten
 * Stelle des ganzen Clients, wo ein Fehler nicht auffällt, sondern als
 * „unauthorized" von der Gegenseite zurückkommt. Mit RSA und `RS256` gibt
 * `openssl_sign` genau das Format aus, das hineingehört. Das *ausgestellte*
 * Zertifikat ist davon unberührt: Dessen Signatur macht die
 * Zertifizierungsstelle, und welchen Schlüsseltyp es trägt, entscheidet
 * {@see Csr}.
 */
final class Jws
{
    public function __construct(private readonly OpenSSLAsymmetricKey $key) {}

    /**
     * base64url ohne Auffüllzeichen — die Kodierung, die JWS überall benutzt.
     *
     * Die drei Unterschiede zu base64 stehen in einer Zeile, und alle drei
     * zählen: `+` wird `-`, `/` wird `_`, und die `=` am Ende fallen weg. Ein
     * einziges übriggebliebenes `=` macht aus einer gültigen Signatur eine, die
     * die Gegenseite verwirft.
     */
    public static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Der öffentliche Teil des Kontoschlüssels als JWK.
     *
     * **Die Reihenfolge der drei Felder ist Teil der Zusage.** RFC 7638 bildet
     * den Fingerabdruck über genau diese Felder, in lexikographischer Ordnung
     * und ohne ein einziges Leerzeichen. Wer sie umstellt, bekommt einen
     * anderen Fingerabdruck — und damit eine Schlüsselautorisierung, die jede
     * Prüfung ablehnt, ohne dass irgendwo stünde, warum.
     *
     * @return array{e: string, kty: string, n: string}
     */
    public function jwk(): array
    {
        $details = openssl_pkey_get_details($this->key);
        $rsa = is_array($details) ? ($details['rsa'] ?? null) : null;

        if (! is_array($rsa)) {
            throw AgentException::execFailed('Der Kontoschlüssel gibt seinen öffentlichen Teil nicht her.');
        }

        $modulus = $rsa['n'] ?? null;
        $exponent = $rsa['e'] ?? null;

        if (! is_string($modulus) || ! is_string($exponent)) {
            throw AgentException::execFailed('Der Kontoschlüssel ist kein RSA-Schlüssel.');
        }

        return [
            'e' => self::base64url($exponent),
            'kty' => 'RSA',
            'n' => self::base64url($modulus),
        ];
    }

    public function thumbprint(): string
    {
        return self::thumbprintOf($this->jwk());
    }

    /**
     * Der Fingerabdruck eines JWK nach RFC 7638.
     *
     * Er ist die zweite Hälfte jeder Schlüsselautorisierung: Was die
     * Prüfstelle unter der Adresse oder im DNS findet, ist `<token>.<dieser
     * Wert>`. Damit beweist der Server, dass er denselben Kontoschlüssel
     * besitzt, mit dem die Bestellung signiert wurde.
     *
     * Statisch und mit dem JWK als Argument, damit er gegen den **Testvektor
     * aus dem RFC** geprüft werden kann und nicht nur gegen sich selbst —
     * dieselbe Überlegung wie bei TOTP.
     *
     * @param  array{e: string, kty: string, n: string}  $jwk
     */
    public static function thumbprintOf(array $jwk): string
    {
        return self::base64url(hash('sha256', self::json($jwk), true));
    }

    /**
     * Ein fertiges JWS in der abgeflachten Form.
     *
     * **`null` heisst POST-as-GET.** ACME liest geschützte Ressourcen nicht mit
     * GET, sondern mit einem signierten POST, dessen Rumpf die *leere
     * Zeichenkette* ist. Das ist ein anderer Fall als der leere Rumpf weiter
     * unten, und die beiden zu verwechseln kostet eine Fehlermeldung, in der
     * nichts davon steht.
     *
     * @param  array<string, mixed>  $protected
     * @param  array<string, mixed>|null  $payload
     */
    public function sign(array $protected, ?array $payload): string
    {
        /*
         * **Ein leerer Rumpf ist das leere Objekt und nicht die leere Liste.**
         *
         * `json_encode([])` schreibt `[]`. ACME verlangt an genau einer Stelle
         * einen leeren Rumpf — beim Anstossen einer Prüfung —, und dort will es
         * `{}`. Die Antwort auf `[]` ist „malformed", und zwar erst auf dem
         * echten Server, weil es hier keinen gibt, der widerspricht.
         *
         * Die Sonderbehandlung steht hier und nicht in {@see self::json()}:
         * Dort träfe sie auch verschachtelte leere Listen, und aus
         * `"contact": []` würde `"contact": {}` — wieder „malformed", nur an
         * einer Stelle, an der niemand danach sucht.
         */
        $head = self::base64url(self::json($protected));
        $body = match (true) {
            $payload === null => '',
            $payload === [] => self::base64url('{}'),
            default => self::base64url(self::json($payload)),
        };

        $signature = '';

        if (! openssl_sign($head.'.'.$body, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw AgentException::execFailed('Die Anfrage an die Zertifizierungsstelle ließ sich nicht signieren.');
        }

        return self::json([
            'protected' => $head,
            'payload' => $body,
            'signature' => self::base64url($signature),
        ]);
    }

    /**
     * Als JSON, so wie ACME es liest.
     *
     * `JSON_UNESCAPED_SLASHES`, weil PHP `/` sonst als `\/` schreibt. Für den
     * Fingerabdruck wäre das ein anderer Text und damit ein anderer Hash; in
     * den Adressen der Bestellung wäre es unnötiger Ballast.
     *
     * @param  array<string, mixed>  $value
     */
    public static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw AgentException::execFailed('Die Anfrage ließ sich nicht als JSON schreiben: '.$error->getMessage());
        }
    }
}
