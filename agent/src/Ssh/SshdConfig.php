<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ssh;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Ops\PgRemoteAccess;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\SiteTemplate;

/**
 * Der verwaltete Block in `sshd_config` — ein `Match` je Abonnement.
 *
 * ## Die drei Messungen, die diese Datei bestimmen
 *
 * **1. Der Block gehört ans Dateiende, und `Match all` schliesst ihn ab.**
 * Gemessen (`docs/57 §6`): Eine nicht eingerückte Zeile hinter einem
 * `Match`-Block gehört noch zu ihm, und `sshd -t` meldet `rc=0`. Unser
 * `# END srvpanel` ist für sshd ein Kommentar wie jeder andere.
 *
 * > **Eine Endmarke sagt, wo unser Text aufhört. Sie sagt nicht, wo seine
 * > Wirkung aufhört.**
 *
 * Ohne das abschliessende `Match all` fiele die nächste Zeile, die der
 * Betreiber an *seine* Datei hängt, in **unseren** letzten Block — eine
 * Änderung, die er an seiner Datei macht und die still nur noch für einen
 * Kunden gilt. {@see SshdConfigTest} rechnet es nach.
 *
 * **2. Der erste passende Block gewinnt** (`docs/57 §7`). Vom Dateiende aus
 * gewinnt damit alles, was der Betreiber selbst eingetragen hat — „der Bestand
 * ist Gesetz" gilt wörtlich statt dem Sinne nach. Ein Drop-in unter
 * `sshd_config.d/` wäre das Gegenteil: `Include` steht oben, und was oben
 * eingebunden wird, schlägt ihn.
 *
 * **3. Ein Zeilenumbruch in einem Namen macht aus einem Block zwei**
 * (`docs/57 §11`) — untergeschoben wurde in der Messung `PermitRootLogin yes`
 * und ein `ChrootDirectory /` für einen Benutzer, der im Aufruf nicht vorkam.
 * `sshd -t`: `rc=0`. Deshalb gehen Benutzer und Wurzel durch dieselben
 * Prüfungen, die auch den Pfad bauen ({@see SubscriptionProvision}), und nicht
 * durch eine zweite Fassung davon.
 *
 * ## Warum die Schlüsseldatei ausserhalb des Chroots liegt
 *
 * `.ssh/authorized_keys` im Abonnement gehört dem Kunden — er kann sie über
 * genau den Zugang ändern, den sie gewährt, und die Fingerabdrücke im Panel
 * wären dann eine Auskunft über die Hälfte der Wahrheit. Gemessen
 * (`docs/57 §4`): Eine `AuthorizedKeysFile`-Angabe im Block **ersetzt** die
 * Vorgabe, sie ergänzt sie nicht — der Schlüssel, den der Kunde sich selbst
 * hinlegt, kommt nicht herein.
 */
final class SshdConfig
{
    /** Die Datei. Sie gehört OpenSSH und dem Betreiber; wir haben einen Bereich darin. */
    public const FILE = '/etc/ssh/sshd_config';

    /** Wo die Schlüsseldateien liegen — ausserhalb jedes Chroots, root:root. */
    public const KEYS = '/etc/srvpanel/ssh';

    /**
     * Die Zeile, die den Bereich beendet — für sshd und nicht nur für den Leser.
     *
     * Sie steht als Konstante da, weil {@see SshdConfigTest} sie prüft und ein
     * zweites Mal getippt genau der Fehler wäre, den sie verhindern soll.
     */
    public const TERMINATOR = 'Match all';

    /**
     * Wie viele Abonnements ein Aufruf tragen darf.
     *
     * Wie {@see PgRemoteAccess} keine Kapazitätsaussage,
     * sondern ein Riegel: Was hier ankommt, geht Zeile für Zeile in eine Datei,
     * die den Zugang zum Server entscheidet.
     */
    public const MAX_ACCESSES = 2048;

