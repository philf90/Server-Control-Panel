<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Pg\Sql;

/**
 * Die Sperre eines Abonnements erreicht seine Rollen.
 *
 * Wortgleich die Begründung aus `docs/36 §6`: Eine Datenbank ist ein Zugang.
 * Ein gesperrtes Abonnement, dessen Datenbank jede Anwendung weiterbedient, die
 * die Zugangsdaten hat, ist keine Sperre, sondern eine abgeschaltete Webseite.
 *
 * `NOLOGIN` ist die Entsprechung zu `ACCOUNT LOCK` und ist wie dieses die
 * vollständige Umkehrung: Schema, Tabellen und Rechte bleiben unberührt, nur
 * die Anmeldung fällt weg. Ein `REVOKE` wäre die Alternative gewesen und die
 * schlechtere — es müsste sich merken, was es weggenommen hat, um es
 * zurückgeben zu können, und das wäre ein zweiter Zustand neben `status`.
 *
 * ## Wiederholbar heisst hier: vorher fragen
 *
 * **PostgreSQL kennt kein `ALTER ROLE IF EXISTS`** — gemessen am 9. August 2026
 * ist eine fehlende Rolle dort ein Fehler. `docs/36 §6` löst dasselbe Problem
 * mit `ALTER USER IF EXISTS` und schreibt dazu den Satz, auf den es ankommt:
 * *Ein Benutzer, den jemand von Hand entfernt hat, würde den ganzen Aufruf zum
 * Scheitern bringen — und damit bliebe eine Sperre aus, weil ein anderer Zugang
 * fehlt. Die Sperre ist wichtiger als die Vollständigkeit der Buchführung.*
 *
 * Der Satz gilt hier genauso, nur muss ihn dieser Code selbst einlösen: Gefragt
 * wird, welche der genannten Rollen es gibt, und gesperrt wird, was da ist. Was
 * fehlt, steht in der Antwort — **gemeldet und nicht verschwiegen**, denn eine
 * Sperre, die eine Rolle übergeht, ohne es zu sagen, sieht aus wie eine
 * vollständige.
 *
 * ## Was diese Sperre nicht kann
 *
 * **Sie beendet keine bestehende Sitzung.** Eine Anwendung mit offenem
 * Verbindungspool arbeitet weiter, bis sie neu verbindet. `ACCOUNT LOCK` in
 * MariaDB tut das auch nicht — P5 hat es nur nirgends aufgeschrieben
 * (`docs/38 §11`). Wer das schliessen will, braucht `pg_terminate_backend`, und
 * das ist eine Entscheidung mit Folgen: Ein Kunde sähe dann mitten in einer
 * Transaktion einen Abbruch.
 */
final class PgRoleLock implements Op
{
    /** Wie viele Rollen ein Aufruf umschalten darf. */
    private const MAX_ROLES = 64;

    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'pg.role.lock';
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
        $prefix = Names::prefix($args['prefix'] ?? null);
        $locked = Guard::enum($args['mode'] ?? null, ['lock', 'unlock'], 'mode') === 'lock';
        $roles = $this->roles($args['roles'] ?? [], $prefix);

        $context->progress(30, $locked ? 'Zugänge sperren' : 'Zugänge freigeben');

        $present = $this->existing($context, $roles);
        $missing = array_values(array_diff($roles, $present));

        $this->session->execute($context, array_map(
            static fn (string $role): string => self::statement($role, $locked),
            $present,
        ));

        $context->progress(100, 'fertig');

        return ['locked' => $locked, 'roles' => $present, 'missing' => $missing];
    }

    /** Die Anweisung — als reine Funktion, damit sie sich ohne Server prüfen lässt. */
    public static function statement(string $role, bool $locked): string
    {
        return 'ALTER ROLE '.Sql::identifier($role).($locked ? ' NOLOGIN' : ' LOGIN');
    }

    /**
     * @return list<string>
     */
    private function roles(mixed $value, string $prefix): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('roles muss eine Liste sein.');
        }

        if (count($value) > self::MAX_ROLES) {
            throw AgentException::badRequest(sprintf(
                'Zu viele Rollen in einem Aufruf: %d, erlaubt sind %d.',
                count($value),
                self::MAX_ROLES,
            ));
        }

        $names = [];

        foreach ($value as $entry) {
            $role = Names::existing($entry, 'roles');

            if (! Names::belongsTo($role, $prefix)) {
                throw AgentException::denied(sprintf(
                    'Die Rolle %s gehört nicht zum Abonnement %s.',
                    $role,
                    $prefix,
                ));
            }

            $names[] = $role;
        }

        return array_values(array_unique($names));
    }

    /**
     * Welche der genannten Rollen es gibt.
     *
     * **In einer Abfrage und nicht in einer je Rolle.** Bei einem Abonnement mit
     * zwölf Zugängen wären das zwölf Prozessgründungen für eine Frage, die eine
     * Zeile beantwortet — dieselbe Überlegung wie bei `db.usage` in
     * `docs/36 §9`.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function existing(Context $context, array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $known = [];

        foreach ($this->session->query($context, 'SELECT rolname FROM pg_roles') as $row) {
            $known[(string) ($row[0] ?? '')] = true;
        }

        return array_values(array_filter($roles, static fn (string $role): bool => isset($known[$role])));
    }
}
