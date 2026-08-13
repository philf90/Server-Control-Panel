<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Db\Console as DbConsole;
use SrvPanel\Agent\Pg\Console as PgConsole;

/**
 * Was zurückgeschrieben wird, ist nur das, was jemand geändert hat.
 *
 * ## Warum es diesen Wächter gibt — und warum er an der Anweisung misst
 *
 * **Das ist der einzige Punkt des Abnahmekriteriums, dessen Fehlschlag man an
 * der geänderten Zeile nicht sieht** (`docs/46 §4`, Punkt 6). Die Zeile ist
 * danach da, sie sieht richtig aus, und der Rest einer gekürzten Zelle ist fort
 * — für den, der sie aus einem ganz anderen Grund geöffnet hat. Ein Test am
 * Ergebnis fände nichts; deshalb misst dieser an der **erzeugten Anweisung**.
 *
 * > **Ein Formular, das zurückschreibt, was es nur angezeigt hat, überträgt
 * > jeden Anzeigefehler in die Daten.**
 *
 * ## Drei Regeln aus `docs/46 §10.1`
 *
 * 1. **Nur geänderte Spalten.** Ein `UPDATE` über alle schreibt auch die
 *    zurück, die nur angezeigt wurden — jede Kürzung, jedes `''` aus einem
 *    `NULL`, jede Rundung zwischen Anzeige und Formular.
 * 2. **`NULL` und `''` sind zwei Werte.** Wer sie gleich behandelt, merkt es an
 *    keiner Zählung: Ein `WHERE spalte IS NULL` der Kundenanwendung findet die
 *    Zeile danach einfach nicht mehr.
 * 3. **Eine gekürzte oder binäre Zelle ist gesperrt.** Was angezeigt wird, ist
 *    dort nicht der Wert — bei der binären Spalte ist es ihre Länge.
 *
 * Regel 1 ist die wichtigste: Sie schützt auch vor den Fällen, die hier niemand
 * aufgezählt hat.
 */
final class WriteBackTest extends TestCase
{
    /**
     * @param  array<int, array{name: string, key?: bool, binary?: bool, nullable?: bool}>  $columns
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>
     */
    private function columns(array $columns): array
    {
        return array_values(array_map(
            static fn (array $column): array => $column + [
                'type' => 'text',
                'nullable' => true,
                'default' => null,
                'key' => false,
                'binary' => false,
            ],
            $columns,
        ));
    }

    /** @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}> */
    private function table(): array
    {
        return $this->columns([
            ['name' => 'id', 'key' => true],
            ['name' => 'ort'],
            ['name' => 'bemerkung'],
            ['name' => 'anhang', 'binary' => true],
        ]);
    }

    /**
     * Die Anweisung nennt nur die Spalten, die übergeben wurden.
     *
     * **Der Bruch dazu ist, in `writeStatement()` alle Spalten zu nehmen statt
     * der übergebenen** — die Anweisung sieht danach richtiger aus als vorher,
     * und genau das ist der Schaden.
     */
    public function test_only_the_given_columns_reach_the_statement(): void
    {
        foreach (
            [
                'PostgreSQL' => PgConsole::writeStatement('public', 't', $this->table(), 'update', ['id' => '1'], ['ort' => 'Kiel']),
                'MariaDB' => DbConsole::writeStatement('db', 't', $this->table(), 'update', ['id' => '1'], ['ort' => 'Kiel']),
            ] as $name => $statement
        ) {
            $this->assertStringContainsString(
                'ort',
                $statement,
                $name.' schreibt die geänderte Spalte nicht — dann prüft dieser Test an nichts nach.',
            );

            foreach (['bemerkung', 'anhang'] as $unberuehrt) {
                $this->assertStringNotContainsString(
                    $unberuehrt,
                    $statement,
                    sprintf(
                        "%s nimmt `%s` in die Anweisung auf, obwohl niemand sie geändert hat:\n\n%s\n\n"
                        .'Damit wird zurückgeschrieben, was nur angezeigt wurde — bei einer gekürzten '
                        .'Zelle ist der Rest danach fort, und die Zeile sieht richtig aus '
                        .'(docs/46 §10.1, Regel 2).',
                        $name,
                        $unberuehrt,
                        $statement,
                    ),
                );
            }
        }
    }

