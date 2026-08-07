<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\PanelVhost;
use SrvPanel\Agent\SiteTemplate;

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

    /** Die Datei liegt sofort — hier zählen Sekunden, nicht Minuten. */
    public const PATIENCE_SECONDS = 30;

    public const PATIENCE_INTERVAL = 2;

    public function __construct(private readonly string $directory = self::DIRECTORY) {}

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * Der `location`-Block, mit dem nginx diese Dateien ausliefert.
     *
     * **Er steht hier und nicht in den beiden Vorlagen.** Der Ablageort und
     * die Zeile, die ihn ausliefert, sind eine Zusage: Wer das Verzeichnis
     * ändert und die Vorlage vergisst, bekommt keine Fehlermeldung, sondern
     * eine Prüfung, die nichts findet. Zwei Formulierungen derselben Regel
     * sind der Fehler, der dieses Projekt sechsmal getroffen hat — deshalb
     * fragen {@see SiteTemplate} und
     * {@see PanelVhost} beide hier.
     *
     * **`root` und nicht `alias`, und das ist die Stelle, die still
     * danebengeht.** `root` hängt den *ganzen* Pfad aus der Adresse an das
     * Verzeichnis an — die Datei liegt also unter
     * `<Verzeichnis>/.well-known/acme-challenge/<Token>`, und genau dorthin
     * schreibt {@see self::present()}. Mit `alias` wäre der Pfad um zwei
     * Ebenen kürzer, nginx suchte in einem Verzeichnis, in das nie jemand
     * schreibt, und die Prüfung scheiterte mit „unauthorized" — einer Meldung,
     * in der von Pfaden nichts steht.
     *
     * **`^~`**, damit der Präfix gewinnt: Sonst entschiede in der Kundenvorlage
     * die Regel `location ~ /\.` über Punktdateien, und die verweigert.
     */
    public static function nginxLocation(): string
    {
        $directory = self::DIRECTORY;
        $prefix = self::PREFIX;

        return <<<CONF
            # Prüfdatei für das Zertifikat. Sie liegt für alle Domains dieses
            # Servers an einer Stelle — so braucht kein Kunde irgendwo
            # Schreibrechte, und eine Domain ohne DocumentRoot bekommt trotzdem
            # ein Zertifikat.
            location ^~ {$prefix}/ {
                root {$directory};
                default_type text/plain;
                access_log off;
            }
        CONF;
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

    /**
     * Die Datei liegt, sobald sie geschrieben ist.
     *
     * Gewartet wird trotzdem kurz: Zwischen dem Schreiben und dem ersten
     * erfolgreichen Lesen liegt bei einem gemounteten Dateisystem
     * gelegentlich ein Augenblick. Was hier zählt, ist der Unterschied zu
     * DNS-01 — dort sind es Minuten, hier Sekunden.
     */
    public function patience(): Patience
    {
        return new Patience(self::PATIENCE_SECONDS, self::PATIENCE_INTERVAL);
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
