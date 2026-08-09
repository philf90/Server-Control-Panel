<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Pg\Clusters;
use SrvPanel\Agent\Pg\Server;

/**
 * Die Ausgabe von `pg_lsclusters`, Feld für Feld.
 *
 * **Der Anlass ist ein Fehler, den kein Lesen gefunden hat.** Der erste Wurf
 * von {@see Clusters} nahm Feld 4 als Datenverzeichnis — das ist der
 * Eigentümer. Aufgefallen ist es erst beim Lauf gegen das echte Werkzeug, und
 * zwar nur, weil die Ausgabe daneben stand: Ein Cluster mit dem
 * Datenverzeichnis `postgres` sieht in einer Ablage nicht falsch aus.
 *
 * Das ist bemerkenswert, weil die Feldnummern im Kommentar der Methode
 * *richtig* standen. Gezählt wurde trotzdem falsch — und **ein Kommentar, der
 * die Zählung beschreibt, prüft die Zählung nicht.**
 *
 * **Warum das Datenverzeichnis überhaupt zählt.** Es ist nicht Beiwerk:
 * {@see Server::describe()} hält es gegen das, was die
 * Verbindung selbst zurückmeldet, und stellt damit fest, ob `psql` mit dem
 * Cluster geredet hat, den wir gemeint haben. Steht dort der Eigentümer, ist
 * dieser Abgleich eine Prüfung, die nie zutrifft — also gar keine.
 *
 * Die Zeilen unten sind abgeschrieben und nicht ausgedacht: gemessen am
 * 9. August 2026 gegen `postgresql-16` (16.13, Ubuntu 24.04), einmal mit einem
 * gestoppten Cluster und einmal mit zwei laufenden.
 */
final class PgClusterTest extends TestCase
{
    /**
     * Eine echte Zeile, ein gestoppter Cluster.
     */
    private const ONE = '16  main    5432 down   postgres /var/lib/postgresql/16/main '
        .'/var/log/postgresql/postgresql-16-main.log';

    /**
     * Zwei laufende — der Fall, in dem das Panel nicht wählt.
     */
    private const TWO = '16 main  5432 online postgres /var/lib/postgresql/16/main  '
        ."/var/log/postgresql/postgresql-16-main.log\n"
        .'16 zweit 5433 online postgres /var/lib/postgresql/16/zweit '
        .'/var/log/postgresql/postgresql-16-zweit.log';

    /**
     * Das Datenverzeichnis ist Feld 5 und nicht der Eigentümer.
     *
     * **Der Wächter zu genau dem Fehler**, den es gab. Er prüft die
     * Gegenrichtung mit: dass dort nicht `postgres` steht — denn das ist der
     * Wert, den die falsche Zählung geliefert hat, und er ist auf jedem System
     * derselbe.
     */
    public function test_the_data_directory_is_the_sixth_field(): void
    {
        $clusters = Clusters::parse(self::ONE);

        $this->assertCount(1, $clusters);
        $this->assertSame('/var/lib/postgresql/16/main', $clusters[0]['directory']);
        $this->assertNotSame('postgres', $clusters[0]['directory']);
    }

    /**
     * Fassung, Name, Port und Zustand kommen aus derselben Zeile.
     */
    public function test_a_stopped_cluster_reads_as_stopped(): void
    {
        $clusters = Clusters::parse(self::ONE);

        $this->assertSame(16, $clusters[0]['version']);
        $this->assertSame('main', $clusters[0]['name']);
        $this->assertSame(5432, $clusters[0]['port']);
        $this->assertFalse($clusters[0]['running']);
    }

    /**
     * Zwei Cluster sind zwei Einträge, und der zweite ist nicht der erste.
     *
     * Ohne diesen Fall bestünde eine Auswertung, die nur die erste Zeile liest
     * — und `ambiguous` wäre ein Zustand, den es nie gäbe.
     */
    public function test_two_clusters_are_two_entries(): void
    {
        $clusters = Clusters::parse(self::TWO);

        $this->assertCount(2, $clusters);
        $this->assertSame(5432, $clusters[0]['port']);
        $this->assertSame(5433, $clusters[1]['port']);
        $this->assertSame('/var/lib/postgresql/16/zweit', $clusters[1]['directory']);
        $this->assertTrue($clusters[0]['running']);
        $this->assertTrue($clusters[1]['running']);
    }

    /**
     * Nur `online` heisst „antwortet".
     *
     * Ein Cluster mitten im Start meldet etwas anderes, und die sichere
     * Richtung ist, ihn nicht für erreichbar zu halten.
     *
     * **`online` steht in der Liste, und das war es zuerst nicht.** Ohne diesen
     * einen Wert prüfte die Schleife ausschliesslich die Nein-Seite: Jeder
     * Vergleich war falsch, jede Behauptung ging durch, und eine Auswertung,
     * die *nie* etwas als laufend liest, wäre grün geblieben. Gefunden hat es
     * PHPStan („will always evaluate to false") und nicht der Lauf — genau der
     * tote Zweig, den CLAUDE.md als hier unauffindbar beschreibt.
     */
    public function test_only_online_counts_as_running(): void
    {
        foreach (['online', 'down', 'online,recovery', 'starting'] as $status) {
            $line = sprintf(
                '16 main 5432 %s postgres /var/lib/postgresql/16/main /var/log/x.log',
                $status,
            );

            $this->assertSame(
                $status === 'online',
                Clusters::parse($line)[0]['running'],
                sprintf('Zustand %s falsch gelesen', $status),
            );
        }
    }

    /**
     * Keine Ausgabe heisst kein Cluster — und nicht „eine leere Zeile".
     *
     * **`[]` und `null` sind hier zwei verschiedene Antworten** (siehe
     * {@see Clusters}). Diese Methode gibt nur die eine; die andere entsteht
     * eine Ebene höher aus dem fehlenden Werkzeug, und sie lässt sich hier
     * nicht prüfen — ob `pg_lsclusters` da ist, ist eine Eigenschaft der
     * Maschine, und ein Test, der davon abhängt, sagt in der CI etwas anderes
     * als im Entwicklungscontainer.
     */
    public function test_no_output_is_no_cluster(): void
    {
        $this->assertSame([], Clusters::parse(''));
        $this->assertSame([], Clusters::parse("\n  \n"));
    }

    /**
     * Eine Kopfzeile ist kein Cluster.
     *
     * `--no-header` soll sie verhindern; eine Fassung, die sie doch schreibt,
     * ergäbe sonst einen Cluster mit der Fassung 0 — und der stünde dann als
     * „läuft nicht" in der Oberfläche.
     */
    public function test_a_header_line_is_not_a_cluster(): void
    {
        $output = "Ver Cluster Port Status Owner    Data directory              Log file\n".self::ONE;

        $this->assertCount(1, Clusters::parse($output));
    }
}
