<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Daemon;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\ManagedBlock;

/**
 * `pg_hba.conf` — die eine Zeile für das Zurückspielen und der Block für den Fernzugriff.
 *
 * **Wie der Bereich geschrieben wird, steht seit dem 16. August 2026 in
 * {@see ManagedBlock}** — dort, wo es auch `sshd_config` benutzt. Was hier
 * blieb, ist das, was nur für PostgreSQL gilt: die Zeile, die Gruppenrolle, die
 * Form einer Regel und die Prüfung eines Netzes.
 *
 * ## Zwei verwaltete Bereiche, und warum es zwei sein müssen
 *
 * Seit P5b Schritt 10 schreiben in diese Datei **zwei** Anliegen: die Zeile für
 * das Zurückspielen (`docs/38 §13.4`, unten) und der Block für den Fernzugriff
 * (`docs/38 §14`, {@see ManagedBlock::BEGIN}). Sie zu einem Bereich zusammenzulegen war
 * der erste Entwurf und ist an einer Messung gescheitert — **die beiden haben
 * entgegengesetzte Platzansprüche:**
 *
 * - Die Zeile muss **ganz oben** stehen, über Debians `local all all peer`.
 *   Steht sie darunter, kommt sie nie zum Zug; gemessen am 11. August 2026 an
 *   einem Wegwerf-Cluster: `FATAL: Peer authentication failed for user
 *   "p1001_eph"`. Die Begründung steht unten bei {@see self::MARK}.
 * - Der Block gehört **ganz nach unten**, hinter alles, was der Betreiber
 *   selbst eingetragen hat. Sonst gewänne eine Zeile von uns über sein
 *   `reject` — und „der Bestand ist Gesetz" (Leitbild 1) wäre eine Behauptung.
 *
 * **Sie streiten sich trotzdem nicht**, und auch das ist gemessen: Die Zeile
 * ist `local`, der Block ist `host`. Die erste passende Zeile gewinnt — aber
 * eine `local`-Zeile passt auf keine TCP-Verbindung und umgekehrt. Die beiden
 * Bereiche stehen im selben Blatt Papier und in verschiedenen Absätzen.
 *
 * ## Der Fehlerweg, der selbst fehlschlagen konnte
 *
 * **Was sie sich sehr wohl streitig machen, ist die Datei.** Der Agent gabelt
 * je Verbindung ({@see Daemon}), zwei Operationen sind also
 * zwei Prozesse. Am 11. August 2026 nachgestellt, drei Wege und jeder verliert:
 *
 * | wer schreibt | was verschwindet |
 * |---|---|
 * | `pg.remote.access` schreibt seinen Block auf einem Stand von vor {@see self::ensure()} | die Zeile |
 * | `ensure()` schreibt seinen Stand von vor dem Block | der Block |
 * | **der Rückweg** legt den Stand von Schritt 1 zurück | die Zeile |
 *
 * Der dritte ist der teuerste, denn er ist der *Fehlerweg*: Genau der Griff,
 * der den Server vor einer kaputten Datei retten soll, wirft dabei die Zeile
 * weg, an der das Zurückspielen hängt — und niemand merkt es, weil das
 * Zurückspielen erst Wochen später wieder läuft. Wortgleich die Lehre aus
 * P5b: **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
 *
 * Deshalb geht **jeder** Zugriff auf diese Datei durch {@see ManagedBlock::locked()},
 * und jedes Schreiben durch {@see ManagedBlock::put()} — ein `file_put_contents` kürzt
 * erst und schreibt dann, und ein Abbruch dazwischen liesse eine leere
 * `pg_hba.conf` zurück. Die ist syntaktisch fehlerfrei und weist jeden ab.
 *
 * ---
 *
 * ## Die eine Zeile, ohne die das Zurückspielen nicht anfangen kann
 *
 * ## Der Befund, der `docs/38 §13.4` umgeworfen hat
 *
 * §13.4 sagt „wie `docs/36 §10.2`" — dort meldet sich der befristete Benutzer
 * über den Unix-Socket mit Passwort an, und MariaDB lässt das zu. **PostgreSQL
 * auf Debian nicht:** Die erste Zeile der ausgelieferten `pg_hba.conf` lautet
 * `local all all peer`, und `peer` heisst *der Name der Rolle muss der Name des
 * Unix-Benutzers sein*. Eine Rolle `x7f3a91c2b40e15d6_r1a2b3c4d` hat keinen
 * gleichnamigen Benutzer und kommt damit gar nicht erst herein — gemessen am
 * 9. August 2026:
 *
 *     FATAL:  Peer authentication failed for user "m_eph"
 *
 * **Die Ursache war nicht ein Recht, sondern die Anmeldung** — und das ist der
 * Grund, warum dieser Fund so leicht zu übersehen war: Er sieht wie ein
 * Rechtefehler aus und steht in einer Datei, die mit Rechten nichts zu tun hat.
 *
 * ## Warum eine Gruppenrolle und nicht ein Muster
 *
 * `pg_hba.conf` kennt für Rollennamen kein Muster; was es kennt, ist `+gruppe`
 * — „diese Rolle oder eine, die Mitglied darin ist". Deshalb gibt es
 * {@see self::GROUP}: eine Rolle ohne Anmeldung, ohne Rechte und ohne Passwort,
 * die nichts weiter tut, als Mitglieder zu haben. {@see Ephemeral} legt jede
 * befristete Rolle `IN ROLE` darin an; die Zeile hier lässt genau diese
 * Mitglieder über den Socket mit Passwort herein und sonst niemanden.
 *
 * **Der Weg über `127.0.0.1` wäre der andere gewesen**, und er ist gemessen
 * worden: Er läuft ohne jede Änderung an der Konfiguration, weil
 * `scram-sha-256` für `127.0.0.1/32` im Debian-Standard steht. Er hängt dafür
 * an `listen_addresses` — ein Betreiber, der PostgreSQL auf den Socket
 * beschränkt, verlöre das Zurückspielen, und zwar lautlos und erst dann, wenn
 * er es braucht. Der Betreiber hat sich am 9. August für den Socket entschieden.
 *
 * ## Warum die Zeile ganz oben steht
 *
 * `pg_hba.conf` wird von oben nach unten gelesen, und **die erste passende
 * Zeile entscheidet** — auch wenn sie abweist. Stünde `local all +srvpanel_restore`
 * unter `local all all peer`, käme sie nie zum Zug: Die Zeile darüber passt auf
 * jede Rolle, scheitert an `peer`, und danach wird nicht weitergesucht. Das ist
 * dieselbe Falle wie bei einer Firewall-Regel hinter einem `DROP` und in
 * `docs/28 §6` für nginx schon einmal aufgeschrieben.
 */
