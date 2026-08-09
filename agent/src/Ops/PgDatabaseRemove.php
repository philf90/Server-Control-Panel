<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Pg\Sql;

/**
 * Eine PostgreSQL-Datenbank entfernen.
 *
 * **Diese Datei ist vor {@see PgDatabaseCreate} entstanden**, aus dem Grund,
 * den `docs/36 §2` festhält: Wer `create` zuerst schreibt, hat danach etwas,
 * das funktioniert, und `remove` wird zur Nacharbeit — die Mechanik, aus der
 * die Zertifikatslücke von `docs/35` entstanden ist. Eine Datenbank legt
 * Kundendaten an, und die liegen ausserhalb von allem, was
 * `subscription.remove` anfasst.
 *
 * ## `WITH (FORCE)`, und das ist kein Übereifer
 *
 * **In PostgreSQL scheitert `DROP DATABASE` an einer offenen Verbindung.**
 * Gemessen am 9. August 2026:
 *
 *     ohne FORCE → ERROR: database "probe" is being accessed by other users
 *     mit  FORCE → DROP DATABASE
 *
 * MariaDB kennt das nicht; dort wirft `DROP DATABASE` das Schema unter jeder
 * laufenden Anwendung weg. Ohne `FORCE` würde hier **jeder Rückbau an einem
 * Kunden scheitern, dessen Anwendung einen Verbindungspool offen hält** — und
 * das ist der Normalfall und nicht die Ausnahme. Ein Rückbau, der davon abhängt,
 * ob gerade jemand verbunden ist, ist keiner.
 *
 * `WITH (FORCE)` gibt es seit PostgreSQL 13; die kleinste Fassung, auf der
 * dieses Panel arbeitet, ist 14 ({@see Server::MIN_VERSION}).
 *
 * ## Der Name wird zweimal geprüft
 *
 * {@see Names::existing()} auf die Form und {@see Names::belongsTo()} darauf,
 * dass er zum genannten Abonnement gehört. Die zweite Prüfung ist die
 * Mandantengrenze im Agenten — ohne sie wäre ein Fehler in der Anwendung ein
 * `DROP DATABASE` auf die Daten eines fremden Kunden, mit ihr ein abgewiesener
 * Aufruf. **Sie ist der Grund, warum das Präfix in `docs/38 §4` ein Präfix
 * geblieben ist**, obwohl es nichts mehr verrät.
 *
 * ## Wiederholbar
 *
 * Eine Datenbank, die es nicht mehr gibt, ist der gewünschte Zustand. Sonst
 * hinge ein fehlgeschlagener Rückbau für immer, weil sein zweiter Versuch an
 * dem scheitert, was der erste schon geschafft hat.
 *
 * **Die Rollen gehen hier nicht mit** — anders als bei
 * {@see DbDatabaseRemove}, wo `DROP USER` im selben Lauf steht. In PostgreSQL
 * verweigert `DROP ROLE`, solange die Rolle irgendwo Rechte hat oder etwas
 * besitzt, und aufgeräumt wird das je Datenbank ({@see PgRoleRemove}). Diese
 * Reihenfolge — erst die Datenbanken, dann die Rollen — kann nur die Anwendung
 * kennen, die den Bestand führt.
 */
final class PgDatabaseRemove implements Op
{
    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'pg.database.remove';
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
        $database = Names::existing($args['name'] ?? null, 'name');

        if (! Names::belongsTo($database, $prefix)) {
            throw AgentException::denied(sprintf(
                'Die Datenbank %s gehört nicht zum Abonnement %s.',
                $database,
                $prefix,
            ));
        }

        // **Die Fassung wird hier nicht verlangt.** `Server::require()` steht
        // vor dem Anlegen und nicht vor dem Entfernen: Wer eine Datenbank auf
        // einem Server loswerden will, dessen Fassung wir inzwischen für zu alt
        // halten, soll das können. Eine Vorbedingung, die den Rückbau
        // blockiert, hinterlässt genau das, was sie verhindern soll.
        $existed = $this->exists($context, $database);

        $context->progress(30, $existed ? 'Datenbank entfernen' : 'Datenbank ist bereits fort');

        $this->session->execute($context, [self::statement($database)]);

        $context->progress(100, 'fertig');

        return ['name' => $database, 'removed' => $existed];
    }

    /**
     * Die Anweisung — als reine Funktion, damit sie sich ohne Server prüfen
     * lässt.
     */
    public static function statement(string $database): string
    {
        return 'DROP DATABASE IF EXISTS '.Sql::identifier($database).' WITH (FORCE)';
    }

    private function exists(Context $context, string $database): bool
    {
        return $this->session->query(
            $context,
            'SELECT 1 FROM pg_database WHERE datname = '.Sql::text($database),
        ) !== [];
    }
}
