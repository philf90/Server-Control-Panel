<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names as DbNames;
use SrvPanel\Agent\Ops\PgDatabaseRemove;
use SrvPanel\Agent\Ops\PgRestore;
use SrvPanel\Agent\Ops\PgRoleCreate;
use SrvPanel\Agent\Ops\PgRoleGrant;

/**
 * Die Eigentümerrolle eines Abonnements — und die vier Anweisungen, die sie
 * wirksam machen.
 *
 * **Sie ist die Antwort auf den Unterschied, den P5 nicht kannte:** In MariaDB
 * haben alle Zugänge eines Abonnements dieselben Rechte auf dasselbe Schema —
 * ein Recht dort gilt für alles, was darin entsteht. In PostgreSQL gehört jede
 * Tabelle dem, der sie angelegt hat, und Eigentum ist kein Recht, das sich
 * vergeben lässt. Zwei Messungen vom 10. August 2026 (`docs/39`, Punkt 7):
 *
 *     x_cron auf einer Tabelle von x_web  → ERROR: permission denied for table
 *     nach dem Zurückspielen gehört alles → root, der Kunde kommt nicht heran
 *
 * Beides ist dieselbe Frage: *Wem gehört, was in dieser Datenbank steht?* Die
 * Antwort dieses Panels ist **dem Abonnement** und nicht einem seiner Zugänge.
 * Dafür gibt es eine Rolle je Abonnement, die sich nirgends anmeldet.
 *
 * ## Die Mechanik, in vier Zeilen
 *
 * | Anweisung | wo sie läuft | was sie bewirkt |
 * |---|---|---|
 * | {@see self::creation()} | einmal je Abonnement | die Rolle gibt es |
 * | {@see self::schemaStatements()} | **in** der Datenbank | `public` gehört ihr |
 * | {@see self::membership()} | clusterweit | ein Zugang darf sie annehmen |
 * | {@see self::sessionRole()} | clusterweit, je Datenbank | er tut es bei jeder Sitzung |
 *
 * Gemessen am 10. August 2026 gegen PostgreSQL 16.13: `x_web` legt an, die
 * Tabelle gehört `x_owner`, und `x_cron` liest, ändert und löscht sie.
 * `session_user` bleibt dabei der Zugang selbst — **wer verbunden ist, steht
 * weiter im Protokoll von PostgreSQL**, und das ist der Grund für `SET role`
 * statt `SET SESSION AUTHORIZATION`.
 *
 * ## Was sie ausdrücklich **nicht** besitzt
 *
 * Die Datenbank. Die gehört dem Panel — Entscheidung 2 aus `docs/38 §21`, und
 * sie steht hier nicht zur Disposition: Der Eigentümer einer Datenbank darf
 * `GRANT CONNECT … TO PUBLIC` und macht damit die Absperrung rückgängig, die
 * das Abnahmekriterium von P5b ist. Ein Schema kann er freigeben; das trifft
 * nur die eigene Datenbank, in die ohnehin niemand hineinkommt.
 *
 * ## Warum die Mitgliedschaft clusterweit ist und trotzdem nichts öffnet
 *
 * `GRANT x_owner TO x_web` gilt nicht je Datenbank — PostgreSQL kennt keine
 * Mitgliedschaft mit Geltungsbereich. Das ist keine Lücke: Die Rolle trägt das
 * Präfix **eines** Abonnements, und der Agent lässt keinen Namen durch, der zu
 * einem anderen gehört ({@see DbNames::belongsTo()}). Was ein Zugang durch sie
 * erreicht, gehört seinem eigenen Abonnement.
 *
 * Deshalb wird die Mitgliedschaft beim Anlegen des Zugangs vergeben und erst
 * mit ihm entfernt — nicht bei jeder Freigabe einer einzelnen Datenbank. Was je
 * Datenbank wechselt, ist die Sitzungsrolle ({@see PgRoleGrant}).
 */
final class Owner
{
    public function __construct(private readonly Session $session = new Session) {}

