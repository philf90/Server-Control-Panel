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
 * ## Das Schema `public` darin gehört dem Abonnement
 *
 * Und das ist kein Widerspruch dazu, sondern die Antwort auf die Frage, die P5
 * nie stellen musste: *Wem gehört, was in dieser Datenbank entsteht?* In
 * PostgreSQL gehört eine Tabelle dem, der sie angelegt hat — ein zweiter Zugang
 * desselben Abonnements bekäme `permission denied`. Die Eigentümerrolle aus
 * {@see Owner} nimmt diese Frage aus den Zugängen heraus. Sie kann nichts
 * freigeben, was die Absperrung berührte: `GRANT CONNECT` steht an der
 * Datenbank, und die gehört weiter dem Panel.
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
        private readonly Owner $owner = new Owner,
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
        /*
         * **Das Gebietsschema wird erfragt und nicht gesetzt.**
         *
         * Hier stand `?? 'C.UTF-8'`, und solange das Panel eines mitschickte,
         * griff die Zeile nie. Seit es keines mehr schickt (`docs/39`, Punkt 3),
         * **war sie die Antwort** — und `C.UTF-8` sortiert nach Bytes: „Äpfel"
         * steht dann hinter „Zebra". Auf `cloudsrv24` bekam die erste
         * Kundendatenbank so eine andere Sortierung als jede andere Datenbank
         * des Servers. Gemessen am 10. August 2026, entschieden vom Betreiber.
         *
         * *Ein Vorgabewert, den niemand überschreibt, ist kein Vorgabewert — er
         * ist die Antwort.* Zum fünften Mal an einem Tag, diesmal im Agenten.
         */
        $locale = self::locale($args['locale'] ?? $this->clusterLocale($context));

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

        $context->progress(75, 'absperren');
        $this->session->execute($context, Shielding::statements($database, $channels), $database);

        /*
         * **Das Schema gehört dem Abonnement und nicht dem Panel** — anders als
         * die Datenbank darüber, und das ist kein Widerspruch, sondern die
         * Grenze aus {@see Owner}: Wer eine Datenbank besitzt, kann
         * `GRANT CONNECT … TO PUBLIC` und hebt damit die Absperrung auf; wer
         * ihr Schema besitzt, kann das nicht.
         *
         * **Läuft nach der Absperrung.** `Shielding::statements()` enthält
         * `REVOKE ALL ON SCHEMA public FROM PUBLIC`, und {@see Owner} setzt es
         * gleich noch einmal — die Reihenfolge ist deshalb gleichgültig, aber
         * eine Absperrung, die auf ein Schema mit fremdem Eigentümer trifft,
         * wäre die Sorte Abhängigkeit, die niemand liest.
         */
        $context->progress(90, 'Eigentümerrolle');
        $owner = $this->owner->ensure($context, $prefix);
        $this->session->execute($context, Owner::schemaStatements($owner), $database);

        $context->progress(100, 'fertig');

        return [
            'name' => $database,
            'created' => ! $existed,
            'encoding' => $encoding,
            'locale' => $locale,
            'shielded' => count($channels),
            'owner' => $owner,
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

    /**
     * Die Sortierung, mit der dieser Cluster eingerichtet wurde.
     *
     * **Gefragt wird `template0` und nicht `template1`.** Zwei Gründe, und
     * beide zählen: `template0` trägt unverändert, was `initdb` gesetzt hat —
     * das ist „die Vorgabe des Clusters" im Wortsinn. Und es ist die Vorlage,
     * aus der {@see self::statement()} anlegt; ein Gebietsschema, das zu ihr
     * passt, ist immer zulässig.
     *
     * **Ohne Antwort wird nichts erfunden.** Ein Ersatzwert an dieser Stelle
     * wäre genau der Fehler, den diese Änderung behebt — er stünde still da und
     * würde eines Tages die Antwort. Kommt der Katalog nicht, bricht das
     * Anlegen ab und sagt warum.
     */
    private function clusterLocale(Context $context): string
    {
        $rows = $this->session->query(
            $context,
            "SELECT datcollate FROM pg_database WHERE datname = 'template0'",
        );

        $locale = (string) ($rows[0][0] ?? '');

        if ($locale === '') {
            throw AgentException::execFailed(
                'Der Katalog nennt die Sortierung von template0 nicht — ohne sie lässt sich keine '
                .'Datenbank anlegen, die zum Cluster passt.',
            );
        }

        return $locale;
    }
}
