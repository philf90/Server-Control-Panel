<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;

/**
 * Die eine Zeile in `pg_hba.conf`, ohne die das Zurückspielen nicht anfangen kann.
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
        $content = @file_get_contents($path);

        if ($content === false) {
            throw AgentException::execFailed('pg_hba.conf liess sich nicht lesen.', ['path' => $path]);
        }

        if (str_contains($content, self::MARK)) {
            return false;
        }

        if (@file_put_contents($path, self::prepend($content)) === false) {
            throw AgentException::execFailed('pg_hba.conf liess sich nicht schreiben.', ['path' => $path]);
        }

        return true;
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
}
