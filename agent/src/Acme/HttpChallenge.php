<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;

/**
 * HTTP-01: eine Datei unter `/.well-known/acme-challenge/`.
 *
 * **Ein gemeinsames Verzeichnis für alle Domains, nicht eines je Abonnement.**
 * Das ist die Entscheidung, an der drei Dinge auf einmal hängen:
 *
 * - Kein Kunde braucht irgendwo Schreibrechte für etwas, das der Agent legt.
 * - Eine Domain **ohne DocumentRoot** bekommt trotzdem ein Zertifikat. Eine
 *   Weiterleitung beantwortet jede Anfrage selbst und sucht nie eine Datei; ein
 *   gesperrtes Abonnement antwortet mit 503. Läge die Prüfdatei im
 *   DocumentRoot, wären beide von TLS ausgeschlossen — und zwar dauerhaft.
 * - Es gibt eine Stelle, auf die der Server-Block zeigt, statt einer je
 *   Abonnement, die beim Umbenennen eines Verzeichnisses ins Leere zeigt.
 *
 * Der Server-Block zeigt mit `root` hierher, nicht mit `alias` — deshalb liegt
 * die Datei unterhalb des vollen Pfades aus der Adresse und nicht direkt im
 * Verzeichnis. Das ist die Zeile, die still danebengeht, wenn man sie falsch
 * herum baut: nginx sucht dann in einem Verzeichnis, in das nie jemand schreibt.
 */
final class HttpChallenge implements Challenge
{
    public const TYPE = 'http-01';

    /** Hier liegen die Prüfdateien aller Domains dieses Servers. */
    public const DIRECTORY = '/var/lib/srvpanel/acme-challenge';

    /** Der Pfad aus der Adresse — er ist Teil des Ablageorts, siehe oben. */
    public const PREFIX = '/.well-known/acme-challenge';

    public function __construct(private readonly string $directory = self::DIRECTORY) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function present(string $domain, string $token, string $keyAuthorization): void
    {
        $file = $this->file($token);
        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw AgentException::execFailed('Das Verzeichnis für die Prüfdatei ließ sich nicht anlegen: '.$directory);
        }

        if (@file_put_contents($file, $keyAuthorization) === false) {
            throw AgentException::execFailed('Die Prüfdatei ließ sich nicht schreiben: '.$file);
        }

        // Lesbar für alle: nginx liefert sie als unprivilegierter Worker aus.
        // Ein Geheimnis steht nicht darin — die Schlüsselautorisierung ist
        // genau das, was jeder von aussen abrufen können soll.
        chmod($file, 0o644);
    }

    public function ready(string $domain, string $token, string $keyAuthorization): bool
    {
        return is_file($this->file($token));
    }

    public function cleanup(string $domain, string $token): void
    {
        @unlink($this->file($token));
    }

    /**
     * Der Pfad der Prüfdatei — mit der Prüfung, die hier nicht fehlen darf.
     *
     * **Der Token kommt von aussen und wird zu einem Dateinamen.** Dass die
     * Gegenstelle vertrauenswürdig ist, ist eine Annahme über heute; eine
     * Positivliste ist eine Aussage über jeden Tag. RFC 8555 lässt für den
     * Token ausschliesslich base64url zu, und genau das steht hier — ein `/`
     * oder ein `..` käme damit nie bis zu `file_put_contents`, das hier als
     * root läuft.
     */
    private function file(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $token) !== 1) {
            throw AgentException::badRequest('Unzulässiger Token für die Prüfdatei.', ['token' => $token]);
        }

        return $this->directory.self::PREFIX.'/'.$token;
    }
}
