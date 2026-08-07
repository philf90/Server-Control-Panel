<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use OpenSSLAsymmetricKey;
use SrvPanel\Agent\AgentException;

/**
 * Ein hochgeladenes Zertifikat — geprüft, bevor irgendetwas abgelegt wird.
 *
 * **Die Prüfung gehört in den Agenten und nicht in die Anwendung.** Hier sitzt
 * openssl, hier läuft nginx, und hier bleibt der private Schlüssel: Er soll
 * nicht durch das Panel wandern, um von dort wieder hinauszugehen. Die
 * Anwendung erfährt danach, was auch jeder Browser sieht.
 *
 * **Warum jede einzelne dieser Prüfungen dasteht.** Ein Zertifikat, das nginx
 * nicht laden kann, nimmt beim nächsten Reload *alle* Websites dieses Servers
 * mit — nicht nur die, für die es gedacht war. Und die Fälle, die durchgehen
 * und trotzdem falsch sind, sind die teureren:
 *
 * - **Kette und Schlüssel gehören nicht zusammen.** nginx startet nicht, und
 *   die Meldung im Journal nennt eine Datei, nicht den Grund.
 * - **Die Kette ist falsch sortiert.** Das verzeihen Browser
 *   unterschiedlich — Firefox holt das fehlende Glied nach, ein Mobilgerät
 *   nicht. Der Betreiber sieht eine Seite, die bei ihm aufgeht, und der Kunde
 *   eine Warnung.
 * - **Ein Schlüssel mit Passwort.** nginx fragt beim Start danach, und wer
 *   neu startet, findet den Dienst hängend statt gestartet.
 * - **Abgelaufen oder noch nicht gültig.** Beides sieht in einer Dateiliste
 *   gleich aus.
 *
 * **Keine Prüfung gegen die Wurzelspeicher des Systems.** Ob die
 * ausstellende Stelle bekannt ist, entscheidet der Browser des Besuchers und
 * nicht dieser Server; ein internes Zertifikat einer Firmen-CA abzuweisen wäre
 * eine Anmassung. Geprüft wird, dass die Kette *in sich* stimmt.
 */
final class Bundle
{
    /**
     * Wieviel eine Kette höchstens umfassen darf.
     *
     * Eine übliche Kette hat zwei bis vier Zertifikate zu je rund zwei
     * Kilobyte. Die Grenze steht hier, weil das Gegenüber als root schreibt und
     * eine Angabe ohne Grenze eine Einladung ist — nicht, weil eine echte Kette
     * je in ihre Nähe käme.
     */
    public const MAX_CHAIN_BYTES = 64 * 1024;

    public const MAX_KEY_BYTES = 32 * 1024;

    /** Mehr Glieder hat keine Kette, die ein Browser noch mitgeht. */
    public const MAX_CERTIFICATES = 10;

    /**
     * @param  non-empty-list<string>  $names  Die Namen aus dem subjectAltName
     */
    private function __construct(
        public readonly string $chain,
        public readonly string $privateKey,
        public readonly array $names,
        public readonly ?string $issuer,
        public readonly ?string $serial,
        public readonly int $notBefore,
        public readonly int $notAfter,
    ) {}

