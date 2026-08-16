<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Hba;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Pg\Sql;

/**
 * Eine Rolle entfernen — und vorher alles, woran sie hängt.
 *
 * **Das ist die Stelle, an der P5 und P5b am weitesten auseinanderliegen**, und
 * der Unterschied geht in beide Richtungen.
 *
 * In MariaDB entfernt `DROP USER` den Benutzer und **lässt seine Rechte
 * stehen**: Sie liegen in `mysql.db` und überleben ihr Schema. `docs/36 §22.3p`
 * hat genau das auf `cloudsrv24` gefunden — eine Rechtezeile für ein Schema,
 * das es nicht mehr gab. Entsteht der Name wieder, hätte der Zugang sofort
 * alle Rechte darauf.
 *
 * **PostgreSQL macht das Gegenteil und ist damit unbequemer, aber ehrlicher:**
 * `DROP ROLE` **verweigert**, solange die Rolle irgendwo Rechte hat oder etwas
 * besitzt. Gemessen am 9. August 2026:
 *
 *     DROP ROLE kunde2
 *     → ERROR: role "kunde2" cannot be dropped because some objects depend on it
 *       DETAIL: privileges for database bleibt
 *
 * Aufgeräumt wird das mit `DROP OWNED BY`, **und das wirkt je Datenbank.** Eine
 * Rolle, die in drei Datenbanken Rechte hat, braucht drei Läufe — es gibt keine
 * clusterweite Fassung davon.
 *
 * ## Welche Datenbanken, sagt die Anwendung
 *
 * Dieselbe Entscheidung wie in {@see DbDatabaseRemove}, wo die Anwendung sagt,
 * welche Benutzer mitgehen: Der Agent führt keinen Bestand, und eine Operation,
 * die selbst in `pg_shdepend` nachsähe, wäre eine zweite Fassung dieser Regel —
 * und die zweite ist die, die veraltet.
 *
 * **Der Rückfall ist trotzdem sicher.** Nennt die Anwendung eine Datenbank
 * nicht, scheitert `DROP ROLE` mit der Meldung von PostgreSQL, die die fehlende
 * Datenbank **beim Namen nennt**. Das ist der Zustand, in dem eine Rolle
 * stehenbleibt und jemand es erfährt — nicht der, in dem ein Recht auf ein
 * fremdes Schema unbemerkt weiterlebt.
 *
 * ## Wiederholbar, durch Nachfragen
 *
 * PostgreSQL kennt `IF EXISTS` für `DROP ROLE`, aber **nicht für
 * `DROP OWNED BY`** — gemessen, dort ist eine fehlende Rolle ein Fehler.
 * Gefragt wird deshalb vorher. Das ist der Unterschied zu
 * {@see DbUserLock}, wo `ALTER USER IF EXISTS` dieselbe Arbeit übernimmt.
 */
final class PgRoleRemove implements Op
{
    /** Wie viele Datenbanken ein Aufruf aufräumen darf. */
    private const MAX_DATABASES = 64;

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'pg.role.remove';
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
        $role = self::owned($args['name'] ?? null, $prefix, 'name');
        $databases = $this->databases($args['databases'] ?? [], $prefix);

        if (! $this->roleExists($context, $role)) {
            $context->progress(100, 'Rolle ist bereits fort');

            return ['name' => $role, 'removed' => false, 'cleared' => []];
        }

        /*
         * **Nur die Datenbanken, die es noch gibt.** Eine, die der Rückbau
         * gerade geworfen hat, steht weiter im Bestand des Panels, bis die
         * Anwendung ihre Zeile löscht — und ein `DROP OWNED BY` in einer
         * Datenbank, die nicht mehr existiert, bricht den ganzen Lauf ab. Der
         * Zustand „Datenbank fort, Rolle noch da" ist der Normalfall dieses
         * Rückbaus und kein Fehler.
         */
        $present = $this->existing($context, $databases);
        $cleared = [];

        foreach ($present as $index => $database) {
            $context->progress(20 + intdiv(60 * $index, max(1, count($present))), 'Rechte aufräumen: '.$database);
            $this->session->execute($context, ['DROP OWNED BY '.Sql::identifier($role)], $database);
            $cleared[] = $database;
        }

