<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Ephemeral as DbEphemeral;
use Throwable;

/**
 * Eine Rolle für die Dauer eines Laufs.
 *
 * **Warum es sie gibt, steht wortgleich in {@see DbEphemeral}:** Das
 * Zurückspielen darf nicht als Superuser laufen. Ein Dump ist beliebiges SQL,
 * und der Kunde lädt ihn hoch. Gemessen am 9. August 2026, was ein solcher Dump
 * unter dieser Rolle erreicht — jede Zeile mit Rückgabewert 3 und Abbruch:
 *
 *     CREATE DATABASE fremd;         → ERROR: permission denied to create database
 *     ALTER ROLE … SUPERUSER;        → ERROR: permission denied to alter role
 *     \connect andere_datenbank      → FATAL: permission denied for database …
 *
 * Dass ein Dump so etwas enthält, ist damit kein Sonderfall, den jemand
 * abfangen muss: Er scheitert an den Rechten, laut und mit der Meldung des
 * Systems.
 *
 * ## Drei Unterschiede zu P5, jeder gemessen
 *
 * **1. Sie braucht `GRANT ALL ON SCHEMA public`.** Seit PostgreSQL 15 darf
 * `PUBLIC` in `public` nicht mehr schreiben; ohne diese Zeile scheitert das
 * Zurückspielen an der ersten `CREATE TABLE` mit `permission denied for schema
 * public` — und zwar erst dort, also nach dem halben Vorspann des Dumps. In
 * MariaDB gab es dazu kein Gegenstück, weil ein Schema dort die Datenbank
 * *ist*.
 *
 * **2. Sie ist Mitglied in {@see Hba::GROUP}**, sonst kommt sie über den Socket
 * gar nicht herein. Der Grund steht dort.
 *
 * **3. `SET` statt eines zweiten `GRANT`.** `CREATE ROLE … IN ROLE` macht sie
 * zum Mitglied; `INHERIT` steht dabei nicht, weil sie aus der Gruppe nichts
 * erben soll — die Gruppe hat nichts. Was sie darf, bekommt sie einzeln.
 *
 * **`finally` und nicht am Ende des Erfolgspfads**, wie in P5. Und weil auch
 * ein `finally` einen Stromausfall nicht überlebt, erkennt
 * {@see Names::isEphemeral()} solche Reste am Namen.
 */
final class Ephemeral
{
    public function __construct(private readonly Session $session = new Session) {}

