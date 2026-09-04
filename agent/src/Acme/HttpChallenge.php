<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Maintenance;
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

    /**
     * Hier liegen die Prüfdateien aller Domains dieses Servers.
     *
     * **Unter `/var/spool` und nicht unter `/var/lib/srvpanel`** — gemessen am
     * 24. August 2026 auf `cloudsrv24`, nachdem drei frisch angelegte Domains
     * kein Zertifikat bekamen. Die Prüfdatei steht auf `0644`, ihre
     * Verzeichnisse auf `0755`, und ausgeliefert hat nginx trotzdem nichts:
     * Das Paket legt `/var/lib/srvpanel` als `0750 srvpanel:srvpanel` an, der
     * nginx-Worker läuft als `www-data`, und `www-data` gehört dieser Gruppe
     * nicht an — `postinstall.sh` nimmt `srvpanel` in die Gruppe `www-data`
     * auf, nicht umgekehrt. Er kam nicht einmal hindurch. Die
     * Zertifizierungsstelle las **403** und meldete „Invalid response … : 403"
     * — eine Zahl, in der von Rechten nichts steht.
     *
     * Gemessen als Paar an einem einzigen Bit: `403` · mit `o+x` auf
     * `/var/lib/srvpanel` `200` · nach `o-x` wieder `403`, dazu im Log der
     * Domain `open(…) failed (13: Permission denied)`.
     *
     * > **Eine Datei, die für alle lesbar ist, ist damit nicht erreichbar —
     * > der Weg zu ihr entscheidet.**
     *
     * **Dieselbe Frage ist in P6 schon einmal gestellt und beantwortet
     * worden**, für die Aufzeichnungen der Cronjobs: {@see
     * \SrvPanel\Agent\Ops\CronApply::SPOOL_DIR} nennt denselben Grund und
     * dieselbe Antwort. Hier hat sie niemand gestellt. Die Alternative wäre
     * gewesen, `/var/lib/srvpanel` ein `o+x` zu geben — also die Rechte des
     * Panels aufzuweichen, damit ein Fremder an einem Unterverzeichnis vorbei
     * darf. Der Satz dagegen steht dort ebenfalls: *Wer ein Verzeichnis
     * öffnet, damit ein anderer durchkommt, öffnet es für alle, die
     * vorbeikommen.*
     *
     * **Wer diesen Wert ändert, ändert den Ablageort und die `root`-Zeile
     * zugleich** — beide kommen von hier, siehe {@see self::nginxLocation()}.
     * Was er nicht ändert, sind die Blöcke, die schon auf der Platte stehen:
     * Den der Oberfläche zieht jedes Update nach, die der Kundendomains erst
     * `srvpanel vhost --sites`.
     */
    public const DIRECTORY = '/var/spool/srvpanel/acme-challenge';

    /** Der Pfad aus der Adresse — er ist Teil des Ablageorts, siehe oben. */
    public const PREFIX = '/.well-known/acme-challenge';

    /**
     * Die Zeichen, aus denen ein Token bestehen darf.
     *
     * Nicht erfunden: RFC 8555 §8.1 schreibt vor, dass ein Token nur Zeichen
     * des base64url-Alphabets enthält. **Das ist der Teil, an dem etwas hängt**
     * — kein `/`, kein `.`, kein `?`, also weder ein Ausbruch aus dem Pfad noch
     * eine Abfrage dahinter.
     *
     * Als Zeichenklasse und nicht als fertiger Ausdruck, weil zwei Leser ihn
     * verschieden brauchen: {@see self::TOKEN} setzt die Länge dazu,
     * {@see Maintenance} nur ein `+`.
     */
    public const TOKEN_CHARS = '[A-Za-z0-9_-]';

    /**
     * Die volle Form, wie die Prüfdatei sie verlangt.
     *
     * Die Grenzen 16 und 128 sind die dieses Panels und weit genug für die 43
     * Zeichen, die Let's Encrypt schickt. Sie stehen **hier** und nicht in der
     * Wache des Wartungsmodus: Dort entschiede eine zu enge Grenze darüber, ob
     * eine Erneuerung während einer Wartung durchkommt, und der Fehler fiele
     * zur falschen Seite — ein zu kurzer Token wird vom Leser hier ohnehin
     * abgewiesen, ein abgewiesener Token dort kostet ein Zertifikat.
     */
    public const TOKEN = self::TOKEN_CHARS.'{16,128}';

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
        if (preg_match('/^'.self::TOKEN.'$/D', $token) !== 1) {
            throw AgentException::badRequest('Unzulässiger Token für die Prüfdatei.', ['token' => $token]);
        }

        return $this->directory.self::PREFIX.'/'.$token;
    }
}
