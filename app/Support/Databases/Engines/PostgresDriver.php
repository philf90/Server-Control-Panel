<?php

declare(strict_types=1);

namespace App\Support\Databases\Engines;

use App\Enums\DatabaseEngine;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Databases\Dumps;
use App\Support\Tenancy\Tenancy;
use RuntimeException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Pg\Names;

/**
 * PostgreSQL — dieselben fünf Handgriffe, drei andere Antworten.
 *
 * Die Unterschiede stehen in {@see EngineDriver}; hier steht, was jeder
 * einzelne kostet.
 *
 * ## Das Präfix kommt aus `system_users` und nicht aus dem Namen
 *
 * In MariaDB ist das Präfix der Systembenutzer — `p1001`. Hier ist es
 * `x7f3a91c2…`, nichtssagend und gewürfelt (`docs/38 §4`), weil in PostgreSQL
 * **die Namen aller Datenbanken für jeden lesbar sind** (gemessen, §2). Ein
 * sprechendes Präfix wäre die Kundenliste des Servers, für jeden Kunden.
 *
 * **Nachgeschlagen wird über die Nummer und nicht über den Namen.** `docs/35`
 * hat die Nummer zur bleibenden Kennung gemacht; der Name eines Abonnements
 * darf sich ändern, und ein Nachschlagen daran ergäbe irgendwann ein anderes
 * Präfix — also fremde Datenbanken.
 *
 * ## Sortierung: `encoding` und `locale` statt `charset` und `collation`
 *
 * Und sie steht in denselben zwei Spalten. Das ist Absicht und keine
 * Sparsamkeit: Die Fläche im Panel ist eine (`docs/38 §8`), und zwei weitere
 * Spalten, die je nach `engine` leer sind, wären zwei Spalten, die man beim
 * Lesen jedes Mal übersetzt.
 *
 * ## Ein Zugang ist eine Rolle, und Rollen sind clusterweit
 *
 * `host` kommt in keinem Aufruf vor. Die Zeile im Panel trägt ihn trotzdem —
 * mit `localhost`, weil das Datenmodell eines ist und die Wirtsbeschränkung in
 * PostgreSQL nicht am Zugang hängt, sondern in `pg_hba.conf` (`docs/38 §14`,
 * Schritt 10).
 */
final class PostgresDriver implements EngineDriver
{
    public function __construct(
        private readonly Client $agent,
        private readonly Tenancy $tenancy,
    ) {}

    public static function engine(): DatabaseEngine
    {
        return DatabaseEngine::Postgres;
    }

    /**
     * Das gewürfelte Präfix dieses Abonnements.
     *
     * **Ohne Mandantenklammer, und ausdrücklich.** `system_users` ist die
     * Reservierungsliste des Servers und gehört keinem Kunden; sie ist von der
     * Klammer gar nicht erfasst. Das Abonnement in der Hand ist bereits durch
     * sie gekommen — dieselbe Begründung wie in `Databases::subscriptionOf()`.
     *
     * Fehlt der Eintrag, ist das kein stiller Fall: Er entsteht in
     * `Lifecycle::claim()` zusammen mit dem Systembenutzer. Fehlt er trotzdem,
     * stammt das Abonnement aus der Zeit vor P5b und die Migration hat ihn
     * nachgetragen — oder etwas ist kaputt, und dann soll es hier stehen und
     * nicht in einer Meldung des Agenten über einen leeren Namen.
     */
    public function prefix(Subscription $subscription): string
    {
        return self::prefixOf($this->tenancy, $subscription);
    }

    /**
     * Dasselbe ohne Treiber — für {@see Dumps}.
     *
     * **Sie ist `static`, damit es die Frage nur einmal gibt.** `Dumps` braucht
     * das Präfix für den Auftrag an `pg.dump.create` und hat keinen Treiber:
     * Welche Aufgabe eine Sicherung auslöst, gehört zu dem, was mit ihr
     * geschieht, und nicht dazu, wie eine Datenbank angelegt wird. Die Abfrage
     * dort ein zweites Mal zu schreiben wäre die zweite Fassung, und die zweite
     * ist die, die veraltet — genau der Grund, aus dem Schritt 6 sie zuerst
     * offengelassen und im Code benannt hat.
     */
    public static function prefixOf(Tenancy $tenancy, Subscription $subscription): string
    {
        $user = (string) $subscription->system_user;
        $number = (int) ltrim($user, 'p');

        $prefix = $tenancy->withoutRestriction(
            static fn (): mixed => SystemUser::query()->where('number', $number)->value('db_prefix')
        );

        if (! is_string($prefix) || $prefix === '') {
            throw new RuntimeException(sprintf(
                'Zum Systembenutzer %s gibt es kein Datenbankpräfix. Ohne das lässt sich keine '.
                'PostgreSQL-Datenbank anlegen, die zum Abonnement gehört.',
                $user === '' ? '(keiner)' : $user,
            ));
        }

        return $prefix;
    }

    public function databaseName(string $prefix, string $label): string
    {
        return Names::database($prefix, $label);
    }

    public function userName(string $prefix, string $label): string
    {
        return Names::role($prefix, $label);
    }

