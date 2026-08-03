<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Die Zugangsdaten des SMTP-Relays.
 *
 * **Warum ein Relay und kein eigener Mailversand.** Ein Panel, das selbst
 * zustellt, braucht einen MTA auf demselben Server, einen sauberen PTR, SPF,
 * DKIM und eine IP mit Ruf — und wenn eines davon fehlt, landet die Mail mit
 * dem Einmal-Link im Spam, und zwar ohne Rückmeldung. Über ein Relay geht sie
 * über einen Absender, der das alles schon hat.
 *
 * **Ein Wertobjekt und kein Array.** Die Felder werden an drei Stellen
 * gebraucht — Formular, Prüfung, Mailkonfiguration —, und ein Array mit
 * Zeichenketten als Schlüsseln wäre an allen dreien dieselbe Gelegenheit für
 * einen Tippfehler wie bei den Kontingenten.
 */
final class MailSettings
{
    /** Die Verschlüsselung der Verbindung. */
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public function __construct(
        public readonly string $host = '',
        public readonly int $port = 587,
        public readonly string $encryption = 'tls',
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly string $from_address = '',
        public readonly string $from_name = 'SrvPanel',
    ) {}

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $encryption = is_string($stored['encryption'] ?? null) ? $stored['encryption'] : 'tls';

        return new self(
            host: trim((string) ($stored['host'] ?? '')),
            port: (int) ($stored['port'] ?? 587),
            // Ein unbekannter Wert wird zu `tls` und nicht zu `none`: Wenn
            // hier je etwas schiefgeht, soll die Verbindung scheitern und
            // nicht im Klartext zustande kommen.
            encryption: in_array($encryption, self::ENCRYPTIONS, true) ? $encryption : 'tls',
            username: (string) ($stored['username'] ?? ''),
            password: (string) ($stored['password'] ?? ''),
            from_address: trim((string) ($stored['from_address'] ?? '')),
            from_name: (string) ($stored['from_name'] ?? 'SrvPanel'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'password' => $this->password,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
        ];
    }

    /**
     * Steht genug da, um eine Mail zu verschicken?
     *
     * Benutzername und Passwort gehören nicht dazu — ein Relay im eigenen Netz
     * kann ohne Anmeldung arbeiten. Ohne Server und ohne Absenderadresse geht
     * dagegen nichts: Die eine sagt, wohin die Verbindung geht, die andere
     * steht im Umschlag, und ein leeres `From` weist jeder Empfänger ab.
     */
    public function usable(): bool
    {
        return $this->host !== '' && $this->from_address !== '';
    }

    /**
     * Die Ablage für die Oberfläche — **ohne** das Passwort.
     *
     * Es steht in keiner Antwort des Servers. Ein Passwortfeld, das den
     * gespeicherten Wert zurückschickt, damit das Formular „vollständig"
     * aussieht, legt ihn im Quelltext jeder Seite ab, die es zeigt. Das
     * Formular zeigt statt dessen, *ob* eines hinterlegt ist.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(): array
    {
        $values = $this->toArray();
        unset($values['password']);

        $values['password_set'] = $this->password !== '';

        return $values;
    }
}
