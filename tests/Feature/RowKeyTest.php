<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Console as DbConsole;
use SrvPanel\Agent\Pg\Console as PgConsole;

/**
 * Ein Schreibvorgang braucht einen Schlüssel und zählt nach, was er getroffen hat.
 *
 * ## Warum es diesen Wächter gibt
 *
 * **Ohne Schlüssel gibt es keinen sicheren Bezug auf eine Zeile** (`docs/46
 * §10`). Zwei Zeilen mit gleichem Inhalt sind in einer Tabelle ohne Schlüssel
 * nicht unterscheidbar, und ein `UPDATE … WHERE <alle Spalten>` trifft dann
 * beide. Der Schaden ist dabei nicht die Fehlermeldung, sondern ihr Ausbleiben:
 * Der Vorgang meldet Erfolg.
 *
 * > **Ein Schreibvorgang, der nicht nachzählt, was er getroffen hat, meldet
 * > Erfolg für einen Treffer, den niemand geprüft hat.**
 *
 * Deshalb prüft dieser Wächter **an der erzeugten Anweisung** und nicht an einem
 * Ergebnis: Dieselbe Bauform wie `PhpIsolationTest` und `SiteTemplateTest`, und
 * hier aus demselben Grund richtig — der Schutz ist eine Eigenschaft der
 * erzeugten Zeichenkette, und dieser Container hat für die eine Hälfte keinen
 * Server.
 *
 * ## Und zwei Wege dorthin, weil die Systeme verschieden sind
 *
 * PostgreSQL kennt keinen `LIMIT` an `UPDATE` und bekommt deshalb einen
 * `DO`-Block mit `GET DIAGNOSTICS`; MariaDB kennt keinen anonymen Block und
 * bekommt `LIMIT 1` plus `ROW_COUNT()`. Geprüft wird beides, denn eine Regel,
 * die nur für ein System gilt, ist in diesem Panel eine halbe.
 */
