<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Owner;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Pg\Sql;

/**
 * Eine Rolle anlegen und ihr Datenbanken freigeben.
 *
 * **Diese Operation läuft nie über die Warteschlange.** Sie trägt ein Passwort,
 * und ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab —
 * in der Datenbank des Panels, dauerhaft und im Klartext. Dieselbe Regel wie
 * bei `db.user.create`, `tls.certificate.upload` und `dns.credential.store`;
 * `SecretsStayOutOfTheQueueTest` setzt sie durch.
 *
 * Das Passwort wird danach nirgends abgelegt (`docs/36 §4`, Entscheidung 3):
 * Das Panel erzeugt es, schickt es hierher, zeigt es genau einmal an und
 * vergisst es. Was bleibt, ist der Hash, den PostgreSQL selbst führt.
 *
 * ## Was diese Rolle nicht ist
 *
 * `LOGIN` und sonst nichts. **Kein `CREATEDB`** — sonst legte ein Kunde
 * Datenbanken an, die im Bestand des Panels fehlen und deren Absperrung nie
 * gelaufen ist. **Kein `CREATEROLE`**, **kein `SUPERUSER`**, **kein
 * `BYPASSRLS`**, kein `WITH ADMIN OPTION` und kein `WITH GRANT OPTION`.
 * `PgGrantTest` liest die erzeugte Anweisung als Text und besteht darauf.
 *
 * ## Mitglied ist sie in genau einer Rolle: der ihres eigenen Abonnements
 *
 * Hier stand, sie werde „**kein Mitglied** einer anderen", und das war als
 * Zusage gemeint. Sie hat einen Fehler gedeckt: In PostgreSQL gehört eine
 * Tabelle dem, der sie angelegt hat, und ohne eine gemeinsame Rolle steht der
 * zweite Zugang eines Abonnements vor den Daten des ersten
 * (`permission denied for table`, gemessen am 10. August 2026). Die Zusage, die
 * gilt, ist enger und wahr: **Mitglied in einer Rolle, die dasselbe Präfix
 * trägt** — {@see Owner}, `NOLOGIN`, ohne Passwort, ohne `ADMIN OPTION`.
 *
 * ## Und sie besitzt ihre Datenbank nicht
 *
 * Die gehört dem Panel ({@see PgDatabaseCreate}), und das ist Entscheidung 2
 * aus `docs/38 §21`: Ein Eigentümer darf `GRANT CONNECT … TO PUBLIC` und macht
 * die Absperrung damit rückgängig. Das Schema darin gehört der Eigentümerrolle
 * — sie kann damit nur die eigene Datenbank freigeben, in die ohnehin niemand
 * hineinkommt. Was die Rolle sonst bekommt, vergibt {@see PgRoleGrant}.
 */
