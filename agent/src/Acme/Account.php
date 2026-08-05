<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use JsonException;
use OpenSSLAsymmetricKey;
use SrvPanel\Agent\AgentException;

/**
 * Der Kontoschlüssel — er entsteht hier und geht nirgendwohin.
 *
 * Dieselbe Regel wie beim Datenbankpasswort und beim `APP_KEY` aus
 * `PanelProvision`: Was der Agent an Geheimnissen erzeugt, überquert den Socket
 * nicht. Für ACME gilt sie doppelt, denn der Kontoschlüssel *ist* das Konto —
 * wer ihn hat, kann für jede Domain dieses Servers ein Zertifikat bestellen und
 * bestehende widerrufen.
 *
 * **Je ACME-Verzeichnis ein eigenes Konto, in einem eigenen Unterverzeichnis.**
 * Testbetrieb und Produktion sind getrennte Welten: Eine Kontonummer aus dem
 * einen ist im anderen unbekannt. Läge beides nebeneinander, wäre der Wechsel
 * des Verzeichnisses eine Fehlersuche mit der Meldung „accountDoesNotExist" —
 * und die sagt nicht, dass zwei Dinge verwechselt wurden. Der Verzeichnisname
 * ist der Anfang des SHA-256 der Adresse: kurz, stabil, und ohne ein Zeichen,
 * das in einem Pfad etwas bedeutet.
 */
final class Account
{
    /** Oberhalb davon liegt nichts von ACME. */
    public const ROOT = '/etc/srvpanel/acme';

    public function __construct(
        private readonly string $directoryUrl,
        private readonly string $root = self::ROOT,
    ) {}

    /** Das Verzeichnis dieses Kontos — siehe die Klassenbeschreibung. */
    public function path(): string
    {
        return $this->root.'/'.substr(hash('sha256', $this->directoryUrl), 0, 16);
    }

    /**
     * Der Schlüssel, erzeugt beim ersten Aufruf.
     *
     * 2048 Bit RSA, wie in `PanelTls`. Grössere Schlüssel bringen an dieser
     * Stelle nichts: Der Kontoschlüssel signiert Anfragen, die Sekunden gelten,
     * und nicht ein Zertifikat, das ein Jahr steht.
     */
    public function key(): OpenSSLAsymmetricKey
    {
        $file = $this->path().'/account.key';

        if (is_file($file)) {
            $key = openssl_pkey_get_private((string) file_get_contents($file));

            if ($key === false) {
                throw AgentException::execFailed('Der ACME-Kontoschlüssel ist unlesbar: '.$file);
            }

            return $key;
        }

        return $this->create($file);
    }

    /** Die Kontonummer, sofern dieses Konto schon einmal registriert wurde. */
    public function kid(): ?string
    {
        $fields = $this->read();
        $kid = $fields['kid'] ?? null;

        return is_string($kid) ? $kid : null;
    }

    /**
     * Die Kontonummer festhalten.
     *
     * **Ohne sie beginnt jeder Lauf mit einer Registrierung.** Die wäre nicht
     * falsch — `newAccount` mit demselben Schlüssel liefert dieselbe Nummer
     * zurück —, aber sie kostet je Zertifikat eine Anfrage, und die zählt auf
     * dieselbe Ratenbegrenzung ein wie alles andere.
     *
     * Das Verzeichnis steht mit in der Datei, obwohl es schon im Pfad steckt:
     * Wer hier hineinsieht, um eine Fehlersuche abzukürzen, soll nicht erst
     * einen Hash zurückrechnen müssen.
     */
    public function remember(string $kid, string $contact): void
    {
        $this->write('account.json', [
            'kid' => $kid,
            'directory' => $this->directoryUrl,
            'contact' => $contact,
        ]);
    }

    private function create(string $file): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw AgentException::execFailed('Der ACME-Kontoschlüssel ließ sich nicht erzeugen.');
        }

        $pem = '';

        if (! openssl_pkey_export($key, $pem)) {
            throw AgentException::execFailed('Der ACME-Kontoschlüssel ließ sich nicht ablegen.');
        }

        $this->directory();

        // Über eine Zwischendatei und dann umbenennen: Ein abgebrochener
        // Schreibvorgang hinterlässt sonst eine halbe Schlüsseldatei, die beim
        // nächsten Lauf als vorhanden gilt und nicht mehr zu lesen ist.
        $temp = $file.'.neu';

        if (@file_put_contents($temp, $pem) === false) {
            throw AgentException::execFailed('Der ACME-Kontoschlüssel ließ sich nicht schreiben: '.$file);
        }

        chmod($temp, 0o600);
        rename($temp, $file);

        return $key;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $file = $this->path().'/account.json';

        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded)) {
            return [];
        }

        $fields = [];

        foreach ($decoded as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $fields */
    private function write(string $name, array $fields): void
    {
        $this->directory();

        try {
            $text = json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw AgentException::execFailed('Die Kontodaten ließen sich nicht schreiben: '.$error->getMessage());
        }

        $file = $this->path().'/'.$name;

        if (@file_put_contents($file, $text."\n") === false) {
            throw AgentException::execFailed('Die Kontodaten ließen sich nicht schreiben: '.$file);
        }

        chmod($file, 0o600);
    }

    private function directory(): void
    {
        $path = $this->path();

        if (! is_dir($path) && ! @mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw AgentException::execFailed('Das Verzeichnis für das ACME-Konto ließ sich nicht anlegen: '.$path);
        }
    }
}