final class RowKeyTest extends TestCase
{
    /**
     * Die MariaDB-Konsole **ohne Kommentare**.
     *
     * **Der Bruch hat es gefunden, und der Wächter blieb dabei grün.** Beide
     * Behauptungen unten suchen einen Aufruf im Quelltext — und beide Namen
     * stehen dort auch in einem erklärenden Kommentar. Wer den Aufruf entfernt
     * und die Erklärung stehen lässt, bekam vorher ein Grün.
     *
     * > **Ein Wächter, der Kommentare liest, wird von der Dokumentation des
     * > Fehlers beruhigt, vor dem er schützt.**
     *
     * Dasselbe Muster wie in `ConsoleFanoutTest` und `NullDisplayTest`, nur
     * andersherum: Dort machte ein Kommentar den Test rot, hier grün. Die
     * zweite Richtung ist die gefährlichere, weil sie nach Ordnung aussieht.
     */
    private function mariadb(): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Db/Console.php'),
        );
    }

    /**
     * Ein Spaltensatz, wie ihn `columns()` liefert.
     *
     * @param  array<int, array{name: string, key: bool}>  $columns
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>
     */
    private function columns(array $columns): array
    {
        return array_values(array_map(
            static fn (array $column): array => $column + [
                'type' => 'text',
                'nullable' => true,
                'default' => null,
                'binary' => false,
            ],
            $columns,
        ));
    }

    /** @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}> */
    private function simple(): array
    {
        return $this->columns([['name' => 'id', 'key' => true], ['name' => 'ort', 'key' => false]]);
    }

    /**
     * Der `DO`-Block zählt nach, und was er zählt, entscheidet.
     *
     * **Der Bruch dazu ist, die Zählung zu entfernen** — dann bleibt eine
     * Anweisung, die genau dasselbe tut und nichts mehr prüft.
     */
    public function test_postgres_counts_what_it_touched(): void
    {
        $statement = PgConsole::writeStatement('public', 't', $this->simple(), 'update', ['id' => '1'], ['ort' => 'Kiel']);

        $this->assertStringContainsString(
            'GET DIAGNOSTICS',
            $statement,
            'Der Schreibvorgang für PostgreSQL fragt seine Trefferzahl nicht ab. Ohne sie meldet er '
            .'Erfolg für einen Treffer, den niemand geprüft hat (docs/46 §10.1).',
        );

        $this->assertMatchesRegularExpression(
            '/getroffen\s*<>\s*1/',
            $statement,
            'Der Schreibvorgang für PostgreSQL vergleicht seine Trefferzahl nicht gegen genau eins. '
            .'Ein `UPDATE`, das zwei Zeilen trifft, liefe damit durch.',
        );

        $this->assertStringContainsString(
            'RAISE EXCEPTION',
            $statement,
            'Der Schreibvorgang für PostgreSQL bricht bei der falschen Trefferzahl nicht ab. Eine '
            .'Zählung ohne Abbruch ist eine Zahl, die niemand liest — und die Transaktion bliebe stehen.',
        );
    }

    /**
     * MariaDB kann nicht mehr als eine Zeile treffen, und prüft, ob es eine war.
     *
     * **Zwei Riegel und nicht einer.** `LIMIT 1` macht „mehr als eine"
     * unmöglich; `ROW_COUNT()` fängt die andere Richtung — null Zeilen, weil die
     * Zeile zwischen Anzeige und Änderung verschwunden ist.
     */
    public function test_mariadb_can_touch_at_most_one_row_and_checks_it_was_one(): void
    {
        foreach (['update', 'delete'] as $mode) {
            $statement = DbConsole::writeStatement(
                'db',
                't',
                $this->simple(),
                $mode,
                ['id' => '1'],
                $mode === 'delete' ? [] : ['ort' => 'Kiel'],
            );

            $this->assertMatchesRegularExpression(
                '/LIMIT 1\s*$/',
                $statement,
                sprintf(
                    'Der Schreibvorgang `%s` für MariaDB trägt kein `LIMIT 1`: %s'."\n\n"
                    .'MariaDB hat keinen anonymen Block, in dem sich nachzählen liesse — `LIMIT 1` ist '
                    .'dort der Riegel gegen „mehr als eine Zeile".',
                    $mode,
                    $statement,
                ),
            );
        }

        $source = $this->mariadb();

        $this->assertStringContainsString(
            'ROW_COUNT()',
            $source,
            'Die MariaDB-Konsole fragt `ROW_COUNT()` nicht ab. `LIMIT 1` verhindert „mehr als eine" — '
            .'„keine einzige" sieht ohne die Abfrage aus wie Erfolg.',
        );
    }

    /**
     * Ohne Schlüssel entsteht die Anweisung gar nicht erst.
     *
     * **In beiden Systemen und für beide Arten.** Ein `UPDATE` ohne `WHERE`
     * trifft die ganze Tabelle; ein `DELETE` ohne `WHERE` leert sie. Das ist der
     * eine Fall dieser Stufe, bei dem ein fehlender Riegel nicht eine Zeile
     * kostet, sondern alle.
     */
    public function test_no_statement_is_built_without_a_key(): void
    {
        $keyless = $this->columns([['name' => 'ort', 'key' => false]]);
        $gesehen = 0;

        foreach (['update', 'delete'] as $mode) {
            foreach (['pg', 'db'] as $engine) {
                $gesehen++;

                try {
                    if ($engine === 'pg') {
                        PgConsole::writeStatement('public', 't', $keyless, $mode, [], ['ort' => 'Kiel']);
                    } else {
                        DbConsole::writeStatement('db', 't', $keyless, $mode, [], ['ort' => 'Kiel']);
                    }
                } catch (AgentException) {
                    continue;
                }

                $this->assertTrue(
                    false,
                    sprintf(
                        'Ein `%s` ohne Schlüssel entsteht in %s ohne Widerspruch. Ein `UPDATE` ohne '
                        .'`WHERE` trifft die ganze Tabelle, ein `DELETE` leert sie (docs/46 §10).',
                        $mode,
                        $engine === 'pg' ? 'PostgreSQL' : 'MariaDB',
                    ),
                );
            }
        }

        $this->assertSame(4, $gesehen, 'Es werden nicht alle vier Fälle gefahren — dann prüft dieser Test weniger, als er behauptet.');
    }

    /**
     * Ein halber Schlüssel ist keiner.
     *
     * **Bei einem zusammengesetzten Schlüssel `(b, c)` trifft `WHERE b = '1'`
     * jede Zeile mit diesem `b`.** Die Nachzählung nimmt das zwar zurück — sie
     * meldet dann aber „hat 3 Zeilen getroffen", und das liest sich wie ein
     * Nebenläufigkeitsproblem statt wie ein unvollständiger Aufruf.
     *
     * > **Eine Sicherung, die den Schaden verhindert, erklärt ihn nicht.**
     */
    public function test_half_a_key_is_refused(): void
    {
        $zusammen = $this->columns([
            ['name' => 'b', 'key' => true],
            ['name' => 'c', 'key' => true],
            ['name' => 'ort', 'key' => false],
        ]);

        foreach (['PostgreSQL' => PgConsole::keyCondition(...), 'MariaDB' => DbConsole::keyCondition(...)] as $name => $bauen) {
            try {
                $bauen($zusammen, ['b' => '1']);
            } catch (AgentException) {
                continue;
            }

            $this->assertTrue(
                false,
                sprintf('%s baut aus einem halben Schlüssel eine Bedingung. Sie trifft jede Zeile mit diesem `b`.', $name),
            );
        }

        // Und die Gegenprobe: Der vollständige Schlüssel geht durch. Ohne sie
        // wäre der Test auch dann grün, wenn `keyCondition()` alles abwiese.
        $this->assertStringContainsString(
            'AND',
            PgConsole::keyCondition($zusammen, ['b' => '1', 'c' => '2']),
            'Ein vollständiger zusammengesetzter Schlüssel wird nicht zu einer Bedingung über beide '
            .'Spalten — dann weist dieser Wächter etwas nach, das gar nicht funktioniert.',
        );
    }

    /**
     * Beide Systeme melden denselben Satz, und er kommt aus einer Quelle.
     *
     * **Der Anlass ist Befund 2 aus `docs/47`.** Der Satz stand in PostgreSQL
     * wörtlich im `RAISE EXCEPTION` und kam als Datenbankfehler verkleidet
     * zurück — mit `CONTEXT: PL/pgSQL function inline_code_block line 7 at
     * RAISE` und einem Vorspann, der sagt, es habe jemand anders gesprochen.
     *
     * > **Eine Verpackung, die für eine fremde Meldung richtig ist, ist für die
     * > eigene falsch.**
     */
    public function test_the_marker_is_one_constant_on_both_sides(): void
    {
        $statement = PgConsole::writeStatement('public', 't', $this->simple(), 'update', ['id' => '1'], ['ort' => 'Kiel']);

        $this->assertStringContainsString(
            PgConsole::MISS_MARKER.'=',
            $statement,
            'Der `DO`-Block schickt seine Trefferzahl nicht mit der Marke zurück. Ohne sie kann die '
            .'Anwendung die eigene Meldung nicht von einer fremden unterscheiden (docs/47 §6, Befund 2).',
        );

        $this->assertStringNotContainsString(
            'Zeilen getroffen',
            $statement,
            'Der Satz für den Kunden steht in der Anweisung. Er kommt damit als Datenbankfehler '
            .'verkleidet zurück, mit einer Zeilennummer auf eine Datei, die es nicht gibt.',
        );

        // **Die Marke wird nicht abgeschrieben, sondern gelesen.** Ein
        // Buchstabe Unterschied zwischen Bauen und Lesen fiele sonst nirgends
        // auf — die Meldung sähe aus wie eine fremde und bekäme still die alte
        // Verpackung zurück.
        $this->assertSame(
            7,
            PgConsole::missedCount('Die Datenbank hat abgewiesen: ERROR:  '.PgConsole::MISS_MARKER."=7\nCONTEXT:  irgendwas"),
            'Was der Block schickt, erkennt der Leser nicht wieder. Bauen und Lesen benutzen dann nicht '
            .'dieselbe Marke.',
        );

        $this->assertNull(
            PgConsole::missedCount('Die Datenbank hat abgewiesen: ERROR:  duplicate key value'
                ."\nDETAIL:  Key (ort)=(".PgConsole::MISS_MARKER.'=7) already exists.'),
            'Ein Kundenwert, der die Marke nachahmt, wird als eigene Meldung gelesen. Die Erkennung ist '
            .'dann nicht am Zeilenende verankert.',
        );

        $this->assertSame(
            PgConsole::missed(0),
            PgConsole::missed(0),
            'Der Satz ist nicht wiederholbar — dann kommt er nicht aus einer Quelle.',
        );

        $mariadb = $this->mariadb();

        $this->assertStringContainsString(
            'PgConsole::missed(',
            $mariadb,
            'Die MariaDB-Konsole baut ihren eigenen Satz. Zwei Fassungen derselben Meldung laufen '
            .'auseinander, und die zweite ist die, die niemand pflegt (docs/47 §6, Befund 2).',
        );
    }

    /**
     * Und die Oberfläche sagt, warum eine Tabelle nur lesbar ist.
     *
     * **Ein fehlendes Bedienelement ist keine Auskunft** (`docs/46 §4`,
     * Kriterium 5). Wer eine Zeile ändern will und keinen Knopf findet, sucht
     * weiter — der Satz beendet die Suche und sagt, womit es ginge.
     */
    public function test_the_interface_says_why_a_table_is_read_only(): void
    {
        $console = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Databases/Console.vue',
        );

        $this->assertSame(
            1,
            preg_match('/const readOnlyReason = computed\(.*?\n\}\)/su', $console, $treffer),
            'Es gibt keinen Grund mehr für „nur lesbar" — dann prüft dieser Test nichts.',
        );

        $this->assertStringContainsString(
            'nicht eindeutig ansprechen',
            $treffer[0],
            'Der Grund nennt den fehlenden Schlüssel nicht. „Ändern nicht möglich" beantwortet die '
            .'Frage nicht, die jemand hat, der es gerade versucht hat (docs/46 §10, Regel 3).',
        );

        $this->assertStringContainsString(
            'Sicht',
            $treffer[0],
            'Der Grund unterscheidet die Sicht nicht von der Tabelle ohne Schlüssel. Eine Sicht '
            .'speichert nichts — „leg einen Schlüssel an" wäre dort der falsche Rat.',
        );
    }
}
