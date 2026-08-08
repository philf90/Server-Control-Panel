<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Server;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Einen Datenbankbenutzer anlegen und ihm Datenbanken freigeben.
 *
 * **Diese Operation läuft nie über die Warteschlange.** Sie trägt ein Passwort,
 * und ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab
 * — also in der Datenbank des Panels, dauerhaft und im Klartext. Dieselbe Regel
 * gilt seit P4 für `tls.certificate.upload` (privater Schlüssel) und
 * `dns.credential.store` (DNS-Token); sie steht in
 * `AgentOperationReachTest::WITHOUT_LIFECYCLE` und wird seit P5 von einem
 * Wächter durchgesetzt statt von einer Gewohnheit.
 *
 * Die Anwendung ruft deshalb unmittelbar auf (`Client::call`) und schreibt ihre
 * Zeile danach selbst — genau wie `CertificateRecord` es nach dem Hochladen
 * eines Zertifikats tut.
 *
 * **Das Passwort wird danach nirgends abgelegt** (`docs/36 §4`, Entscheidung 3
 * des Betreibers): Das Panel erzeugt es, schickt es hierher, zeigt es genau
 * einmal an und vergisst es. Der Agent behält es ebenfalls nicht — was bleibt,
 * ist der Hash in `mysql.global_priv`, den MariaDB selbst führt.
 *
 * ## Rechte begrenzt
 *
 * Der Plan verlangt es (`§9 P5`), und es besteht aus zwei Hälften:
 *
 * 1. **Nur auf Schemaebene.** `ALL PRIVILEGES ON` *eine Datenbank* enthält kein
 *    `SUPER`, `FILE`, `PROCESS`, `SHUTDOWN`, `RELOAD` und `CREATE USER` — die
 *    sind global und stehen in `*.*`, das hier nie vorkommt.
 * 2. **Kein `WITH GRANT OPTION`.** Ein Kunde, der Rechte weiterreichen darf,
 *    kann sich selbst welche geben.
 *
 * `DbIsolationTest` liest die erzeugten Anweisungen als Text und besteht auf
 * beidem. Warum als Text: Dieser Container hat keine MariaDB, und der Schutz
 * ist eine Eigenschaft der erzeugten Zeichenkette — dieselbe Prüfart wie in
 * `SiteTemplateTest` und `PhpIsolationTest`.
 *
 * Und die Falle, um die es in {@see Sql::grantTarget()} geht, gilt hier: In
 * `GRANT … ON <db>.*` ist `<db>` ein **Muster**. Es wird nie auf eines
 * berechtigt, und der Unterstrich wird maskiert.
 */
final class DbUserCreate implements Op
{
    /** Wie viele Datenbanken ein Aufruf freigeben darf. */
    private const MAX_DATABASES = 32;

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'db.user.create';
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
        $prefix = Names::prefix($args['user'] ?? null);
        $account = Names::user($prefix, $args['suffix'] ?? null);
        $host = Names::host($args['host'] ?? 'localhost');
        $password = self::password($args['password'] ?? null);
        $databases = self::databases($args['databases'] ?? [], $prefix);

        $context->progress(20, 'Datenbankserver prüfen');
        $this->server->require($context, $this->session);

        $context->progress(60, 'Benutzer anlegen');

        $this->session->execute($context, self::statements($account, $host, $password, $databases));

        $context->progress(100, 'fertig');

