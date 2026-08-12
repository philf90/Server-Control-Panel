<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

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
 * **1. Sie arbeitet als die Eigentümerrolle des Abonnements** ({@see Owner}).
 * Zwei Fliegen: Seit PostgreSQL 15 darf `PUBLIC` in `public` nicht mehr
 * schreiben — als Eigentümer des Schemas braucht sie dafür keine eigene
 * Freigabe —, und was sie einspielt, gehört hinterher dem Kunden statt ihr.
 * Hier stand ein `GRANT ALL ON SCHEMA public TO <befristete Rolle>`; es hat das
 * erste gelöst und das zweite verdeckt (`docs/39` Punkt 7). In MariaDB gab es
 * zu beidem kein Gegenstück, weil ein Schema dort die Datenbank *ist* und
 * niemandem gehört.
 *
 * **2. Sie ist Mitglied in {@see Hba::GROUP}**, sonst kommt sie über den Socket
 * gar nicht herein. Der Grund steht dort.
 *
 * **3. Mitgliedschaft allein genügt nicht — es braucht `SET role`.** Ein
 * Mitglied *darf*, was der Gruppe gehört; was es selbst anlegt, gehört aber
 * weiter ihm. Für ein Zurückspielen ist genau das die falsche Hälfte: Die Rolle
 * legt die ganze Datenbank an, und beim Aufräumen ginge sie mit. Erst
 * {@see Owner::sessionRole()} macht die Gruppe zum Eigentümer des Neuen.
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
    public function with(
        Context $context,
        string $prefix,
        string $database,
        callable $work,
        string $kind = Names::KIND_RESTORE,
    ): mixed {
        $role = Names::ephemeral($prefix, $kind);
        $password = self::password();
        $owner = Names::owner($prefix);

        /*
         * **Zwei Mitgliedschaften, und beide sind nötig.** {@see Hba::GROUP}
         * bringt sie über den Socket herein; die Eigentümerrolle des
         * Abonnements macht sie zu jemandem, der in dieser Datenbank arbeiten
         * darf — und, mit der Zeile darunter, dafür sorgt, dass das
         * Eingespielte hinterher dem **Kunden** gehört und nicht ihr.
         *
         * Dass es die Eigentümerrolle gibt, stellt
         * {@see \SrvPanel\Agent\Ops\PgRestore} vor diesem Aufruf sicher.
         */
        $this->session->execute($context, [
            sprintf(
                'CREATE ROLE %s LOGIN PASSWORD %s IN ROLE %s, %s',
                Sql::identifier($role),
                Sql::text($password),
                Sql::identifier(Hba::GROUP),
                Sql::identifier($owner),
            ),
            sprintf(
                'GRANT CONNECT ON DATABASE %s TO %s',
                Sql::identifier($database),
                Sql::identifier($role),
            ),
            Owner::sessionRole($owner, $role, $database, true),
        ]);

        try {
            return $work(new Credentials($role, $password));
        } finally {
            /*
             * **Ohne Abbruch, wenn das Aufräumen scheitert** — wortgleich die
             * Begründung aus P5: Eine Ausnahme aus dem `finally` verschluckte
             * die des Laufs, und dann stünde im Vorgang „Rolle liess sich nicht
             * entfernen" statt „der Dump hat an Zeile 40312 abgebrochen".
             *
             * ## Hier stand ein `REASSIGN OWNED BY`, und der Entwurf hat es
             * überflüssig gemacht
             *
             * Ein `DROP ROLE` scheitert, solange der Rolle etwas gehört oder
             * jemand ihr ein Recht gegeben hat. Der naheliegende Weg ist
             * `DROP OWNED BY` — und **er wirft weg, was gerade eingespielt
             * wurde.** Gemessen am 9. August 2026: Der Lauf meldete Erfolg, und
             * danach gab es die Tabelle nicht.
             *
             * Die Antwort war ein `REASSIGN OWNED BY … TO` an den Eigentümer
             * der *Datenbank* — und genau daran hing der Fehler aus `docs/39`
             * Punkt 7: Die Datenbank gehört dem Panel, also gehörte danach alles
             * `root`, und der Kunde stand vor seinen eigenen Zeilen.
             *
             * Seit die Rolle **als** die Eigentümerrolle des Abonnements
             * arbeitet, entsteht das Problem nicht mehr. Gemessen am 10. August
             * 2026 nach einem vollständigen Zurückspielen:
             *
             *     Tabellen im Besitz der befristeten Rolle → 0
             *     Eigentümer der eingespielten Tabelle     → x…_owner
             *     DROP ROLE ohne REASSIGN                  → geht
             *
             * > **Ein Entwurf, der eine Fallgrube überflüssig macht, ist besser
             * > als einer, der sie umgeht.**
             *
             * `DROP OWNED BY` bleibt und räumt nur noch die Rechte weg, die
             * diesem Lauf gegeben wurden — ohne es verweigert `DROP ROLE` wegen
             * des `GRANT CONNECT`. Was es wegwerfen könnte, besitzt die Rolle
             * nicht mehr.
             */
            try {
                $this->session->execute($context, [
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