    /**
     * Prüfen und zusammensetzen — oder mit Grund abweisen.
     *
     * **Die Reihenfolge der Prüfungen ist die Reihenfolge der Meldungen**, und
     * das ist der ganze Unterschied für den, der die Datei gerade hochgeladen
     * hat: „Ungültig" ist keine Auskunft. Geprüft wird deshalb vom Groben zum
     * Feinen, und jede Stufe nennt, was sie gefunden hat.
     */
    public static function from(mixed $chain, mixed $privateKey, ?int $now = null): self
    {
        $pem = self::text($chain, 'certificate', self::MAX_CHAIN_BYTES);
        $key = self::text($privateKey, 'private_key', self::MAX_KEY_BYTES);

        $certificates = self::split($pem);
        $leaf = self::parse($certificates[0]);

        /*
         * **Erst die Reihenfolge, dann der Schlüssel — und das ist keine
         * Geschmacksfrage.**
         *
         * Beides stand hier andersherum, und im Abnahmelauf am 7. August 2026
         * fiel auf, was das bedeutet: Eine verkehrt sortierte Kette wurde mit
         * „Der Schlüssel gehört nicht zu diesem Zertifikat" abgewiesen. Der
         * Satz ist buchstäblich wahr — steht das ausstellende Zertifikat vorn,
         * ist es „dieses Zertifikat", und der Schlüssel des Blattes passt nicht
         * dazu. Er ist trotzdem die falsche Auskunft: Sie schickt den Betreiber
         * zum Schlüssel, den er neu holt, neu ausleitet und neu einfügt, während
         * die Ursache zwei Zeilen weiter oben in derselben Datei steht.
         *
         * `ordered()` weiss es genau und sagt es auch — welches Glied welches
         * nicht unterschrieben hat. Diese Prüfung gehört deshalb zuerst.
         *
         * **Die umgekehrte Reihenfolge kostet nichts.** Eine richtig sortierte
         * Kette mit falschem Schlüssel läuft durch `ordered()` hindurch und
         * bekommt weiterhin die Meldung zum Schlüssel; ein einzelnes Zertifikat
         * ohne Kette lässt `ordered()` unberührt.
         */
        self::ordered($certificates);
        self::keyBelongs($certificates[0], $key);

        $notBefore = (int) ($leaf['validFrom_time_t'] ?? 0);
        $notAfter = (int) ($leaf['validTo_time_t'] ?? 0);

        self::valid($notBefore, $notAfter, $now ?? time());

        $issuer = $leaf['issuer'] ?? null;

        return new self(
            chain: implode("\n", $certificates)."\n",
            privateKey: rtrim($key)."\n",
            names: self::names($leaf),
            issuer: is_array($issuer) ? (string) ($issuer['CN'] ?? '') : null,
            serial: isset($leaf['serialNumberHex']) ? (string) $leaf['serialNumberHex'] : null,
            notBefore: $notBefore,
            notAfter: $notAfter,
        );
    }

