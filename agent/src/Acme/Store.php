<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\DomainName;

/**
 * Wo ein ausgestelltes Zertifikat liegt — und wie es dorthin kommt.
 *
 * **Der Pfad entsteht hier und kommt nicht von aussen.** Übergeben wird ein
 * Domainname, und der geht durch dieselbe Prüfung wie überall
 * ({@see DomainName::normalize()}). Ein Pfad aus der Anwendung wäre bei einem
 * Prozess, der als root schreibt, genau die Freiheit, die dieses Projekt
 * nirgends gewährt — dieselbe Regel wie in `Site` und `SubscriptionProvision`.
 *
 * **Kette und Schlüssel liegen getrennt**, weil nginx sie getrennt liest:
 * `ssl_certificate` will die Kette, `ssl_certificate_key` den Schlüssel. Eine
 * gemeinsame Datei wäre für nginx unbrauchbar und für die Rechte ein Problem:
 * Die Kette darf jeder lesen, der Schlüssel nur root.
 *
 * **Geschrieben wird über eine Zwischendatei.** Ein Reload, der genau zwischen
 * die beiden Schreibvorgänge fällt, sähe sonst eine halbe Datei — und `nginx -t`
 * lehnt sie ab, mit einer Meldung über ein Zertifikat, das eine Sekunde später
 * in Ordnung ist.
 */
final class Store
{
    /** Unterhalb davon liegt je Zertifikat ein Verzeichnis. */
    public const ROOT = '/etc/srvpanel/tls/certs';

    public function __construct(private readonly string $root = self::ROOT) {}

    /**
     * Das Verzeichnis eines Zertifikats.
     *
     * Benannt nach dem ersten Namen — das ist der, der auch im CommonName
     * steht. Ein Platzhalter kommt hier nicht an: Wildcards brauchen DNS-01,
     * und das ist der zweite Wurf.
     */
    public function directory(string $name): string
    {
        return $this->root.'/'.DomainName::normalize($name, 'name');
    }

    public function certificate(string $name): string
    {
        return $this->directory($name).'/fullchain.pem';
    }

    public function key(string $name): string
    {
        return $this->directory($name).'/privkey.pem';
    }

    /**
     * Liegt für diesen Namen ein Zertifikat — und wo?
     *
     * **Beide Dateien oder keine.** Ein `ssl_certificate` ohne
     * `ssl_certificate_key` lässt nginx nicht starten; die halbe Antwort wäre
     * hier also schlimmer als gar keine. Der Fall tritt auf, wenn ein Lauf
     * zwischen den beiden Schreibvorgängen abbricht.
     *
     * @return array{certificate: string, key: string}|null
     */
    public function existing(string $name): ?array
    {
        $certificate = $this->certificate($name);
        $key = $this->key($name);

        if (! is_file($certificate) || ! is_file($key)) {
            return null;
        }

        return ['certificate' => $certificate, 'key' => $key];
    }

    /**
     * Kette und Schlüssel ablegen.
     *
     * @return array{certificate: string, key: string, storage_name: string}
     */
    public function write(string $name, string $chain, string $privateKey): array
    {
        $directory = $this->directory($name);

        if (! is_dir($directory) && ! @mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw AgentException::execFailed('Verzeichnis für das Zertifikat ließ sich nicht anlegen: '.$directory);
        }

        $certificate = $this->certificate($name);
        $key = $this->key($name);

        // Die Kette darf jeder lesen — sie steht ohnehin in jeder Verbindung.
        $this->put($certificate, $chain, 0o644);

        // Der Schlüssel gehört root allein. nginx liest ihn als Masterprozess,
        // und der läuft als root; die Worker brauchen ihn nicht.
        $this->put($key, $privateKey, 0o600);

        // **`storage_name` geht mit zurück, und das ist keine Zugabe.** Ab dem
        // zweiten Wurf von P4 sagt das Panel, welches Zertifikat ein
        // Server-Block ausliefert — dazu muss es den Schlüssel kennen, unter
        // dem es hier liegt. Ihn in der Anwendung ein zweites Mal auszurechnen
        // hiesse, dieselbe Regel an zwei Stellen zu führen; und die Regel
        // ändert sich gleich wieder, wenn ein Platzhalter dazukommt, dessen
        // Name kein Verzeichnisname sein kann.
        return [
            'certificate' => $certificate,
            'key' => $key,
            'storage_name' => basename($directory),
        ];
    }

    private function put(string $path, string $contents, int $mode): void
    {
        $temp = $path.'.neu';

        if (@file_put_contents($temp, $contents) === false) {
            throw AgentException::execFailed('Datei ließ sich nicht schreiben: '.$path);
        }

        chmod($temp, $mode);
        rename($temp, $path);
    }
}