    /**
     * `NULL` und die leere Zeichenkette sind zwei verschiedene Werte.
     *
     * **Der Test nennt beide und sagt nicht „der Wert stimmt nicht".** Das ist
     * dieselbe Vorsicht wie in Kriterium 2 des Abnahmelaufs: Wer die beiden
     * gleich behandelt, merkt es an keiner Zählung.
     */
    public function test_null_and_the_empty_string_stay_two_values(): void
    {
        $leer = PgConsole::literal('');
        $nichts = PgConsole::literal(null);

        $this->assertSame(
            "''",
            $leer,
            sprintf('Die leere Zeichenkette wird zu `%s` und nicht zu `\'\'`.', $leer),
        );

        $this->assertSame(
            'NULL',
            $nichts,
            sprintf('`null` wird zu `%s` und nicht zu `NULL`.', $nichts),
        );

        $this->assertNotSame(
            $leer,
            $nichts,
            'Die leere Zeichenkette und `NULL` werden zum selben Wert. Ein `WHERE spalte IS NULL` der '
            .'Kundenanwendung findet die Zeile danach nicht mehr, und keine Zählung meldet es '
            .'(docs/46 §10.1).',
        );

        /*
         * **Und in der ganzen Anweisung, in beiden Arten und beiden Systemen.**
         *
         * Der erste Wurf prüfte nur das Ändern — und der Bruch dazu (ein
         * `strval()` über die Werte) blieb **grün**, weil er den Zweig fürs
         * Anlegen traf. Eine neue Zeile mit einem ausdrücklichen `NULL` in einer
         * nullbaren Spalte ist ein gewöhnlicher Fall, und er hatte keinen
         * Wächter.
         *
         * > **Ein Wächter, der einen von zwei Zweigen prüft, deckt die Hälfte
         * > ab und meldet das nicht.**
         */
        $faelle = 0;

        foreach (['PostgreSQL', 'MariaDB'] as $engine) {
            foreach (['update' => '/(?:"ort"|`ort`)\s*=\s*NULL/', 'insert' => '/VALUES\s*\(NULL\)/'] as $mode => $muster) {
                $faelle++;

                $statement = $engine === 'PostgreSQL'
                    ? PgConsole::writeStatement('public', 't', $this->table(), $mode, ['id' => '1'], ['ort' => null])
                    : DbConsole::writeStatement('db', 't', $this->table(), $mode, ['id' => '1'], ['ort' => null]);

                $this->assertMatchesRegularExpression(
                    $muster,
                    $statement,
                    sprintf(
                        "%s schreibt beim `%s` kein `NULL`, obwohl eines gesetzt werden soll:\n\n%s\n\n"
                        .'Ein `NULL`, das unterwegs zu `\'\'` wird, ist der Fehler aus docs/46 §10.1 — '
                        .'und ein `WHERE spalte IS NULL` der Kundenanwendung findet die Zeile danach '
                        .'nicht mehr.',
                        $engine,
                        $mode,
                        $statement,
                    ),
                );
            }
        }

        $this->assertSame(4, $faelle, 'Es werden nicht alle vier Fälle gefahren — dann prüft dieser Test weniger, als er behauptet.');
    }

    /**
     * Die Oberfläche schickt nur, was jemand angefasst hat.
     *
     * **Die Anweisung kann nur weglassen, was ihr nicht gegeben wird.** Regel 2
     * hat deshalb zwei Enden, und dieses ist das obere: Ein Formular, das alle
     * Felder schickt, macht die Prüfung am Agenten wirkungslos, ohne sie zu
     * verletzen.
     */
    public function test_the_form_sends_only_what_was_touched(): void
    {
        $console = $this->console();

        $this->assertSame(
            1,
            preg_match('/async function save\(\).*?\n\}/su', $console, $treffer),
            'Es gibt kein Speichern mehr in der Konsole — dann prüft dieser Test nichts.',
        );

        $this->assertStringContainsString(
            'changedFields',
            $treffer[0],
            'Das Speichern läuft nicht über die geänderten Felder. Ein Formular, das alle schickt, '
            .'schreibt zurück, was es nur angezeigt hat (docs/46 §10.1, Regel 2).',
        );

        $this->assertSame(
            1,
            preg_match('/function isChanged\(field: Field\): boolean \{(.*?)\n\}/su', $console, $geaendert),
            'Es gibt keine Entscheidung mehr darüber, was geändert ist.',
        );

        $this->assertStringContainsString(
            'field.touched',
            $geaendert[1],
            'Was geändert ist, entscheidet sich ohne `touched`. Beim Anlegen gibt es keinen '
            .'Ausgangswert zum Vergleichen — „das Feld ist leer" hiesse dann entweder „schreib `\'\'`" '
            .'oder „lass die Vorgabe gelten", und ein leeres Textfeld hält die beiden nicht auseinander.',
        );

        $this->assertStringContainsString(
            'before.value',
            $geaendert[1],
            'Was geändert ist, entscheidet sich ohne den Ausgangswert. Wer tippt und es zurücknimmt, '
            .'schriebe die Spalte dann trotzdem.',
        );
    }