    /**
     * **Die Sortierung wird durchgereicht und nicht übersetzt.** Was die
     * Oberfläche anbietet, ist je System eine eigene Liste — eine Abbildung
     * `utf8mb4_unicode_ci` → `C.UTF-8` wäre eine Behauptung über zwei
     * Sortierordnungen, die einander nicht entsprechen.
     */
    /**
     * **`$collation` wird hier nicht benutzt, und das ist der ganze Punkt.**
     *
     * In PostgreSQL entstehen Zeichensatz und Sortierung beim Anlegen und
     * werden von diesem Panel nicht gewählt (`docs/38 §5`); das Formular zeigt
     * das Feld gar nicht. Was hier ankam, war deshalb nie eine Wahl des Kunden
     * — es war der Ersatzwert des Steuerungscodes, und der lautete
     * `utf8mb4_unicode_ci`. Jedes Anlegen scheiterte an
     * *invalid LC_COLLATE locale name*, gefunden am 10. August 2026 auf
     * `cloudsrv24` (`docs/39`, Punkt 3).
     *
     * **Ohne `locale` setzt der Agent sein eigenes Gebietsschema** — die
     * Entscheidung gehört dorthin, wo `CREATE DATABASE` geschrieben wird, und
     * nicht in einen Ersatzwert zwei Schichten darüber.
     */
    public function createDatabase(string $prefix, string $label, ?string $collation): array
    {
        $result = $this->agent->call('pg.database.create', [
            'prefix' => $prefix,
            'suffix' => $label,
            'encoding' => 'UTF8',
        ]);

        return [
            'name' => (string) ($result['name'] ?? $this->databaseName($prefix, $label)),
            'charset' => (string) ($result['encoding'] ?? 'UTF8'),
            'collation' => (string) ($result['locale'] ?? $collation),
        ];
    }

    public function createUser(string $prefix, string $label, array $databases, string $host, string $password): array
    {
        $result = $this->agent->call('pg.role.create', [
            'prefix' => $prefix,
            'suffix' => $label,
            'password' => $password,
            'databases' => $databases,
        ]);

        // Der Wirt kommt zurück, wie er hineinging: Die Zeile im Panel trägt
        // ihn, der Cluster nicht.
        return [
            'name' => (string) ($result['name'] ?? $this->userName($prefix, $label)),
            'host' => $host,
        ];
    }

    /**
     * **Dieselbe Operation wie zum Anlegen, und das ist kein Behelf.**
     * `pg.role.create` ist wiederholbar: `CREATE ROLE` kennt kein
     * `IF NOT EXISTS`, also setzt sie an einer vorhandenen Rolle das Passwort
     * mit `ALTER ROLE` — der gewünschte Zustand ist beide Male derselbe.
     * Eine zweite Operation dafür wäre eine zweite Fassung derselben Regel.
     *
     * **Deshalb müssen die Datenbanken mit.** Der Aufruf gibt frei, was in der
     * Liste steht; ohne sie verlöre der Zugang beim Zurücksetzen des Passworts
     * seine Freigaben — nicht, weil etwas entzogen würde, sondern weil nichts
     * mehr bestätigt wird. Sie kommen aus dem Bestand des Panels und sind
     * damit dieselben, die der Kunde gerade sieht.
     */
    public function setPassword(string $prefix, DbUser $user, array $databases, string $password): void
    {
        $this->agent->call('pg.role.create', [
            'prefix' => $prefix,
            'suffix' => (string) $user->label,
            'password' => $password,
            'databases' => $databases,
        ]);
    }

    public function grant(string $prefix, DbUser $user, string $database, bool $granted): void
    {
        $this->agent->call('pg.role.grant', [
            'prefix' => $prefix,
            'name' => $user->name,
            'database' => $database,
            'mode' => $granted ? 'grant' : 'revoke',
        ]);
    }

    public function removeUser(string $prefix, DbUser $user, array $databases): void
    {
        $this->agent->call('pg.role.remove', [
            'prefix' => $prefix,
            'name' => $user->name,
            'databases' => $databases,
        ]);
    }

    public function consoleOperation(string $handle): string
    {
        return 'pg.console.'.$handle;
    }

    /**
     * Immer `public`.
     *
     * Das Panel legt keine weiteren Schemata an, und der Kunde bekommt kein
     * `CREATE SCHEMA` (`docs/38 §5`). Ein Schema, das ein mitgebrachter Dump
     * angelegt hat, ist damit über die Konsole nicht erreichbar — eine benannte
     * Lücke und keine Nachlässigkeit: Sie zu schliessen hiesse, das Feld aus
     * der Anfrage zu übernehmen, und dann stünde ein Bezeichner in der Nutzlast,
     * den niemand nachgeschlagen hat.
     */
    public function consoleSchema(Database $database): string
    {
        return 'public';
    }

    public function removalTask(): string
    {
        return 'pg.database.remove';
    }

    /**
     * **Nur eine Liste, und das ist gemessen.**
     *
     * MariaDB bekommt zwei — `users` gehen mit, `revoke` bleiben und verlieren
     * ihr Recht —, weil dort eine Rechtezeile in `mysql.db` ihr Schema
     * überlebt. In PostgreSQL liegt dasselbe Recht in `pg_database.datacl` und
     * geht mit der Datenbank; am 9. August 2026 nachgesehen, stand die
     * bleibende Rolle danach nur noch an der Datenbank, die es noch gibt.
     *
     * Eine `revoke`-Liste wäre hier Arbeit für einen Zustand, den es nicht gibt
     * — und sie sähe aus wie eine Zusage.
     */
    public function removalPayload(string $prefix, Database $database, array $doomed, array $staying): array
    {
        return [
            'prefix' => $prefix,
            'name' => $database->name,
            'roles' => array_map(static fn (DbUser $u): string => $u->name, $doomed),
        ];
    }
}