    /**
     * Der Block für ein Abonnement.
     *
     * `ForceCommand internal-sftp` braucht **keine Binärdatei im Chroot**
     * (`docs/50 §6`), eine Shell schon — deshalb gibt es hier keine.
     *
     * `-u 0027` setzt die umask des SFTP-Servers: Was der Kunde hochlädt, wird
     * `0640`/`0750` und trägt über das setgid-Bit an `httpdocs` die Gruppe
     * `www-data` — dieselbe Rechnung wie in `docs/53` Befund 3 für den
     * Dateimanager. Ohne sie käme eine hochgeladene Datei als `0644` an und
     * wäre nur über das Weltbit lesbar.
     *
     * **`AuthenticationMethods publickey` und nicht nur `PasswordAuthentication
     * no`.** Der Unterschied ist am 17. August 2026 gegen OpenSSH 9.6p1
     * gemessen worden, nachdem der Betreiber im Abnahmelauf eine
     * Passwortabfrage bekommen hat (`docs/59`, Befund 6). Mit einem
     * Kopfteil in der Vorgabe der Distribution:
     *
     * | Block | angeboten wird |
     * |---|---|
     * | `PasswordAuthentication no` | `publickey,keyboard-interactive` |
     * | dazu `AuthenticationMethods publickey` | `publickey` |
     *
     * `PasswordAuthentication no` schliesst **eine** von zwei Türen:
     * `KbdInteractiveAuthentication` bleibt `yes`, und über PAM fragt die
     * zweite Tür nach demselben Passwort. Der Kunde hat keines, es kann also
     * nicht gelingen — aber er wird gefragt, und ein Wörterbuchangriff nimmt
     * den Weg durch PAM.
     *
     * > **Ein Riegel, der eine von zwei Türen schliesst, ist eine Auskunft über
     * > die Tür und keine über das Haus.**
     *
     * Genommen wird die Positivliste und nicht der zweite Riegel
     * (`KbdInteractiveAuthentication no` täte hier dasselbe, gemessen): Sie
     * nennt, was gilt, statt aufzuzählen, was nicht gilt — dieselbe
     * Entscheidung wie bei der Programmliste des Agenten. Eine Tür, die
     * OpenSSH später dazubekommt, ist damit von vornherein zu.
     *
     * @return list<string>
     */
    public static function block(string $user, string $root): array
    {
        return [
            'Match User '.$user,
            '    ChrootDirectory '.$root,
            '    ForceCommand internal-sftp -u 0027',
            '    AuthorizedKeysFile '.self::keyFile($user),
            '    AuthenticationMethods publickey',
            '    PasswordAuthentication no',
            '    PermitTTY no',
            '    AllowTcpForwarding no',
            '    X11Forwarding no',
        ];
    }

    /**
     * Alle Blöcke, und dahinter der Abschluss.
     *
     * **Der Aufruf trägt immer den vollständigen Sollzustand**, nicht eine
     * Änderung — wortgleich die Überlegung aus `docs/42`:
     *
     * > **Eine Reihenfolge, die erst beim Ausführen entsteht, kann beim
     * > Einreihen niemand kennen.**
     *
     * Sortiert und einmalig, damit „hat sich etwas geändert" nicht von der
     * Reihenfolge zweier Datenbankzeilen abhängt. Zwei Blöcke für denselben
     * Benutzer wären ausserdem still wirkungslos: Der zweite käme nie zum Zug
     * (`docs/57 §7`).
     *
     * **Der Aufruf nennt den Namen des Abonnements und nicht seinen Pfad.**
     * Wortgleich die wichtigste Entscheidung aus {@see SubscriptionProvision}:
     * Eine Operation, die einen Pfad annimmt und ihn danach prüft, ist eine
     * Operation, deren Prüfung irgendwann eine Lücke hat. Gebaut wird er hier.
     *
     * @param  list<array{user: string, name: string}>  $accesses
     * @return list<string>
     */
    public static function lines(array $accesses): array
    {
        if (count($accesses) > self::MAX_ACCESSES) {
            throw AgentException::badRequest(sprintf(
                'Zu viele Zugänge in einem Aufruf: %d, erlaubt sind %d.',
                count($accesses),
                self::MAX_ACCESSES,
            ));
        }

        $blocks = [];

        foreach ($accesses as $access) {
            $user = SubscriptionProvision::systemUser($access['user'] ?? null);
            $name = SubscriptionProvision::subscriptionName($access['name'] ?? null);

            $blocks[$user] = self::block($user, SubscriptionProvision::VHOSTS.'/'.$name);
        }

        if ($blocks === []) {
            return [];
        }

        ksort($blocks);

        $lines = [];

        foreach ($blocks as $block) {
            foreach ($block as $line) {
                $lines[] = $line;
            }
        }

        /*
         * **Der Abschluss, ohne den der Bereich kein Ende hat.** Er steht
         * *innerhalb* des verwalteten Bereichs und nicht dahinter: Was hinter
         * `# END srvpanel` steht, gehört dem Betreiber, und dort etwas
         * hinzuschreiben wäre genau der Übergriff, den dieser Bereich vermeidet.
         */
        $lines[] = self::TERMINATOR;

        return $lines;
    }

    /** Die Schlüsseldatei eines Systembenutzers. */
    public static function keyFile(string $user): string
    {
        return self::KEYS.'/'.$user;
    }

    /**
     * Den fertigen Text erzeugen — als reine Funktion, damit ein Test ihn ohne Datei liest.
     *
     * Dieselbe Bauart wie {@see SiteTemplate}: Der Schutz ist
     * eine Eigenschaft der erzeugten Zeichenkette und nicht eines Systemzustands.
     *
     * @param  list<array{user: string, name: string}>  $accesses
     */
    public static function render(string $content, array $accesses): string
    {
        return ManagedBlock::render($content, self::lines($accesses), self::FILE);
    }
}