        // **Das Passwort steht nicht im Ergebnis.** Es ginge sonst als
        // `operations.result` zurück in die Datenbank des Panels und wäre damit
        // genau dort, wo es nach Entscheidung 3 nicht sein soll — nur über den
        // Rückweg statt über den Hinweg.
        return [
            'name' => $account,
            'host' => $host,
            'databases' => $databases,
        ];
    }

    /**
     * Die Anweisungen — als reine Funktion, damit sie sich als Text prüfen lässt.
     *
     * **Dieser Container hat keine MariaDB** (CLAUDE.md: „kein nginx, kein
     * PHP-FPM, kein Agent, kein systemd"). Der Schutz muss deshalb eine
     * Eigenschaft der erzeugten Zeichenkette sein und nicht eine des laufenden
     * Systems — genau wie bei den nginx-Vorlagen, die `SiteTemplateTest` und
     * `PhpIsolationTest` als Text lesen. Was `DbIsolationTest` hier
     * nachrechnet: kein `*.*`, kein `WITH GRANT OPTION`, und der Unterstrich im
     * Datenbanknamen maskiert.
     *
     * **`CREATE … IF NOT EXISTS` und danach `ALTER`.** Der Lauf ist damit
     * wiederholbar und setzt in beiden Fällen dasselbe Passwort. Ohne den
     * `ALTER` bekäme ein zweiter Versuch nach einem abgebrochenen Vorgang einen
     * Benutzer mit dem *alten* Passwort — und der Kunde hätte das neue in der
     * Hand.
     *
     * @param  list<string>  $databases
     * @return list<string>
     */
    public static function statements(string $account, string $host, string $password, array $databases): array
    {
        $statements = [
            sprintf(
                'CREATE USER IF NOT EXISTS %s IDENTIFIED BY %s',
                Sql::account($account, $host),
                Sql::text($password),
            ),
            sprintf(
                'ALTER USER %s IDENTIFIED BY %s',
                Sql::account($account, $host),
                Sql::text($password),
            ),
        ];

        foreach ($databases as $database) {
            /*
             * **`ALL PRIVILEGES` auf Schemaebene, und sonst nichts.** Das ist
             * die eine Hälfte von „Rechte begrenzt" aus dem Plan: `SUPER`,
             * `FILE`, `PROCESS`, `SHUTDOWN`, `RELOAD` und `CREATE USER` sind
             * globale Rechte und stehen in `*.*`, das hier nie vorkommt.
             *
             * Die andere Hälfte ist das, was **nicht** dasteht: kein
             * `WITH GRANT OPTION`. Ein Kunde, der Rechte weiterreichen darf,
             * kann sich selbst welche geben.
             */
            $statements[] = sprintf(
                'GRANT ALL PRIVILEGES ON %s TO %s',
                Sql::grantTarget($database),
                Sql::account($account, $host),
            );
        }

        return $statements;
    }

    /**
     * Das Passwort, wie es aus dem Panel kommt.
     *
     * Geprüft wird gegen dasselbe Alphabet, aus dem `PanelProvision::secret()`
     * erzeugt — und der Grund steht dort: Das Passwort landet in einer
     * SQL-Anweisung und in einer Optionsdatei, und Zeichen, die in einer der
     * beiden Bedeutung haben, sind kein Gewinn an Stärke, sondern eine
     * Fehlerquelle. Der Kunde wählt es nicht (`docs/36 §19`, Punkt 7); käme
     * hier je ein selbst gewähltes an, wiese diese Zeile es ab.
     */
    private static function password(mixed $value): string
    {
        $password = Guard::string($value, 'password');

        if (! preg_match('/^[A-Za-z0-9]{16,128}$/D', $password)) {
            throw AgentException::badRequest(
                'Das Passwort wird vom Panel erzeugt — erwartet werden 16 bis 128 Buchstaben und Ziffern.',
            );
        }

        return $password;
    }

    /**
     * Die Datenbanken, die freigegeben werden — jede geprüft.
     *
     * @return list<string>
     */
    private static function databases(mixed $value, string $prefix): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('databases muss eine Liste sein.');
        }

        if (count($value) > self::MAX_DATABASES) {
            throw AgentException::badRequest(sprintf(
                'Ein Aufruf gibt höchstens %d Datenbanken frei.',
                self::MAX_DATABASES,
            ));
        }

        $databases = [];

        foreach ($value as $entry) {
            $database = Names::existing($entry, 'databases');

            if (! Names::belongsTo($database, $prefix)) {
                throw AgentException::denied(sprintf(
                    'Die Datenbank %s gehört nicht zum Abonnement %s.',
                    $database,
                    $prefix,
                ));
            }

            $databases[] = $database;
        }

        return array_values(array_unique($databases));
    }
}