final class PgRoleCreate implements Op
{
    /** Wie viele Datenbanken ein Aufruf freigeben darf. */
    private const MAX_DATABASES = 32;

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
        private readonly Owner $owner = new Owner,
    ) {}

    public static function name(): string
    {
        return 'pg.role.create';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $this->server->require($context, $this->session);

        $prefix = Names::prefix($args['prefix'] ?? null);
        $role = Names::role($prefix, $args['suffix'] ?? null);
        $password = self::password($args['password'] ?? null);
        $databases = $this->databases($args['databases'] ?? [], $prefix);

        $context->progress(30, 'Rolle anlegen');

        /*
         * **Anlegen oder das Passwort setzen — dieselbe Wirkung, zwei
         * Anweisungen.** `CREATE ROLE` kennt kein `IF NOT EXISTS`. Ein zweiter
         * Lauf nach einem Abbruch soll trotzdem durchkommen, und der gewünschte
         * Zustand ist beide Male derselbe: Diese Rolle gibt es, und sie hat
         * dieses Passwort.
         */
        $existed = $this->exists($context, $role);

        $this->session->execute($context, [self::statement($role, $password, $existed)]);

        /*
         * **Die Mitgliedschaft hängt am Zugang und nicht an einer Datenbank.**
         * PostgreSQL kennt keine Mitgliedschaft mit Geltungsbereich; sie gilt
         * clusterweit oder gar nicht. Sie wird deshalb hier vergeben und erst
         * mit der Rolle wieder entfernt — was je Datenbank wechselt, ist die
         * Sitzungsrolle in {@see PgRoleGrant}.
         *
         * Und sie steht **vor** der Schleife: Ein Zugang ohne eine einzige
         * freigegebene Datenbank ist erlaubt, und er soll die Rolle trotzdem
         * schon haben — sonst hinge die spätere Freigabe daran, dass jemand
         * hier noch einmal vorbeikommt.
         */
        $owner = $this->owner->ensure($context, $prefix);
        $this->session->execute($context, [Owner::membership($owner, $role)]);

        foreach ($databases as $index => $database) {
            $context->progress(50 + intdiv(40 * $index, max(1, count($databases))), 'freigeben: '.$database);

            /*
             * **`CONNECT` zuerst, dann übereignen.** {@see Owner::adopt()}
             * fragt den Katalog, welche Zugänge dieser Datenbank es gibt — wer
             * nicht hineindarf, kommt nicht vor. Stünde die Übereignung vorher,
             * fehlte in ihrer Liste ausgerechnet die Rolle, die dieser Aufruf
             * gerade anlegt.
             */
            $this->session->execute($context, [
                PgRoleGrant::databaseStatement($role, $database, true),
                Owner::sessionRole($owner, $role, $database, true),
            ]);
            $this->owner->adopt($context, $prefix, $database);
            $this->session->execute($context, PgRoleGrant::schemaStatements($role, true), $database);
        }

        $context->progress(100, 'fertig');

        return ['name' => $role, 'created' => ! $existed, 'databases' => $databases, 'owner' => $owner];
    }

    /**
     * Die Anweisung — als reine Funktion, damit sie sich ohne Server prüfen
     * lässt.
     *
     * **`NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT` stehen ausdrücklich da**,
     * obwohl PostgreSQL sie ohnehin nicht vergibt. Eine Zusage, die von einer
     * Vorgabe abhängt, ist keine — dieselbe Begründung wie beim `REVOKE ALL ON
     * SCHEMA public` in `Pg\Shielding`, das ab PG 15 nichts mehr ändert und
     * trotzdem dasteht.
     */
    public static function statement(string $role, string $password, bool $existed): string
    {
        $name = Sql::identifier($role);
        $secret = Sql::text($password);

        return $existed
            ? sprintf('ALTER ROLE %s WITH LOGIN PASSWORD %s', $name, $secret)
            : sprintf(
                'CREATE ROLE %s WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION '
                .'NOBYPASSRLS PASSWORD %s',
                $name,
                $secret,
            );
    }

    /**
     * Das Passwort, geprüft.
     *
     * Gegen eine Positivliste und nicht gegen eine Liste der Sonderzeichen —
     * dieselbe Form wie in `Db\Credentials`, und aus demselben Grund: Die
     * Passwörter dieses Projekts entstehen ohnehin aus genau diesem Alphabet
     * (`docs/36 §4`), und was hier ankommt, geht in eine SQL-Anweisung.
     */
    private static function password(mixed $value): string
    {
        $password = Guard::string($value, 'password');

        if (preg_match('/^[A-Za-z0-9]{8,128}$/D', $password) !== 1) {
            throw AgentException::badRequest('Dieses Passwort wird nicht gesetzt.');
        }

        return $password;
    }

    /**
     * @return list<string>
     */
    private function databases(mixed $value, string $prefix): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('databases muss eine Liste sein.');
        }

        if (count($value) > self::MAX_DATABASES) {
            throw AgentException::badRequest(sprintf(
                'Zu viele Datenbanken in einem Aufruf: %d, erlaubt sind %d.',
                count($value),
                self::MAX_DATABASES,
            ));
        }

        $names = [];

        foreach ($value as $entry) {
            $name = Names::existing($entry, 'databases');

            if (! Names::belongsTo($name, $prefix)) {
                throw AgentException::denied(sprintf(
                    'Die Datenbank %s gehört nicht zum Abonnement %s.',
                    $name,
                    $prefix,
                ));
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    private function exists(Context $context, string $role): bool
    {
        return $this->session->query(
            $context,
            'SELECT 1 FROM pg_roles WHERE rolname = '.Sql::text($role),
        ) !== [];
    }
}
