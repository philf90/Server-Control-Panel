<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\DbUsage;

/**
 * Die Messung gibt nur die Schemata dieses Panels heraus.
 *
 * **Warum das ein eigener Test ist und nicht eine Zeile in einem anderen.**
 * `information_schema.tables` kennt jedes Schema des Servers: `mysql` mit der
 * Benutzertabelle, `sys`, `performance_schema` — und die Datenbank des Panels
 * selbst, in der die Kontodaten, die Sitzungen und die Zertifikatszeilen
 * liegen. Was `db.usage` zurückgibt, geht als Ergebnis eines Aufrufs an die
 * Anwendung; eine Operation, die die Schemaliste des Servers ausliefert, wäre
 * eine Auskunft, die niemand bestellt hat. Derselbe Satz steht über `repquota`
 * in `docs/26 §8`, und dort ist er aus demselben Anlass entstanden.
 *
 * **Geprüft wird als Text und nicht an einer Verbindung.** In diesem Container
 * läuft kein MariaDB (CLAUDE.md), und das ist hier kein Behelf: Was zählt, ist
 * die Aussonderung — sie an einem laufenden Server zu prüfen hiesse, sie an
 * dessen zufälligem Schemabestand zu prüfen. Deshalb ist {@see DbUsage::parse()}
 * eine reine Funktion.
 */
final class DbUsageScopeTest extends TestCase
{
    /**
     * Was `information_schema` auf einem echten Server ausgibt.
     *
     * Die fremden Namen sind nicht ausgedacht: `mysql`, `information_schema`,
     * `performance_schema` und `sys` liefert MariaDB mit, `srvpanel` ist die
     * Datenbank dieses Panels (siehe `PanelProvision`), und `wordpress` steht
     * für das, was ein Betreiber von Hand angelegt hat, bevor es dieses Panel
     * gab.
     *
     * @return list<list<string>>
     */
    private function rows(): array
    {
        return [
            ['information_schema', '196608'],
            ['mysql', '2621440'],
            ['performance_schema', '0'],
            ['sys', '16384'],
            ['srvpanel', '5242880'],
            ['wordpress', '104857600'],
            ['p1001_shop', '52428800'],
            ['p1001_blog', '1048576'],
            ['p123456789_a', '0'],
        ];
    }

    public function test_only_the_schemas_of_this_panel_are_reported(): void
    {
        $measured = DbUsage::parse($this->rows());

        $this->assertSame(['p1001_shop', 'p1001_blog', 'p123456789_a'], array_keys($measured));
    }

    public function test_the_panels_own_database_is_not_reported(): void
    {
        // Eine eigene Behauptung, obwohl der Test darüber sie mitträgt: Wer
        // die Liste oben erweitert, soll an dieser Zeile sehen, welcher der
        // Namen der teuerste ist.
        $this->assertArrayNotHasKey('srvpanel', DbUsage::parse($this->rows()));
        $this->assertArrayNotHasKey('mysql', DbUsage::parse($this->rows()));
    }

    /**
     * Ein Name, der *fast* passt, passt nicht.
     *
     * Dieselbe Sorte Beinahe-Treffer wie in `GrantPatternTest`: Das Präfix ist
     * `p` plus vier bis neun Ziffern, dann ein Unterstrich. `p1001x_shop` hat
     * einen Buchstaben zu viel, `p100_shop` eine Ziffer zu wenig, und
     * `p1001-shop` gar keinen Unterstrich. Ein Betreiber darf solche Schemata
     * anlegen — sie gehören dann ihm und nicht dem Panel.
     */
    public function test_a_name_that_almost_fits_does_not(): void
    {
        $fremd = [
            ['p1001x_shop', '1'],
            ['p100_shop', '1'],
            ['p1001-shop', '1'],
            ['p1001_', '1'],
            ['p1001_Shop', '1'],
            ['xp1001_shop', '1'],
        ];

        $this->assertSame([], DbUsage::parse($fremd));
    }

    /**
     * Die Zahl ist die aus der Abfrage, unverändert.
     *
     * Umgerechnet wird im Panel und nicht hier: Der Agent liefert Bytes, weil
     * das die Einheit der Abfrage ist. Wer hier durch 1024² teilte, verlöre für
     * jede Datenbank unter einem Megabyte die Unterscheidung zwischen „leer"
     * und „klein" — und das ist genau die Unterscheidung, nach der jemand
     * sucht, der eine Sicherung vermisst.
     */
    public function test_the_number_is_bytes_and_not_megabytes(): void
    {
        $this->assertSame(['p1001_shop' => 52_428_800], DbUsage::parse([['p1001_shop', '52428800']]));
    }

    /** Ein Schema aus lauter Sichten hat `NULL` als Summe — daraus wird 0. */
    public function test_a_schema_without_tables_measures_zero(): void
    {
        $this->assertSame(['p1001_shop' => 0], DbUsage::parse([['p1001_shop', 'NULL']]));
    }
}
