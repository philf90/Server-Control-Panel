<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Session;

/**
 * Wie viel jede PostgreSQL-Datenbank belegt — alles in einem Aufruf.
 *
 * Das Gegenstück zu {@see DbUsage}, und die Entscheidungen sind dieselben.
 *
 * **Ein Aufruf für alle und nicht einer je Datenbank.** Bei zweihundert
 * Abonnements wären das zweihundert Verbindungen alle fünfzehn Minuten, und
 * jede fragt dasselbe.
 *
 * **Nicht erreichbar ist kein Fehlschlag.** Läuft kein PostgreSQL — und auf den
 * meisten Servern läuft keines —, kommt `available: false` mit dem Grund
 * zurück. Ein rot gemeldeter Vorgang alle fünfzehn Minuten ist eine Meldung,
 * die man nach zwei Tagen wegsieht.
 *
 * ## Warum nicht der reguläre Ausdruck aus `docs/38 §12`
 *
 * Der Plan schreibt die Abfrage mit `WHERE datname ~ '^x[0-9a-f]{16}_'`. Das
 * wäre die **zweite Fassung** des Musters — die erste steht in {@see Names},
 * und `CLAUDE.md` sagt über zweite Fassungen, dass die zweite die ist, die
 * veraltet. Ändert sich die Form des Präfixes je, ändert sie sich hier lautlos
 * nicht mit, und die Messung fände nichts mehr.
 *
 * Ausgesondert wird deshalb in {@see self::parse()} über
 * {@see Names::isPanelName()} — dieselbe Stelle, die auch beim Anlegen und
 * beim Rückbau entscheidet, was zum Panel gehört. Das kostet ein paar Zeilen
 * mehr über den Socket und keine Genauigkeit.
 *
 * **Und `pg_database_size()` läuft nur über das, was übrig bleibt.** Auf einer
 * fremden Datenbank ist der Aufruf zwar erlaubt, weil der Agent als Superuser
 * verbunden ist — gemessen werden soll sie trotzdem nicht, und die Grösse
 * fremder Daten gehört nicht in die Antwort.
 *
 * Nicht verändernd — sie liest.
 */
final class PgUsage implements Op
{
    /**
     * Die Abfrage.
     *
     * `pg_database_size()` liefert Bytes, einschliesslich Indizes und der
     * Verwaltungsdaten der Datenbank. Das ist das Gegenstück zu
     * `data_length + index_length` in MariaDB und nicht dasselbe: PostgreSQL
     * zählt hier mit, was auf der Platte liegt, MariaDB die logische Grösse
     * der Tabellen. Die Zahlen sind deshalb nicht vergleichbar — was sie
     * beantworten, ist dieselbe Frage: *Wie viel Platz belegt dieser Kunde?*
     *
     * `datallowconn` steht in der Bedingung, weil `template0` sonst einen
     * Verbindungsfehler auslöst. Für das Panel ist die Zeile ohnehin
     * uninteressant; sie fiele in {@see self::parse()} heraus.
     */
    public const SQL = 'SELECT datname, pg_database_size(oid) FROM pg_database WHERE datallowconn';

    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'pg.usage';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, Context $context): array
    {
        try {
            $rows = $this->session->query($context, self::SQL);
        } catch (AgentException $error) {
            return ['available' => false, 'databases' => [], 'reason' => $error->getMessage()];
        }

        return ['available' => true, 'databases' => self::parse($rows)];
    }

    /**
     * Die Zeilen in Bytes je Datenbank — nur die des Panels.
     *
     * Getrennt vom Lauf und `public static`, damit die Aussonderung als Text
     * prüfbar ist. Sie an einer Verbindung zu prüfen hiesse, sie gar nicht zu
     * prüfen: Was hier zählt, ist, dass die Datenbank des Betreibers und die
     * Vorlagen **nicht** in der Antwort stehen.
     *
     * @param  list<list<string>>  $rows
     * @return array<string, int>
     */
    public static function parse(array $rows): array
    {
        $databases = [];

        foreach ($rows as $row) {
            $name = (string) ($row[0] ?? '');

            if (! Names::isPanelName($name)) {
                continue;
            }

            $databases[$name] = max(0, (int) ($row[1] ?? 0));
        }

        return $databases;
    }
}
