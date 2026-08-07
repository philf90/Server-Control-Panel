<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\DomainName;

/**
 * Wo ein ausgestelltes Zertifikat liegt — und wie es dorthin kommt.
 *
 * **Der Pfad entsteht hier und kommt nicht von aussen.** Übergeben wird ein
 * Name, und der geht durch {@see CertificateName::normalize()} — also durch
 * dieselbe Prüfung wie jeder Domainname ({@see DomainName::normalize()}), nur
 * dass ein Platzhalter zusätzlich seinen Schlüssel bekommt. Ein Pfad aus der
 * Anwendung wäre bei einem Prozess, der als root schreibt, genau die Freiheit,
 * die dieses Projekt nirgends gewährt — dieselbe Regel wie in `Site` und
 * `SubscriptionProvision`.
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
     * steht. Ein Platzhalter heisst hier `_wildcard.example.de` und nicht
     * `*.example.de`; warum, steht in {@see CertificateName}.
     */
    public function directory(string $name): string
    {
        return $this->root.'/'.CertificateName::normalize($name, 'name');
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

    /**
     * Ein abgelegtes Zertifikat entfernen — Kette, Schlüssel, Verzeichnis.
     *
     * **Warum es das erst seit August 2026 gibt.** Bis dahin konnte dieses
     * System ein Zertifikat anlegen und erneuern, aber nirgends löschen — weder
     * im Panel noch hier. Ein zurückgebautes Abonnement liess seine
     * Zertifikatsverzeichnisse liegen, **samt privatem Schlüssel**, und
     * `subscription.remove` räumt sie nicht mit weg: Der Ablageort liegt
     * ausserhalb des Abo-Verzeichnisses. Aufgefallen ist das erst, als die
     * Migration aus docs/35 zwölf davon auf dem Zielserver fand.
     *
     * **Kein rekursives Löschen.** Entfernt werden genau die Dateien, die
     * {@see self::write()} anlegt, plus die Zwischendatei eines abgebrochenen
     * Schreibvorgangs. Danach `rmdir` — liegt dort noch etwas, bleibt das
     * Verzeichnis stehen und der Rest wird gemeldet. Ein `rm -rf` auf einen
     * Pfad, der aus einem Namen entsteht, wäre in einem Prozess mit
     * Systemrechten genau die Freiheit, die dieses Projekt nirgends gewährt.
     *
     * **Wiederholbar**: Ein Zertifikat, das es nicht mehr gibt, ist der
     * gewünschte Zustand.
     *
     * @return array{directory: string, removed: bool, left_behind: list<string>}
     */
    public function remove(string $name): array
    {
        $directory = $this->directory($name);

        // **Die Eindämmung wird geprüft und nicht angenommen.** Sie folgt schon
        // aus {@see CertificateName::normalize()} — ein Name mit `/` oder `..`
        // kommt dort nicht durch. Hier löscht aber root, und eine Zusicherung,
        // die nur woanders steht, ist die Sorte Beleg, die dieses Projekt
        // mehrfach eingeholt hat.
        if (dirname($directory) !== $this->root) {
            throw AgentException::execFailed('Ablageort liegt nicht im Zertifikatsverzeichnis: '.$directory);
        }

        // Ein Symlink wird nicht verfolgt: `is_dir` täte es, und dann zeigte
        // das Löschen woandershin als das Verzeichnis, das gemeint war.
        if (is_link($directory)) {
            throw AgentException::execFailed('Ablageort ist eine Verknüpfung: '.$directory);
        }

        if (! is_dir($directory)) {
            return ['directory' => $directory, 'removed' => false, 'left_behind' => []];
        }

        foreach (['fullchain.pem', 'privkey.pem', 'fullchain.pem.neu', 'privkey.pem.neu'] as $file) {
            $path = $directory.'/'.$file;

            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }

        $left = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));

        if ($left !== []) {
            return ['directory' => $directory, 'removed' => false, 'left_behind' => $left];
        }

        if (! @rmdir($directory)) {
            throw AgentException::execFailed('Ablageort liess sich nicht entfernen: '.$directory);
        }

        return ['directory' => $directory, 'removed' => true, 'left_behind' => []];
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
