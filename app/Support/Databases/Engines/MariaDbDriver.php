<?php

declare(strict_types=1);

namespace App\Support\Databases\Engines;

use App\Enums\DatabaseEngine;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Subscription;
use RuntimeException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Ops\DbDatabaseCreate;

/**
 * MariaDB — unverändert das, was P5 gebaut hat.
 *
 * **Diese Datei bringt keine neue Regel mit.** Was hier steht, stand bis zum
 * 9. August 2026 wörtlich in `Databases` und ist nur an eine Stelle gezogen, an der auch ein zweites System Platz hat. Wer
 * einen Unterschied zu P5 sucht, findet keinen; wer wissen will, *warum* etwas
 * so ist, findet die Begründung weiter dort.
 *
 * **Der Wirt gehört hier zum Schlüssel.** `'p1001_web'@'localhost'` und
 * `'p1001_web'@'203.0.113.5'` sind in MariaDB zwei Benutzer mit zwei
 * Passwörtern — der Unterschied, den `docs/37 §4` als „die teuerste Zeile" der
 * Übergabetabelle angekündigt hat. Jede Methode hier reicht `host` deshalb mit.
 */
final class MariaDbDriver implements EngineDriver
{
    public function __construct(private readonly Client $agent) {}

    public static function engine(): DatabaseEngine
    {
        return DatabaseEngine::MariaDb;
    }

    /**
     * Der Systembenutzer ist das Präfix.
     *
     * **Und er ist sprechend, mit Absicht.** `p1001_shop` sagt jedem Kunden des
     * Servers, dass es ein Abonnement 1001 gibt — in MariaDB ist das folgenlos,
     * weil `SHOW DATABASES` nur zeigt, worauf man ein Recht hat. In PostgreSQL
     * ist es das nicht, und deshalb hat `docs/38 §4` dort ein anderes Präfix.
     */
    public function prefix(Subscription $subscription): string
    {
        $user = (string) $subscription->system_user;

        if ($user === '') {
            throw new RuntimeException('Diesem Abonnement fehlt der Systembenutzer.');
        }

        return $user;
    }

    public function databaseName(string $prefix, string $label): string
    {
        return Names::database($prefix, $label);
    }

    public function userName(string $prefix, string $label): string
    {
        return Names::user($prefix, $label);
    }

    /**
     * **Die Vorgabe steht hier und nicht im Steuerungscode.** Sie gilt für
     * MariaDB und nur für MariaDB; als `?? $this->collations()[0]` im
     * Controller galt sie für *jedes* System und hat PostgreSQL eine
     * MariaDB-Sortierung als `LC_COLLATE` untergeschoben (`docs/39`, Punkt 3).
     *
     * Erreicht wird sie im Normalfall nie: Das Formular verlangt die Sortierung
     * für MariaDB (`Rule::requiredIf`). Sie steht da für Aufrufer ohne
     * Formular — und trägt denselben Wert, den das Formular als ersten anbietet.
     */
    public function createDatabase(string $prefix, string $label, ?string $collation): array
    {
        $collation ??= DbDatabaseCreate::charsets()['utf8mb4'][0];

        $result = $this->agent->call('db.database.create', [
            'user' => $prefix,
            'suffix' => $label,
            'charset' => 'utf8mb4',
            'collation' => $collation,
        ]);

        return [
            'name' => (string) ($result['name'] ?? $this->databaseName($prefix, $label)),
            'charset' => (string) ($result['charset'] ?? 'utf8mb4'),
            'collation' => (string) ($result['collation'] ?? $collation),
        ];
    }

    public function createUser(string $prefix, string $label, array $databases, string $host, string $password): array
    {
        $result = $this->agent->call('db.user.create', [
            'user' => $prefix,
            'suffix' => $label,
            'host' => $host,
            'password' => $password,
            'databases' => $databases,
        ]);

        return [
            'name' => (string) ($result['name'] ?? $this->userName($prefix, $label)),
            'host' => (string) ($result['host'] ?? $host),
        ];
    }

    /** `$databases` bleibt ungenutzt: MariaDB kennt eine Operation nur fürs Passwort. */
    public function setPassword(string $prefix, DbUser $user, array $databases, string $password): void
    {
        $this->agent->call('db.user.password', [
            'user' => $prefix,
            'name' => $user->name,
            'host' => $user->host,
            'password' => $password,
        ]);
    }

    public function grant(string $prefix, DbUser $user, string $database, bool $granted): void
    {
        $this->agent->call('db.user.grant', [
            'user' => $prefix,
            'name' => $user->name,
            'host' => $user->host,
            'database' => $database,
            'mode' => $granted ? 'grant' : 'revoke',
        ]);
    }

    /** `$databases` bleibt ungenutzt: `DROP USER` hängt in MariaDB an nichts. */
    public function removeUser(string $prefix, DbUser $user, array $databases): void
    {
        $this->agent->call('db.user.remove', [
            'user' => $prefix,
            'name' => $user->name,
            'host' => $user->host,
        ]);
    }

    public function removalTask(): string
    {
        return 'db.database.remove';
    }

    /**
     * **`revoke` ist hier keine Vorsicht, sondern ein Fund.** `DROP DATABASE`
     * nimmt in MariaDB die Rechte auf das Schema nicht mit; ein Zugang, der an
     * einer zweiten Datenbank hängt und darum überlebt, behielt bis zum
     * 8. August 2026 sein `GRANT ALL` auf die entfernte (`docs/36 §22.3p`).
     * PostgreSQL hat diesen Zustand nicht — dort steht das Recht in
     * `pg_database.datacl` und geht mit.
     */
    public function removalPayload(string $prefix, Database $database, array $doomed, array $staying): array
    {
        return [
            'user' => $prefix,
            'name' => $database->name,
            'users' => self::accounts($doomed),
            'revoke' => self::accounts($staying),
        ];
    }

    /**
     * @param  list<DbUser>  $users
     * @return list<array{name: string, host: string}>
     */
    private static function accounts(array $users): array
    {
        return array_map(
            static fn (DbUser $u): array => ['name' => $u->name, 'host' => $u->host],
            $users,
        );
    }
}