    /**
     * Sorgt dafür, dass es die Eigentümerrolle gibt, und nennt ihren Namen.
     *
     * **Fragen und dann anlegen**, wie in {@see Ephemeral::group()} und aus
     * demselben Grund: `CREATE ROLE` kennt kein `IF NOT EXISTS`, und ein
     * anonymer Block brächte Dollar-Anführung durch drei Ebenen.
     *
     * Sie wird an **vier** Stellen gebraucht — beim Anlegen einer Datenbank,
     * beim Anlegen eines Zugangs, beim Freigeben und beim Zurückspielen. Dass
     * jede von ihnen sie selbst sicherstellt, ist dasselbe Muster wie bei
     * {@see PgRestore}: *Was eine Operation braucht, stellt
     * sie selbst sicher* — sonst fehlt sie auf jedem Abonnement, das vor dieser
     * Fassung entstanden ist.
     */
    public function ensure(Context $context, string $prefix): string
    {
        $owner = Names::owner($prefix);

        if ($this->session->query($context, sprintf(
            'SELECT 1 FROM pg_roles WHERE rolname = %s',
            Sql::text($owner),
        )) !== []) {
            return $owner;
        }

        $this->session->execute($context, [self::creation($owner)]);

        return $owner;
    }

    /**
     * Die Rolle selbst.
     *
     * **`NOLOGIN` und kein Passwort.** Sie ist ein Name für „was diesem
     * Abonnement gehört" und kein Zugang; über sie meldet sich niemand an. Alles
     * Weitere steht ausdrücklich da, obwohl PostgreSQL es ohnehin nicht vergibt
     * — dieselbe Begründung wie in {@see PgRoleCreate}: Eine
     * Zusage, die von einer Vorgabe abhängt, ist keine.
     */
    public static function creation(string $owner): string
    {
        return sprintf(
            'CREATE ROLE %s NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS',
            Sql::identifier($owner),
        );
    }

    /**
     * Das Schema `public` gehört ihr — **die Anweisungen laufen in der
     * Datenbank**.
     *
     * Dieselbe Trennung wie in {@see PgRoleGrant}: `ALTER SCHEMA` gilt für das
     * Schema *der verbundenen Datenbank*. Wer das in `postgres` schickt,
     * verschenkt das Schema der falschen Datenbank — und es fiele erst auf, wenn
     * ein Kunde seine Tabellen nicht anlegen kann.
     *
     * **Der Entzug von `PUBLIC` steht mit dabei und ist keine Doppelung zu
     * {@see Shielding}.** Diese Anweisungen laufen auch, nachdem das Schema neu
     * angelegt wurde ({@see self::reset()}), und dann ist die Absperrung von
     * damals mit dem alten Schema fort.
     *
     * @return list<string>
     */
    public static function schemaStatements(string $owner): array
    {
        return [
            'ALTER SCHEMA public OWNER TO '.Sql::identifier($owner),
            'REVOKE ALL ON SCHEMA public FROM PUBLIC',
        ];
    }

    /**
     * Ein leeres Schema, das ihr gehört — **privilegiert**.
     *
     * **Das ist der dritte Fund aus Punkt 7, und der überraschendste.** Ein
     * Zurückspielen in eine Datenbank, in der schon Tabellen stehen, scheitert
     * überhaupt: `pg_dump --format=plain` schreibt kein `DROP`, während
     * `mysqldump` sein `DROP TABLE IF EXISTS` von selbst mitbringt. Gemessen am
     * 10. August 2026, wörtlich —
     *
     *     psql:/…/x…-2026.sql:38: ERROR:  relation "kunden" already exists
     *
     * P5b hat diese stillschweigende Vorgabe **geerbt**, ohne dass jemand sie
     * treffen musste.
     *
     * **`pg_dump --clean` trägt nicht.** Die Anweisungen liefen dann unter der
     * befristeten Rolle, und die darf nicht wegräumen, was ihr nicht gehört
     * (`must be owner of table`, gemessen). Das Leeren ist ein privilegierter
     * Vorgang und das Einspielen nicht — deshalb stehen sie hier und nicht im
     * Dump.
     *
     * **`CASCADE` ist der Punkt und nicht der Übereifer.** Ein `DROP SCHEMA`
     * ohne ihn scheitert an der ersten Tabelle. Was hier fällt, sind die Daten,
     * die der Kunde gerade überschreiben lässt — das ist die Bedeutung von
     * „zurückspielen".
     *
     * @return list<string>
     */
    public static function reset(string $owner): array
    {
        return array_merge(
            ['DROP SCHEMA public CASCADE', 'CREATE SCHEMA public'],
            self::schemaStatements($owner),
        );
    }

    /**
     * Ein Zugang darf die Rolle annehmen.
     *
     * **Ohne `WITH ADMIN OPTION`** — sonst reichte ein Kunde die Mitgliedschaft
     * weiter, und die Grenze eines Abonnements hinge daran, dass er es nicht
     * tut.
     */
    public static function membership(string $owner, string $role): string
    {
        return sprintf('GRANT %s TO %s', Sql::identifier($owner), Sql::identifier($role));
    }

