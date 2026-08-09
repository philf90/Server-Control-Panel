<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Pg\Shielding;
use SrvPanel\Agent\Pg\Sql;

/**
 * Eine PostgreSQL-Datenbank anlegen — und im selben Lauf absperren.
 *
 * **Das Absperren gehört hierher und nicht in ein Einrichtungsskript**, und das
 * ist der teuerste Fund der Messung vom 9. August 2026 (`docs/38 §2.2`, M2b):
 * Die Rechte auf die Katalogsichten stehen **je Datenbank**. Eine Absperrung,
 * die einmal gesetzt wird, ist bei der nächsten mit `TEMPLATE template0`
 * angelegten Datenbank wieder fort — und `template0` ist Pflicht, sobald eine
 * Sortierung gesetzt wird. Gemessen sah das so aus: dieselbe Rolle sah in der
 * einen Datenbank nichts und in der nächsten sieben Namen, und beide sahen von
 * aussen gleich aus.
 *
 * ## Die Datenbank gehört dem Panel
 *
 * Kein `OWNER`-Zusatz: Eigentümer wird die Rolle, die sie anlegt, und das ist
 * die des Agenten. Das ist Entscheidung 2 aus `docs/38 §21`, und sie ist
 * gemessen und nicht vorsichtshalber — ein Eigentümer darf
 * `GRANT CONNECT … TO PUBLIC` und macht damit die Absperrung rückgängig, und er
 * darf seine Datenbank löschen. **Ein Abnahmekriterium, das der Geprüfte mit
 * einer Zeile SQL abschalten kann, ist keins.**
 *
 * ## `TEMPLATE template0`, immer
 *
 * Nicht nur, wenn eine Sortierung gesetzt wird: `template1` nimmt auf, was
 * jemand dort hinterlassen hat, und was in `template1` steht, steht danach in
 * der Datenbank jedes Kunden. `template0` ist die Fassung, die PostgreSQL
 * unverändert hält — und die Absperrung darunter kostet nichts, weil sie
 * ohnehin je Datenbank läuft.
 *
 * ## Wiederholbar, aber nicht blind
 *
 * `CREATE DATABASE` kennt kein `IF NOT EXISTS`. Gefragt wird deshalb vorher —
 * und eine Datenbank, die es schon gibt, wird **nicht** übersprungen, sondern
 * bekommt die Absperrung noch einmal. Das ist der Unterschied zwischen
 * „wiederholbar" und „beim zweiten Mal wirkungslos": Ein abgebrochener erster
 * Lauf kann die Datenbank angelegt und die Absperrung nicht mehr geschafft
 * haben, und genau dieser Zustand sieht von aussen fertig aus.
 */
final class PgDatabaseCreate implements Op
{
    /**
     * Die Zeichensätze, die dieses Panel anlegt.
     *
     * Einer. Eine Datenbank in `LATIN1` ist ein Kunde, der in fünf Jahren einen
     * Umzug bezahlt — und `docs/38 §9` hält fest, dass die Sortierung in
     * PostgreSQL beim Anlegen feststeht und danach nicht mehr wechselt.
     *
     * @var list<string>
     */
    private const ENCODINGS = ['UTF8'];

    /**
     * Die Form eines Gebietsschemas.
     *
     * `C.UTF-8`, `de_DE.UTF-8`, `en_US.UTF-8`. Kein Freitext: Der Wert geht in
     * eine SQL-Anweisung, und er kommt aus dem Panel und nicht aus dem
     * Formular — geprüft wird er trotzdem, weil {@see Sql} die zweite Schranke
     * ist und diese hier die erste.
     */
    private const LOCALE = '/^[A-Za-z0-9_]+(\.[A-Za-z0-9-]+)?$/D';

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'pg.database.create';
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
        $database = Names::database($prefix, $args['suffix'] ?? null);
        $encoding = Guard::enum($args['encoding'] ?? 'UTF8', self::ENCODINGS, 'encoding');
        $locale = self::locale($args['locale'] ?? 'C.UTF-8');

        $existed = $this->exists($context, $database);

        if (! $existed) {
            $context->progress(30, 'Datenbank anlegen');
            $this->session->execute($context, [self::statement($database, $encoding, $locale)]);
        }

        /*
         * **Die Kanäle werden erfragt und nicht verdrahtet** — sie sind
         * fassungsabhängig (`docs/38 §10`). Gefragt wird **in der neuen
         * Datenbank**, denn dort gelten die Rechte, die gleich entzogen werden.
         */
        $context->progress(60, 'Kanäle erfragen');
        $channels = array_map(
            static fn (array $row): string => (string) ($row[0] ?? ''),
            $this->session->query($context, Shielding::DISCOVERY, $database),
        );

        if ($channels === []) {
            throw AgentException::execFailed(
                'Der Katalog nennt keine Sicht mit einem Datenbanknamen — dann greift die Absperrung ins Leere.',
                ['database' => $database],
            );
        }

        $context->progress(80, 'absperren');
        $this->session->execute($context, Shielding::statements($database, $channels), $database);

        $context->progress(100, 'fertig');

        return [
            'name' => $database,
            'created' => ! $existed,
            'encoding' => $encoding,
            'locale' => $locale,
            'shielded' => count($channels),
        ];
    }

    /**
     * Die Anweisung — als reine Funktion, damit sie sich ohne Server prüfen
     * lässt.
     */
    public static function statement(string $database, string $encoding, string $locale): string
    {
        return sprintf(
            'CREATE DATABASE %s TEMPLATE template0 ENCODING %s LC_COLLATE %s LC_CTYPE %s',
            Sql::identifier($database),
            Sql::text($encoding),
            Sql::text($locale),
            Sql::text($locale),
        );
    }

    private static function locale(mixed $value): string
    {
        $locale = Guard::string($value, 'locale');

        if (preg_match(self::LOCALE, $locale) !== 1) {
            throw AgentException::badRequest('Unzulässiges Gebietsschema.', ['locale' => $locale]);
        }

        return $locale;
    }

    private function exists(Context $context, string $database): bool
    {
        return $this->session->query(
            $context,
            'SELECT 1 FROM pg_database WHERE datname = '.Sql::text($database),
        ) !== [];
    }
}
