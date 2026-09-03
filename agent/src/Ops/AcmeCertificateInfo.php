<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\Op;

/**
 * Was liegt für diese Domain gerade an Zertifikat?
 *
 * **Nicht verändernd, also ohne Vorgang** — dieselbe Aufteilung wie bei
 * {@see PanelTlsInfo}: Nachsehen ändert nichts, und eine Seite, die bei jedem
 * Aufruf eine Zeile ins Protokoll schreibt, öffnet man nicht gern.
 *
 * **Der private Schlüssel kommt hier nicht vor.** Herausgegeben wird, was auch
 * jeder Browser sieht.
 */
final class AcmeCertificateInfo implements Op
{
    public function __construct(private readonly Store $store = new Store) {}

    public static function name(): string
    {
        return 'acme.certificate.info';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $name = Guard::string($args['name'] ?? null, 'name');
        $path = $this->store->certificate($name);

        if (! is_file($path)) {
            return ['present' => false, 'reason' => 'Es liegt kein Zertifikat unter '.$path.'.'];
        }

        $pem = (string) file_get_contents($path);
        $parsed = openssl_x509_parse($pem);

        if (! is_array($parsed)) {
            return ['present' => false, 'reason' => 'Das Zertifikat unter '.$path.' lässt sich nicht lesen.'];
        }

        $issuer = $parsed['issuer'] ?? null;
        $subject = $parsed['subject'] ?? null;

        return [
            'present' => true,
            'path' => $path,
            'key' => $this->store->key($name),
            'issuer' => is_array($issuer) ? (string) ($issuer['CN'] ?? '') : '',

            /*
             * **Der Fingerabdruck der Datei — für die Frage an die Leitung.**
             * A10 vergleicht ihn mit dem, was der Webserver für denselben Namen
             * mit SNI ausliefert (`docs/98 §3 E`). Der Fingerabdruck und nicht
             * die Seriennummer: Die ist nur je Aussteller eindeutig, und dieses
             * Panel erzeugt selbstsignierte Zertifikate.
             *
             * Gemessen (`docs/81 §2.3o` M23): Über einer `fullchain.pem`
             * liefert `openssl_x509_fingerprint` den **Leaf** — also genau das,
             * was auch über die Leitung kommt.
             */
            'fingerprint' => self::fingerprint($pem),

            // Aussteller gleich Inhaber heisst selbstsigniert heisst kein HSTS
            // (docs/27 §7). Dieselbe Frage beantwortet `panel.tls.info` für die
            // Oberfläche; hier steht sie für eine Kundendomain.
            'self_signed' => $subject !== null && $subject === $issuer,
            'valid_from' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'valid_to' => (int) ($parsed['validTo_time_t'] ?? 0),
            'names' => Names::fromCertificate($parsed)['dns'],
        ];
    }

    /**
     * Der Fingerabdruck des ersten Zertifikats in einer PEM-Datei.
     *
     * Grossgeschrieben, weil die Gegenseite denselben Vergleich anstellt und
     * `openssl_x509_fingerprint` klein schreibt — zwei Schreibweisen ergäben
     * zwei Zeichenketten, die nie gleich sind.
     */
    private static function fingerprint(string $pem): ?string
    {
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        return $fingerprint === false || $fingerprint === '' ? null : strtoupper($fingerprint);
    }
}