    /**
     * Und er tut es bei jeder Sitzung in dieser Datenbank.
     *
     * **Der Unterschied zwischen `INHERIT` und dieser Zeile ist das Eigentum.**
     * Ein Zugang, der erbt, *darf* alles, was der Gruppe gehört — was er selbst
     * anlegt, gehört aber weiter ihm, und der nächste Zugang steht wieder vor
     * `permission denied`. Erst `SET role` macht die Gruppe zum Eigentümer des
     * Neuen. Deshalb bleibt es bei `NOINHERIT` und dieser Zeile.
     *
     * **Zurückgenommen wird mit `RESET` und nicht mit `SET role = NONE`.**
     * Gemessen: `RESET` auf einem Eintrag, den es nie gab, endet mit
     * Rückgabewert 0 — die Rücknahme ist damit wiederholbar, und ein
     * abgebrochener Lauf lässt sich zu Ende bringen.
     */
    public static function sessionRole(string $owner, string $role, string $database, bool $granted): string
    {
        return $granted
            ? sprintf(
                'ALTER ROLE %s IN DATABASE %s SET role = %s',
                Sql::identifier($role),
                Sql::identifier($database),
                Sql::text($owner),
            )
            : sprintf(
                'ALTER ROLE %s IN DATABASE %s RESET role',
                Sql::identifier($role),
                Sql::identifier($database),
            );
    }

    /**
     * Die Zugänge dieses Abonnements, die es auf dem Server wirklich gibt.
     *
     * **Hier fragt der Agent ausnahmsweise nach und lässt es sich nicht sagen**
     * — anders als bei {@see PgDatabaseRemove}, wo die
     * Anwendung die Liste schickt. Der Unterschied ist die Richtung des
     * Fehlschlags: Dort ist eine zu lange Liste ein `DROP ROLE` zu viel, hier
     * wäre eine zu kurze ein Kunde, der nach dem Zurückspielen nicht mehr an
     * seine Daten kommt. **Eine Nachrüstung, die etwas auslässt, sieht aus wie
     * eine, die durchgelaufen ist.**
     *
     * Und es ist kein Bestand, den der Agent führte: Gefragt wird der Katalog,
     * und die Antwort wird gegen dasselbe Präfix gehalten, das jede Operation
     * hier prüft. Was zurückkommt, gehört diesem Abonnement — sonst käme es
     * nicht zurück.
     *
     * **Gefragt wird zusätzlich nach `CONNECT` auf dieser Datenbank**, und nicht
     * nur nach dem Präfix. Ein Zugang, dem der Betreiber die Datenbank entzogen
     * hat, bekäme sonst seine Sitzungsrolle zurück — sie öffnete zwar nichts, er
     * kommt ohne `CONNECT` nicht herein, aber die Rücknahme aus
     * {@see PgRoleGrant} stünde danach halb wieder da. **Eine Nachrüstung, die
     * eine Entscheidung von gestern überschreibt, ist keine.**
     *
     * Befristete Rollen bleiben draussen: Sie verschwinden von selbst, und eine
     * Mitgliedschaft für ein paar Minuten regelt {@see Ephemeral} bereits beim
     * Anlegen.
     *
     * @return list<string>
     */
    public function roles(Context $context, string $prefix, string $database): array
    {
        /*
         * **`starts_with()` und nicht `LIKE`.** Das Präfix endet auf einen
         * Unterstrich, und der ist in `LIKE` ein Platzhalter — `x7f3a…_%` träfe
         * auch `x7f3a…X…`. Maskieren liesse sich das, aber dann hinge die
         * Mandantengrenze an einem Gegenschrägstrich, der durch PHP, durch
         * {@see Sql} und durch `psql` geht. `starts_with()` kennt keine
         * Platzhalter; es gibt sie seit PostgreSQL 11, die kleinste Fassung hier
         * ist 14 ({@see Server::MIN_VERSION}).
         *
         * Dieselbe Falle wie `docs/36 §3.1` in MariaDB — dort war sie der
         * teuerste Fund des Entwurfs.
         */
        $rows = $this->session->query($context, sprintf(
            'SELECT rolname FROM pg_roles WHERE rolcanlogin AND starts_with(rolname, %s) '
            .'AND has_database_privilege(rolname, %s, %s) ORDER BY rolname',
            Sql::text(Names::prefix($prefix).'_'),
            Sql::text(Names::existing($database, 'database')),
            Sql::text('CONNECT'),
        ));

        $names = [];

        foreach ($rows as $row) {
            $name = (string) ($row[0] ?? '');

            if ($name === '' || Names::isEphemeral($name) || ! Names::belongsTo($name, $prefix)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }
}