        $context->progress(85, 'Rolle entfernen');
        $this->session->execute($context, [self::statement($role)]);

        $context->progress(95, 'Zugangsregeln aufräumen');
        $dropped = $this->forgetRules($context, $role);

        $context->progress(100, 'fertig');

        return ['name' => $role, 'removed' => true, 'cleared' => $cleared, 'hba_removed' => $dropped];
    }

    /**
     * Die Zeilen dieser Rolle aus dem verwalteten Block in `pg_hba.conf`.
     *
     * **Im selben Vorgang, weil sonst niemand es täte** (`docs/38 §14.4`). Eine
     * Zeile für eine Rolle, die es nicht mehr gibt, ist für PostgreSQL **kein
     * Fehler** (M22): Sie bleibt liegen, `pg_hba_file_rules` schweigt, und
     * nichts meldet es. Entsteht der Name irgendwann wieder — und ein Präfix
     * wird nie zweimal vergeben, ein Suffix schon —, stünde die Erlaubnis
     * schon da, bevor jemand sie erteilt hat.
     *
     * **Ein fehlender Block ist kein Fehlschlag.** Der Fernzugriff ist
     * freiwillig; auf den meisten Servern gibt es diesen Block gar nicht, und
     * ein Rückbau, der daran scheiterte, liesse eine Rolle stehen, weil eine
     * Datei nichts enthielt.
     *
     * @return list<string>
     */
    private function forgetRules(Context $context, string $role): array
    {
        $path = $this->server->hbaFile($context, $this->session);

        $dropped = ManagedBlock::locked($path, function () use ($path, $role): array {
            $content = ManagedBlock::read($path);
            $keep = [];
            $gone = [];

            foreach (ManagedBlock::managed($content) as $line) {
                if (Hba::roleOf($line) === $role) {
                    $gone[] = $line;

                    continue;
                }

                $keep[] = $line;
            }

            if ($gone === []) {
                return [];
            }

            ManagedBlock::put($path, ManagedBlock::render($content, $keep, $path));

            return $gone;
        });

        if ($dropped !== []) {
            $this->session->execute($context, ['SELECT pg_reload_conf()']);

            $context->journal->write('pg_hba.conf: Zugangsregeln einer entfernten Rolle genommen', [
                'role' => $role,
                'rules' => count($dropped),
            ]);
        }

        return $dropped;
    }

    /** Die Anweisung — als reine Funktion, damit sie sich ohne Server prüfen lässt. */
    public static function statement(string $role): string
    {
        return 'DROP ROLE IF EXISTS '.Sql::identifier($role);
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
            $names[] = self::owned($entry, $prefix, 'databases');
        }

        return array_values(array_unique($names));
    }

    /**
     * Ein Name, der die Form trägt **und** zum Abonnement gehört.
     *
     * Beide Prüfungen an einer Stelle: Die zweite ist die Mandantengrenze im
     * Agenten, und sie an drei Stellen einzeln zu wiederholen wäre die Sorte
     * Abschrift, bei der eine irgendwann fehlt.
     */
    private static function owned(mixed $value, string $prefix, string $field): string
    {
        $name = Names::existing($value, $field);

        if (! Names::belongsTo($name, $prefix)) {
            throw AgentException::denied(sprintf('%s gehört nicht zum Abonnement %s.', $name, $prefix));
        }

        return $name;
    }

    private function roleExists(Context $context, string $role): bool
    {
        return $this->session->query(
            $context,
            'SELECT 1 FROM pg_roles WHERE rolname = '.Sql::text($role),
        ) !== [];
    }

    /**
     * @param  list<string>  $databases
     * @return list<string>
     */
    private function existing(Context $context, array $databases): array
    {
        if ($databases === []) {
            return [];
        }

        $known = [];

        foreach ($this->session->query($context, 'SELECT datname FROM pg_database') as $row) {
            $known[(string) ($row[0] ?? '')] = true;
        }

        return array_values(array_filter($databases, static fn (string $name): bool => isset($known[$name])));
    }
}