final class Hba
{
    /**
     * Die Gruppenrolle, deren Mitglieder sich über den Socket anmelden dürfen.
     *
     * `NOLOGIN` und ohne Passwort: Sie ist ein Sammelbegriff und kein Zugang.
     * Wer sie zum Anmelden benutzen wollte, müsste ihr erst ein Passwort geben
     * — und das stünde dann in `pg_authid` und fiele auf.
     */
    public const GROUP = 'srvpanel_restore';

    /**
     * Die Zeile selbst.
     *
     * `local` — nur der Unix-Socket. `all` als Datenbank, weil die befristete
     * Rolle je Lauf eine andere Datenbank meint und `pg_hba.conf` keinen
     * Platzhalter für „die eine, für die sie berechtigt ist" kennt. Das kostet
     * nichts: Welche Datenbanken sie erreicht, entscheidet `CONNECT`, und das
     * hat sie auf genau eine ({@see Ephemeral}).
     */
    public const RULE = 'local   all   +'.self::GROUP.'   scram-sha-256';

    /**
     * Die Marke, an der sich die Zeile wiedererkennt.
     *
     * **Nicht die Zeile selbst.** Wer nach der Zeile sucht, findet sie nach
     * jeder Änderung an ihrer Schreibweise nicht mehr und schreibt sie ein
     * zweites Mal dazu — bei jedem Lauf eine mehr. Die Marke ist das, was
     * gleich bleibt; dieselbe Bauart wie die Blöcke in `nginx.conf`
     * (`docs/28 §4`).
     */
    public const MARK = '# srvpanel: Zurückspielen einer Sicherung (docs/38 §13.4)';

