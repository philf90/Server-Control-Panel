<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Op;

/**
 * Der belegte Platz aller Datenbanken — in einem Aufruf.
 *
 * **Warum ein Aufruf für alle und nicht einer je Datenbank.** Wörtlich dieselbe
 * Entscheidung wie bei {@see SubscriptionUsage}, und aus demselben Grund: Bei
 * hundert Abonnements wären es hundert Prozessgründungen je Viertelstunde auf
 * einem Server, der nebenbei Webseiten ausliefert. `information_schema` weiss
 * ohnehin alles auf einmal. Diese Operation nimmt deshalb **keine Argumente** —
 * es gibt nichts auszuwählen.
 *
 * **Sie meldet nur die Schemata des Panels.** `information_schema` gibt jedes
 * Schema des Servers aus, auch `mysql`, `information_schema` selbst und das der
 * Panel-Datenbank. Herausgegeben wird nur, was {@see Names::isPanelName()}
 * annimmt — dieselbe Regel, die beim Anlegen gilt, und derselbe Satz wie über
 * `repquota`: Eine Operation, die die Schemaliste des Servers ausliefert, wäre
 * eine Auskunft, die niemand bestellt hat.
 *
 * **Gemessen wird der belegte Platz und nicht die Nutzdatenmenge.**
 * `data_length + index_length` ist bei InnoDB der zugeteilte Platz in den
 * Tabellendateien, einschliesslich des Freiraums nach gelöschten Zeilen. Das
 * ist die Zahl, um die es bei einem Kontingent geht — was auf dem Datenträger
 * liegt. Sie kann nach einem grossen `DELETE` über der Nutzdatenmenge stehen,
 * und deshalb sagt die Oberfläche „belegt" und nicht „Daten".
 *
 * **Ein Server, der nicht antwortet, ist kein Fehler.** Wie bei
 * {@see SubscriptionUsage} ohne `usrquota` kommt `available: false` samt Grund
 * zurück und keine Ausnahme: Ein Vorgang, der jede Viertelstunde rot wird, weil
 * auf diesem Server kein MariaDB läuft, ist eine Meldung, die man nach zwei
 * Tagen wegsieht.
 *
 * Nicht verändernd — sie liest.
 */
final class DbUsage implements Op
{
    /**
     * Die Abfrage.
     *
     * `GROUP BY table_schema` über `information_schema.tables` — eine Zeile je
     * Schema. Schemata ohne Tabellen erscheinen dabei **nicht**; das ist
     * richtig und wird im Panel als gemessene Null behandelt, genau wie ein
     * Systembenutzer, den die Quota-Datei noch nicht kennt.
     *
     * `SUM(...)` ist bei einem Schema aus lauter Sichten `NULL` — deshalb
     * `COALESCE`, sonst stünde dort die Zeichenkette `NULL` und `(int)` machte
     * daraus eine 0, die aussieht wie eine Messung.
     */
    public const SQL = 'SELECT table_schema, COALESCE(SUM(data_length + index_length), 0)'
        .' FROM information_schema.tables GROUP BY table_schema';

    public static function name(): string
    {
        return 'db.usage';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $args */
    public function execute(array $args, Context $context): array
    {
        try {
            $rows = (new Session)->query($context, self::SQL);
        } catch (AgentException $error) {
            return ['available' => false, 'databases' => [], 'reason' => $error->getMessage()];
        }

        return ['available' => true, 'databases' => self::parse($rows)];
    }

    /**
     * Die Zeilen in Bytes je Schema — nur die des Panels.
     *
     * Getrennt vom Lauf und `public static`, damit die Regel als Text prüfbar
     * ist: In diesem Container gibt es kein MariaDB (CLAUDE.md), und was hier
     * zählt, ist die Aussonderung fremder Schemata. Sie an einer Verbindung zu
     * prüfen hiesse, sie gar nicht zu prüfen.
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