    /**
     * Ein gekürztes und ein binäres Feld sind gesperrt, mit dem Grund daneben.
     *
     * **Beide haben dieselbe Ursache: Was hier steht, ist nicht der Wert.** Eine
     * binäre Spalte trägt in der Tabelle ihre Länge (`docs/46 §8.2`), eine
     * gekürzte Zelle die ersten 512 Zeichen.
     *
     * **Der Grund gehört dazu.** Ein gesperrtes Feld ohne Begründung ist die
     * Sorte Oberfläche, bei der man zweimal klickt und dann aufgibt — und der
     * Weg zum ganzen Wert (die Zelleinzelsicht) bliebe unentdeckt.
     */
    public function test_a_truncated_or_binary_field_is_locked_with_a_reason(): void
    {
        $console = $this->console();

        $this->assertSame(
            1,
            preg_match('/function lockReason\(.*?\n\}/su', $console, $treffer),
            'Es gibt keinen Grund mehr für ein gesperrtes Feld — dann prüft dieser Test nichts.',
        );

        foreach (['isBinary' => 'binär', 'isTruncated' => 'gekürzt'] as $frage => $wort) {
            $this->assertStringContainsString(
                $frage,
                $treffer[0],
                sprintf(
                    'Ein Feld wird nicht danach gesperrt, ob es „%s" ist. Was dort angezeigt wird, ist '
                    .'nicht der Wert — es zurückzuschreiben wirft den Rest weg (docs/46 §10.1, Regel 1).',
                    $wort,
                ),
            );

            $this->assertStringContainsString(
                $wort,
                $treffer[0],
                sprintf('Der Grund „%s" steht nicht am gesperrten Feld.', $wort),
            );
        }

        // Und die andere Hälfte: Ein gesperrtes Feld hat kein Eingabefeld, also
        // kann es nie `touched` werden — und damit nie in die Anweisung kommen.
        $this->assertMatchesRegularExpression(
            '/v-if="field\.locked === null"/',
            $console,
            'Die Vorlage unterscheidet gesperrte Felder nicht. Ein gesperrtes Feld mit Eingabefeld '
            .'könnte angefasst und damit geschrieben werden — der Grund daneben wäre dann eine Notiz '
            .'und keine Sperre.',
        );
    }

    /**
     * Und `NULL` ist im Formular ein eigener Zustand.
     *
     * **Ein Textfeld kann `NULL` nicht ausdrücken.** Ohne das Kästchen wäre jede
     * leere Eingabe ein `''`, und aus jedem `NULL` einer nullbaren Spalte würde
     * lautlos eine leere Zeichenkette. Bei `NOT NULL` gibt es das Kästchen
     * nicht — dort wäre es eine Zusage, die die Datenbank zurückweist.
     */
    public function test_null_is_its_own_state_in_the_form(): void
    {
        $console = $this->console();

        $this->assertSame(
            1,
            preg_match('/function current\(field: Field\): string \| null \{(.*?)\n\}/su', $console, $treffer),
            'Es gibt keine Umsetzung eines Feldes in einen Wert mehr — dann prüft dieser Test nichts.',
        );

        $this->assertStringContainsString(
            'field.isNull',
            $treffer[1],
            'Der Wert eines Feldes entsteht ohne den `NULL`-Zustand. Eine leere Eingabe wäre dann `\'\'`, '
            .'und aus jedem `NULL` einer nullbaren Spalte würde lautlos eine leere Zeichenkette '
            .'(docs/46 §10.1).',
        );

        $this->assertMatchesRegularExpression(
            '/v-if="nullable\(field\.column\)"/',
            $console,
            'Das `NULL`-Kästchen steht an jedem Feld oder an keinem. Bei einer Spalte mit `NOT NULL` '
            .'ist es eine Zusage, die die Datenbank zurückweist.',
        );
    }

    private function console(): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/|<!--.*?-->#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Databases/Console.vue'),
        );
    }
}
