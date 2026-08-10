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
 * ## Die Rollen gehen mit — **nach** der Datenbank
 *
 * Hier stand, sie gingen *nicht* mit, weil `DROP ROLE` verweigert, solange eine
 * Rolle irgendwo etwas besitzt. Das stimmt — und für die Rollen, die mit dieser
 * Datenbank verschwinden, ist es gegenstandslos. **Gemessen am 9. August 2026:**
 *
 *     vor DROP DATABASE   pg_shdepend: dbid 0 → 1 Zeile, dbid 24581 → 2 Zeilen
 *     nach DROP DATABASE  pg_shdepend: 0 Zeilen
 *     DROP ROLE ohne DROP OWNED BY → geht
 *
 * `DROP DATABASE` nimmt **alle** Abhängigkeiten mit, die in ihr wurzeln: die
 * Eigentümerzeilen der Objekte darin und den Eintrag auf die Datenbank selbst.
 * Danach ist eine Rolle, die nur an ihr hing, frei.
 *
 * **Und das ist zugleich der Unterschied zu MariaDB, der eine zweite Liste
 * überflüssig macht.** Dort überlebt eine Rechtezeile in `mysql.db` ihr Schema
 * — `docs/36 §22.3p` hat das auf `cloudsrv24` gefunden —, weshalb
 * {@see DbDatabaseRemove} neben `users` auch `revoke` bekommt: die Zugänge, die
 * bleiben und ihr Recht verlieren müssen. In PostgreSQL liegt dasselbe Recht in
 * `pg_database.datacl` und geht mit der Datenbank. Gemessen: Nach dem Werfen
 * stand die bleibende Rolle nur noch an der Datenbank, die es noch gibt.
 *
 * **Ein Vorgang statt zweier, und das ist der Grund.** Rollen zuerst
 * unmittelbar zu entfernen und die Datenbank danach einzureihen hiesse: Bricht
 * das `DROP DATABASE` ab, sind die Zugänge fort und die Daten da — der Kunde
 * sieht seine Datenbank und kommt nicht mehr hinein. So bleibt bei einem
 * Abbruch der Zustand von vorher.
 *
 * **`DROP OWNED BY` bleibt trotzdem, wo es hingehört:** in
 * {@see PgRoleRemove}, für den anderen Fall — eine Rolle, die entfernt wird,
 * während ihre übrigen Datenbanken bestehen bleiben. Dort verweigert `DROP
 * ROLE` tatsächlich, und dort ist es gemessen.
 */
final class PgDatabaseRemove implements Op
{
    /** Wie viele Rollen ein Aufruf mitnehmen darf. */
    private const MAX_ROLES = 64;

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
        $roles = $this->roles($args['roles'] ?? [], $prefix);

        $existed = $this->exists($context, $database);

        $context->progress(30, $existed ? 'Datenbank entfernen' : 'Datenbank ist bereits fort');

        $this->session->execute($context, [self::statement($database)]);

        /*
         * **Erst danach.** Vorher verweigerte `DROP ROLE` — die Rolle besitzt
         * ja noch, was in der Datenbank liegt. Danach ist sie frei, und zwar
         * ohne `DROP OWNED BY` (gemessen, siehe Klassenkommentar).
         *
         * `IF EXISTS`, weil ein zweiter Anlauf nach einem abgebrochenen Lauf
         * hier ankommt und die Rolle dann schon fort ist.
         */
        $removed = [];

        foreach ($roles as $index => $role) {
            $context->progress(50 + intdiv(40 * $index, max(1, count($roles))), 'Rolle entfernen: '.$role);
            $this->session->execute($context, [PgRoleRemove::statement($role)]);
            $removed[] = $role;
        }

        $context->progress(95, 'Eigentümerrolle prüfen');
        $owner = $this->removeOwner($context, $prefix);

        $context->progress(100, 'fertig');

        return [
            'name' => $database,
            'removed' => $existed,
            'roles_removed' => $removed,
            'owner_removed' => $owner,
        ];
    }

    /**
     * Und die Eigentümerrolle geht mit der **letzten** Datenbank.
     *
     * **Ohne diese Zeile gäbe es hier genau die Lücke, die `docs/35` teuer
     * gelernt hat:** etwas, das sich anlegen, aber nirgends löschen lässt. Sie
     * entsteht in {@see PgDatabaseCreate}, überlebt jeden Rückbau und bliebe
     * für immer in `pg_roles` stehen — sichtbar für jeden, der den Katalog
     * lesen darf, und damit ein Grabstein, der ein Abonnement nennt, das es
     * nicht mehr gibt.
     *
     * **Gefragt wird der Katalog und nicht die Anwendung.** Das ist die
     * Ausnahme von der Regel eine Methode weiter unten, und der Grund ist die
     * Richtung des Fehlschlags: Eine Anwendung, die sich um eine Datenbank
     * verzählt, liesse hier `DROP ROLE` auf eine Rolle laufen, der noch ein
     * Schema gehört — PostgreSQL verweigert das, und der ganze Rückbau wäre
     * rot. Der Katalog weiss es sicher.
     *
     * **`DROP OWNED BY` steht nicht dabei, und das ist gemessen.**
     * `DROP DATABASE` nimmt alle Abhängigkeiten mit, die in ihr wurzeln — auch
     * das Eigentum am Schema `public`. Ist die letzte Datenbank fort, hängt an
     * der Rolle nichts mehr (siehe Klassenkopf, 9. August 2026).
     *
     * @return string|null Der Name, wenn sie ging — sonst `null`
     */
    private function removeOwner(Context $context, string $prefix): ?string
    {
        $remaining = $this->session->query($context, sprintf(
            'SELECT 1 FROM pg_database WHERE starts_with(datname, %s)',
            Sql::text($prefix.'_'),
        ));

        if ($remaining !== []) {
            return null;
        }

        $owner = Names::owner($prefix);

        $this->session->execute($context, [PgRoleRemove::statement($owner)]);

        return $owner;
    }

    /**
     * Die Rollen, die mit dieser Datenbank gehen.
     *
     * **Welche das sind, sagt die Anwendung** — dieselbe Entscheidung wie in
     * {@see DbDatabaseRemove}: Der Agent führt keinen Bestand, und eine
     * Operation, die selbst in `pg_shdepend` nachsähe, wäre eine zweite Fassung
     * dieser Regel.
     *
     * Jeder Name wird gegen das Präfix gehalten. Ohne diese Prüfung wäre ein
     * Fehler in der Anwendung ein `DROP ROLE` auf den Zugang eines fremden
     * Kunden.
     *
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
            $name = Names::existing($entry, 'roles');

            if (! Names::belongsTo($name, $prefix)) {
                throw AgentException::denied(sprintf('Die Rolle %s gehört nicht zum Abonnement %s.', $name, $prefix));
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
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