    /**
     * Sorgt dafür, dass die Zeile da ist — und schreibt nur, wenn sie fehlt.
     *
     * **Der Rückgabewert sagt, ob geschrieben wurde**, weil der Aufrufer dann
     * neu laden muss und sonst nicht. Ein `pg_ctlcluster … reload` bei jedem
     * Zurückspielen wäre ein Signal an den Server für nichts.
     */
    public static function ensure(string $path): bool
    {
        return ManagedBlock::locked($path, static function () use ($path): bool {
            $content = ManagedBlock::read($path);

            if (str_contains($content, self::MARK)) {
                return false;
            }

            ManagedBlock::put($path, self::prepend($content));

            return true;
        });
    }

    /**
     * Die Zeile vor den Bestand setzen — als Text, damit sie prüfbar ist.
     *
     * Getrennt vom Schreiben und `public static`, aus demselben Grund wie
     * {@see Clusters::parse()}: Was hier zählt, ist die *Stelle*, an der die
     * Zeile landet, und die lässt sich an einer Zeichenkette prüfen. An einer
     * laufenden Konfiguration liesse sie sich nur dadurch prüfen, dass man die
     * Anmeldung versucht — und dann prüfte man die Anmeldung.
     */
    public static function prepend(string $content): string
    {
        return self::MARK."\n".self::RULE."\n\n".$content;
    }

    /**
     * Die Methode, mit der sich ein Fernzugang anmeldet.
     *
     * `scram-sha-256` und nicht `md5`: Debian schreibt es seit PG 14 selbst in
     * seine Vorgabe, und die Rollen dieses Panels bekommen ihr Passwort mit
     * `password_encryption = scram-sha-256` — eine `md5`-Zeile fände zu ihnen
     * gar keinen Prüfwert.
     */
    public const METHOD = 'scram-sha-256';

    /**
     * Eine Zeile des Blocks.
     *
     * **Sie nennt die Datenbank und nicht `all`** (`docs/38 §14.1`, M23). Das
     * ist die zweite Wand hinter dem `REVOKE CONNECT` aus §10: Selbst wenn
     * jemand das Recht wieder aufmachte, käme die Rolle über diese Zeile in
     * keine andere Datenbank als ihre eigene. Gemessen — dieselbe Rolle in die
     * Datenbank `postgres`: `no pg_hba.conf entry for host "127.0.0.1", user
     * "p1001_web", database "postgres"`.
     */
    public static function rule(string $database, string $role, string $cidr): string
    {
        return sprintf('host    %s   %s   %s   %s', $database, $role, $cidr, self::METHOD);
    }