    /** Eine Angabe, die dasteht und nicht zu gross ist. */
    private static function text(mixed $value, string $field, int $limit): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw AgentException::badRequest('Es fehlt der Inhalt.', [$field => 'leer']);
        }

        if (strlen($value) > $limit) {
            throw AgentException::badRequest(
                sprintf('Die Angabe ist grösser als %d Byte.', $limit),
                [$field => strlen($value)],
            );
        }

        return $value;
    }

    /**
     * Die einzelnen Zertifikate einer PEM-Kette.
     *
     * **Zerlegt und nicht durchgereicht.** Ohne das Zerlegen prüfte jede
     * folgende Zeile nur das erste Zertifikat — `openssl_x509_parse` liest von
     * einer Kette das erste und schweigt über den Rest. Genau so entsteht die
     * falsch sortierte Kette, die niemand bemerkt.
     *
     * @return non-empty-list<string>
     */
    private static function split(string $pem): array
    {
        $gefunden = preg_match_all(
            '/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s',
            $pem,
            $matches,
        );

        $certificates = $gefunden === false ? [] : $matches[0];

        if ($certificates === []) {
            throw AgentException::badRequest(
                'Darin steht kein Zertifikat im PEM-Format.',
                ['certificate' => 'kein BEGIN CERTIFICATE'],
            );
        }

        if (count($certificates) > self::MAX_CERTIFICATES) {
            throw AgentException::badRequest(
                sprintf('Mehr als %d Zertifikate in einer Kette.', self::MAX_CERTIFICATES),
                ['certificate' => count($certificates)],
            );
        }

        return $certificates;
    }

    /**
     * Ein Zertifikat, das sich lesen lässt.
     *
     * @return array<string, mixed>
     */
    private static function parse(string $certificate): array
    {
        $parsed = openssl_x509_parse($certificate);

        if (! is_array($parsed)) {
            throw AgentException::badRequest(
                'Das Zertifikat lässt sich nicht lesen.',
                ['certificate' => 'nicht lesbar'],
            );
        }

        return $parsed;
    }

    /**
     * Gehören Schlüssel und Zertifikat zusammen?
     *
     * **Der Schlüssel darf kein Passwort tragen.** `openssl_pkey_get_private`
     * gibt dann `false` zurück, und die Meldung dazu muss den Grund nennen: Ein
     * verschlüsselter Schlüssel ist keine kaputte Datei, sondern einer, den
     * nginx beim Start nicht öffnen kann — der Dienst hinge an einer Abfrage,
     * die niemand sieht.
     */
    private static function keyBelongs(string $certificate, string $key): void
    {
        $private = openssl_pkey_get_private($key);

        if (! $private instanceof OpenSSLAsymmetricKey) {
            throw AgentException::badRequest(
                'Der private Schlüssel lässt sich nicht lesen — trägt er ein Passwort?',
                ['private_key' => 'nicht lesbar'],
            );
        }

        if (! openssl_x509_check_private_key($certificate, $private)) {
            throw AgentException::badRequest(
                'Der Schlüssel gehört nicht zu diesem Zertifikat.',
                ['private_key' => 'passt nicht'],
            );
        }
    }

    /**
     * Steht die Kette in der richtigen Reihenfolge?
     *
     * Das Blatt zuerst, danach jedes Zertifikat, das das vorige unterschrieben
     * hat. Geprüft wird genau das — mit dem öffentlichen Schlüssel des
     * nächsten Gliedes gegen die Unterschrift des vorigen.
     *
     * @param  non-empty-list<string>  $certificates
     */
    private static function ordered(array $certificates): void
    {
        for ($i = 0; $i < count($certificates) - 1; $i++) {
            $issuer = openssl_pkey_get_public($certificates[$i + 1]);

            if (! $issuer instanceof OpenSSLAsymmetricKey) {
                throw AgentException::badRequest(
                    sprintf('Das %d. Zertifikat der Kette lässt sich nicht lesen.', $i + 2),
                    ['certificate' => 'Glied '.($i + 2)],
                );
            }

            if (openssl_x509_verify($certificates[$i], $issuer) !== 1) {
                throw AgentException::badRequest(
                    sprintf(
                        'Die Kette ist nicht in der richtigen Reihenfolge: Das %d. Zertifikat hat das %d. '.
                        'nicht unterschrieben. Zuerst das eigene, dann die ausstellenden.',
                        $i + 2,
                        $i + 1,
                    ),
                    ['certificate' => 'Reihenfolge'],
                );
            }
        }
    }

    /** Gilt es gerade? */
    private static function valid(int $notBefore, int $notAfter, int $now): void
    {
        if ($notAfter > 0 && $notAfter <= $now) {
            throw AgentException::badRequest(
                'Das Zertifikat ist abgelaufen.',
                ['certificate' => 'gültig bis '.gmdate('Y-m-d', $notAfter)],
            );
        }

        if ($notBefore > 0 && $notBefore > $now) {
            throw AgentException::badRequest(
                'Das Zertifikat gilt erst später.',
                ['certificate' => 'gültig ab '.gmdate('Y-m-d', $notBefore)],
            );
        }
    }

    /**
     * Die Namen, für die es gilt.
     *
     * **Aus dem subjectAltName und nicht aus dem CommonName.** Der CN ist seit
     * 2017 für die Namensprüfung ohne Bedeutung; ein Browser sieht ihn gar
     * nicht mehr an. Ein Zertifikat ohne SAN deckt deshalb nichts — und das
     * abzuweisen ist ehrlicher, als einen Namen anzunehmen, den niemand prüft.
     *
     * @param  array<string, mixed>  $parsed
     * @return non-empty-list<string>
     */
    private static function names(array $parsed): array
    {
        $extensions = $parsed['extensions'] ?? null;
        $san = is_array($extensions) ? ($extensions['subjectAltName'] ?? null) : null;
        $names = [];

        if (is_string($san)) {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);

                if (! str_starts_with($entry, 'DNS:')) {
                    continue;
                }

                $name = strtolower(trim(substr($entry, 4)));

                if ($name !== '' && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        if ($names === []) {
            throw AgentException::badRequest(
                'Das Zertifikat nennt keinen Namen im subjectAltName — es deckt damit keine Domain ab.',
                ['certificate' => 'ohne subjectAltName'],
            );
        }

        return $names;
    }
}