    /**
     * Legt die Rolle an, führt aus, räumt auf.
     *
     * @template T
     *
     * @param  callable(Credentials): T  $work
     * @return T
     */
    public function with(Context $context, string $prefix, string $database, callable $work): mixed
    {
        $role = Names::ephemeral($prefix);
        $password = self::password();

        $this->session->execute($context, [
            sprintf(
                'CREATE ROLE %s LOGIN PASSWORD %s IN ROLE %s',
                Sql::identifier($role),
                Sql::text($password),
                Sql::identifier(Hba::GROUP),
            ),
            sprintf(
                'GRANT CONNECT ON DATABASE %s TO %s',
                Sql::identifier($database),
                Sql::identifier($role),
            ),
        ]);

        /*
         * **Das Schema wird in der Zieldatenbank vergeben und nicht in
         * `postgres`.** `GRANT … ON DATABASE` ist eine clusterweite Angabe;
         * `GRANT … ON SCHEMA public` gilt für das Schema *der Datenbank, in der
         * die Anweisung läuft*. Dieselbe Trennung wie in
         * {@see \SrvPanel\Agent\Ops\PgRoleGrant}, und derselbe Fehler wäre hier
         * teurer: Er fiele erst mitten im Zurückspielen auf.
         */
        $this->session->execute($context, [
            sprintf('GRANT ALL ON SCHEMA public TO %s', Sql::identifier($role)),
        ], $database);

        try {
            return $work(new Credentials($role, $password));
        } finally {
            /*
             * **Ohne Abbruch, wenn das Aufräumen scheitert** — wortgleich die
             * Begründung aus P5: Eine Ausnahme aus dem `finally` verschluckte
             * die des Laufs, und dann stünde im Vorgang „Rolle liess sich nicht
             * entfernen" statt „der Dump hat an Zeile 40312 abgebrochen".
             *
             * ## Warum hier zwei Anweisungen stehen und nicht eine
             *
             * Ein `DROP ROLE` scheitert, solange der Rolle etwas gehört oder
             * jemand ihr ein Recht gegeben hat. Der naheliegende Weg ist
             * `DROP OWNED BY` — und **er wirft weg, was gerade eingespielt
             * wurde.** Gemessen am 9. August 2026: Der Lauf meldete Erfolg, und
             * danach gab es die Tabelle nicht. Was eine Rolle anlegt, gehört
             * ihr, und beim Zurückspielen legt sie die ganze Datenbank an.
             *
             * Der Fund hat kein Gegenstück in P5: MariaDB kennt kein Eigentum
             * an einer Tabelle, also gab es dort nichts zu übertragen, und
             * `docs/38 §13.4` hat die Frage deshalb nicht gestellt.
             *
             * `REASSIGN OWNED BY … TO` überträgt das Eigentum deshalb zuerst,
             * und zwar an den **Eigentümer der Datenbank** — dieselbe Rolle,
             * der sie ohnehin gehört. Damit steht die Datenbank hinterher so
             * da, als hätte der Agent selbst eingespielt. Das `DROP OWNED BY`
             * danach räumt dann nur noch die Rechte weg, die diesem Lauf
             * gegeben wurden.
             */
            try {
                $this->session->execute($context, [
                    sprintf(
                        'REASSIGN OWNED BY %s TO %s',
                        Sql::identifier($role),
                        Sql::identifier($this->owner($context, $database)),
                    ),
                    sprintf('DROP OWNED BY %s', Sql::identifier($role)),
                ], $database);
            } catch (Throwable $error) {
                $context->journal->write('befristete rolle behielt ihren besitz', [
                    'role' => $role,
                    'error' => $error->getMessage(),
                ]);
            }

            try {
                $this->session->execute($context, [
                    sprintf('DROP ROLE IF EXISTS %s', Sql::identifier($role)),
                ]);
            } catch (Throwable $error) {
                $context->journal->write('befristete rolle blieb stehen', [
                    'role' => $role,
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sorgt dafür, dass es die Gruppenrolle gibt.
     *
     * **Getrennt von {@see self::with()}, weil sie länger lebt als ein Lauf.**
     * Sie steht in `pg_hba.conf`, und eine Zeile dort, die auf eine Rolle zeigt,
     * die es nicht gibt, ist kein Fehler — sie passt nur nie. Das wäre die
     * Sorte Stille, für die dieses Projekt sonst Wächter baut.
     */
    public function group(Context $context): void
    {
        /*
         * **Fragen und dann anlegen, nicht `DO $$ … $$`.** Ein anonymer Block
         * täte dasselbe in einer Anweisung und brächte Dollar-Anführung in eine
         * Zeichenkette, die durch PHP, durch {@see Sql} und durch `psql` geht —
         * drei Ebenen, von denen jede den Dollar anders liest. Zwei Anweisungen
         * sind hier die kürzere Erklärung.
         *
         * Der Zwischenraum zwischen Frage und Anlegen ist kein Problem: Zwei
         * gleichzeitige Läufe legen beide an, der zweite scheitert an der
         * Eindeutigkeit, und der Vorgang ist ohnehin rot. Ein `IF NOT EXISTS`
         * gibt es für `CREATE ROLE` nicht.
         */
        if ($this->session->query($context, sprintf(
            'SELECT 1 FROM pg_roles WHERE rolname = %s',
            Sql::text(Hba::GROUP),
        )) !== []) {
            return;
        }

        $this->session->execute($context, [
            sprintf('CREATE ROLE %s NOLOGIN', Sql::identifier(Hba::GROUP)),
        ]);
    }

    /**
     * Wem die Datenbank gehört — gefragt und nicht angenommen.
     *
     * `PgDatabaseCreate` legt sie ohne `OWNER`-Zusatz an, Eigentümer wird also
     * die Rolle, unter der der Agent verbunden ist. Das ist heute `root` und
     * wäre als Konstante hier eine Behauptung über die Einrichtung des Servers
     * — genau die Sorte, die dieses Projekt in P5b schon dreimal als
     * Stellvertreter erwischt hat. Eine Datenbank, die von einem Umzug stammt,
     * kann jemand anderem gehören.
     */
    private function owner(Context $context, string $database): string
    {
        $rows = $this->session->query($context, sprintf(
            'SELECT pg_catalog.pg_get_userbyid(datdba) FROM pg_database WHERE datname = %s',
            Sql::text($database),
        ));

        $owner = $rows[0][0] ?? '';

        if ($owner === '') {
            throw AgentException::execFailed(
                'Der Eigentümer der Datenbank liess sich nicht feststellen.',
                ['database' => $database],
            );
        }

        return $owner;
    }

    /**
     * Ein Passwort für ein paar Minuten.
     *
     * Dasselbe Alphabet wie überall in diesem Projekt: Es steht gleich in einer
     * SQL-Anweisung und in einer Passwortdatei, und Zeichen, die in einer der
     * beiden Bedeutung haben, sind hier kein Gewinn an Stärke, sondern eine
     * Fehlerquelle. {@see Credentials} weist alles andere ohnehin ab.
     */
    private static function password(): string
    {
        return substr(strtr(base64_encode(random_bytes(24)), '+/=', 'xyz'), 0, 32);
    }
}