    /**
     * Ein Netz, wie `pg_hba.conf` es schreibt — und die drei Fälle, die es nicht sind.
     *
     * **1. Ohne Präfixlänge ist die Zeile kaputt**, und zwar nicht „ungenau",
     * sondern unlesbar. `pg_hba.conf` kennt neben `adresse/länge` eine Form mit
     * *zwei* Spalten (`adresse maske methode`) — steht keine Länge da, liest
     * der Parser die Methode als Maske. Gemessen am 11. August 2026:
     *
     *     host p1001_shop p1001_web 203.0.113.5 scram-sha-256
     *     → 123: invalid IP mask "scram-sha-256": Name or service not known
     *
     * Eine blosse Adresse ist deshalb keine Bequemlichkeit, die wir verweigern,
     * sondern eine Landmine, die wir ergänzen: Sie bekommt `/32` bzw. `/128`.
     *
     * **2. Gesetzte Wirtsbits werden abgewiesen, obwohl PostgreSQL sie
     * annimmt.** `198.51.100.5/24` erzeugt *keinen* Fehler — der Server macht
     * daraus stillschweigend `198.51.100.0/24` und lässt 254 Rechner herein,
     * wo jemand einen gemeint hat. Das ist die gefährlichste Sorte Eingabe:
     * angenommen, wirksam, und um drei Grössenordnungen weiter als gedacht.
     * Geraten wird hier nicht — die Meldung nennt beide Auflösungen und lässt
     * den Kunden wählen.
     *
     * **3. `0.0.0.0/0` wird abgewiesen** (`docs/38 §14`, wörtlich aus
     * `docs/36 §12` Punkt 4). Ein Zugang, der von überall erreichbar ist, ist
     * die Vorlage für den nächsten Vorfallsbericht. Wer das will, trägt es in
     * `pg_hba.conf` von Hand ein — dann ist es seine Entscheidung und nicht ein
     * Feld, das wir angeboten haben. `::/0` ist dieselbe Angabe und fällt mit.
     */
    public static function cidr(mixed $value, string $field = 'cidr'): string
    {
        $raw = trim(Guard::string($value, $field));
        $slash = strrpos($raw, '/');
        $address = $slash === false ? $raw : substr($raw, 0, $slash);

        // **`inet_pton` und nicht nur `filter_var`.** Das erste prüft, das
        // zweite rechnet: Ohne die rohen Bytes liesse sich die Netzadresse
        // unten nur über eine Zerlegung in Dezimalgruppen bestimmen — und die
        // wäre für IPv6 eine zweite, andere Fassung derselben Rechnung.
        $binary = filter_var($address, FILTER_VALIDATE_IP) === false ? false : @inet_pton($address);

        if (! is_string($binary)) {
            throw AgentException::badRequest(sprintf(
                '%s ist keine IP-Adresse: %s', $field, $raw,
            ));
        }

        $width = strlen($binary) * 8;

        if ($slash === false) {
            return self::present($binary).'/'.$width;
        }

        $suffix = substr($raw, $slash + 1);

        if (preg_match('/\A[0-9]{1,3}\z/', $suffix) !== 1 || (int) $suffix > $width) {
            throw AgentException::badRequest(sprintf(
                '%s trägt keine gültige Präfixlänge: %s — erlaubt ist 0 bis %d.', $field, $raw, $width,
            ));
        }

        $length = (int) $suffix;

        if ($length === 0) {
            throw AgentException::denied(sprintf(
                '%s deckt das ganze Internet ab. Ein Datenbankzugang, den jeder Rechner der Welt '
                .'erreicht, wird hier nicht eingetragen — nennen Sie das Netz, aus dem Ihre Anwendung '
                .'kommt.', $raw,
            ));
        }

        $network = self::network($binary, $length);

        if ($network !== $binary) {
            throw AgentException::badRequest(sprintf(
                '%s hat gesetzte Wirtsbits. PostgreSQL nähme das an und läse stillschweigend %s/%d — '
                .'gemeint war vermutlich %s/%d (dieser eine Rechner) oder %s/%d (das ganze Netz).',
                $raw,
                self::present($network), $length,
                $address, $width,
                self::present($network), $length,
            ));
        }

        return self::present($binary).'/'.$length;
    }

    /**
     * Die Rolle, auf die eine Regelzeile zeigt — oder `null`.
     *
     * **Gelesen und nicht mitgeführt.** Was in der Datei steht, ist der
     * Zustand; eine Liste im Panel daneben wäre die zweite Fassung, und die
     * zweite ist die, die veraltet. `host <datenbank> <rolle> <netz> <methode>`
     * — das dritte Feld.
     */
    public static function roleOf(string $line): ?string
    {
        $fields = preg_split('/\s+/', trim($line)) ?: [];

        return count($fields) >= 5 && $fields[0] === 'host' ? $fields[2] : null;
    }

    /**
     * Die Datenbank, auf die eine Regelzeile zeigt — oder `null`.
     */
    public static function databaseOf(string $line): ?string
    {
        $fields = preg_split('/\s+/', trim($line)) ?: [];

        return count($fields) >= 5 && $fields[0] === 'host' ? $fields[1] : null;
    }

    /** Die Netzadresse zu einer Präfixlänge — auf den rohen Bytes, damit v4 und v6 dasselbe sind. */
    private static function network(string $binary, int $length): string
    {
        $bytes = str_split($binary);
        $full = intdiv($length, 8);
        $bits = $length % 8;

        foreach ($bytes as $index => $byte) {
            $bytes[$index] = match (true) {
                $index < $full => $byte,
                $index === $full && $bits > 0 => chr(ord($byte) & (0xFF << (8 - $bits)) & 0xFF),
                default => "\0",
            };
        }

        return implode('', $bytes);
    }

    /** Rohe Bytes zurück in die Schreibweise, die in der Datei steht. */
    private static function present(string $binary): string
    {
        $address = @inet_ntop($binary);

        if (! is_string($address)) {
            throw AgentException::badRequest('Die Adresse liess sich nicht zurückschreiben.');
        }

        return $address;
    }
}
