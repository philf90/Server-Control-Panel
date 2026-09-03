<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

/**
 * {@see Wire} über eine echte TLS-Verbindung zum Webserver dieser Maschine.
 *
 * **Mit SNI, und das ist der Punkt.** Ohne `peer_name` bekommt man den
 * Vorgabeblock und damit ein gültig aussehendes Zertifikat mit dem falschen
 * Namen — `docs/78` hat genau so einmal ein falsches Ergebnis gemessen, und
 * die Messrunde zu A10 hat es nachgestellt (`docs/81 §2.3o` M18).
 *
 * **Nicht geprüft wird die Kette.** `verify_peer` steht auf `false`, weil hier
 * nicht gefragt wird, ob das Zertifikat vertrauenswürdig ist — das hat die
 * Frage an die Datei schon beantwortet —, sondern **welches** es ist. Ein
 * abgelaufenes oder selbstsigniertes Zertifikat liesse sich sonst gar nicht
 * erst ansehen, und genau das wäre die Auskunft, um die es geht.
 *
 * **Gegen `127.0.0.1`.** Die Vorlage bindet `443` auf allen Adressen
 * (`SiteTemplate`), und die Frage lautet „liefert *dieser* Server es aus" —
 * nicht, wohin der Name im DNS zeigt; das ist P7.
 *
 * **Mit Frist.** Der Verbindungsaufbau samt Handshake steht unter dem Zeitlimit
 * von `stream_socket_client`; wie lange ein stiller Port hier wirklich hält,
 * ist ungemessen (`docs/98 §11`, M19) und gehört auf den Zielserver. Gemessen
 * sind die beiden lauten Fälle: ein Port ohne Zuhörer antwortet in 0 ms mit
 * `Connection refused`, einer ohne TLS in 1 ms — **mit leerer Meldung** (M23).
 *
 * > **Eine Fehlermeldung, die leer sein kann, ist keine Auskunft.** Deshalb
 * > gibt diese Klasse `null` zurück und keinen Text; was der Befund sagt, sagt
 * > sein Grund.
 */
final class TlsWire implements Wire
{
    public const HOST = '127.0.0.1';

    public const PORT = 443;

    public const TIMEOUT_SECONDS = 5;

    /**
     * Womit der Fingerabdruck gebildet wird — an **beiden** Enden derselbe.
     *
     * Der Agent bildet ihn über der Datei, diese Klasse über dem, was die
     * Leitung schickt. Zwei verschiedene Verfahren ergäben zwei Zeichenketten,
     * die nie gleich sind — und damit jede Nacht für jede Domain einen Befund.
     */
    public const ALGORITHM = 'sha256';

    public function fingerprint(string $name): ?string
    {
        $context = stream_context_create(['ssl' => [
            'peer_name' => $name,
            'SNI_enabled' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert' => true,
        ]]);

        $socket = @stream_socket_client(
            sprintf('ssl://%s:%d', self::HOST, self::PORT),
            $code,
            $error,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($socket)) {
            return null;
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certificate === null) {
            return null;
        }

        $fingerprint = openssl_x509_fingerprint($certificate, self::ALGORITHM);

        return $fingerprint === false || $fingerprint === '' ? null : strtoupper($fingerprint);
    }
}
