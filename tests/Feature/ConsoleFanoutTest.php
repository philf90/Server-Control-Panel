<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Kein Weg in der Oberfläche fragt den Katalog für mehr als eine Tabelle.
 *
 * **Warum das eine Regel ist und keine Empfehlung.** Jede Katalogfrage der
 * Konsole läuft unter einem **befristeten Zugang**: Der Agent legt eine
 * Datenbankrolle an, fragt, und räumt sie ab (`docs/46 §11.1`). In PostgreSQL
 * sind das gemessene 11 ms; in MariaDB kommt ein Neuladen der Rechtetabellen
 * dazu und ist ungemessen. Eine Schleife über zwanzig Tabellen wären zwanzig
 * davon — für eine Ansicht, die niemand angefordert hat.
 *
 * > **Ein Bedienelement, das zwanzig Datenbankrollen anlegt, sieht aus wie ein
 * > Komfort.**
 *
 * Der Baum ist genau deshalb so gebaut, dass **Aufklappen nichts holt**: Die
 * drei Ziele darunter sind Beschriftungen. Geholt wird erst, wenn jemand eines
 * wählt — für eine Tabelle.
 */
final class ConsoleFanoutTest extends TestCase
{
    private function console(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Databases/Console.vue',
        );
    }

    /**
     * Der Rumpf einer Funktion.
     *
     * Grob und absichtlich so: gesucht wird der Text von der Signatur bis zur
     * schliessenden Klammer auf derselben Einrückung. Ein Parser dafür wäre eine
     * zweite Fassung von TypeScript.
     */
    private function body(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name.'(');

        $this->assertIsInt($start, sprintf('Es gibt keine Funktion %s() mehr — dann prüft dieser Wächter nichts.', $name));

        $rest = substr($source, $start);
        $end = strpos($rest, "\n}");

        return $end === false ? $rest : substr($rest, 0, $end);
    }

    /**
     * Aufklappen holt nichts.
     *
     * Die schärfste Fassung der Regel: Nicht „der Baum lädt sparsam", sondern
     * **beim Aufklappen fällt keine Anfrage an**. Wer das ändert, macht aus
     * jedem Klick auf ein Dreieck einen befristeten Datenbankzugang.
     */
    public function test_expanding_a_branch_asks_nothing(): void
    {
        $toggle = $this->body($this->console(), 'toggle');

        $this->assertStringNotContainsString(
            'ask',
            $toggle,
            'Das Aufklappen eines Zweiges stellt eine Anfrage. Jede Katalogfrage ist ein befristeter '
            .'Datenbankzugang; die drei Ziele unter einem Zweig sind Beschriftungen und keine Daten '
            .'(docs/46 §11.1).',
        );
    }

    /**
     * Keine Anfrage steht in einer Schleife über die Tabellen.
     *
     * Geprüft wird der Zusammenhang und nicht das Wort: Eine Schleife über
     * `tables` ist erlaubt — die Vorlage braucht sie, um den Baum zu zeichnen.
     * Verboten ist, dass in ihr eine Anfrage steht.
     */
    public function test_no_request_stands_inside_a_loop_over_the_tables(): void
    {
        $source = $this->console();

        $anfragen = preg_match_all('/\bask</', $source);

        $this->assertGreaterThanOrEqual(
            3,
            $anfragen,
            'Es werden kaum Anfragen gefunden — dann rechnet dieser Wächter an nichts nach.',
        );

        preg_match_all(
            '/(?:tables(?:\.value)?\.(?:map|forEach|flatMap)\(|for\s*\((?:const|let)\s+\w+\s+of\s+tables)(.{0,400})/su',
            $source,
            $schleifen,
        );

        foreach ($schleifen[1] as $rumpf) {
            $this->assertStringNotContainsString(
                'ask',
                $rumpf,
                'In einer Schleife über die Tabellenliste steht eine Anfrage. Zwanzig Tabellen wären '
                .'zwanzig befristete Datenbankzugänge (docs/46 §14.9).',
            );
        }
    }

    /**
     * Und es gibt kein Bedienelement, das alles auf einmal öffnet.
     *
     * **Der Knopf, den man aus Freundlichkeit einbaut.** „Alles aufklappen" ist
     * die naheliegendste Ergänzung an einem Baum und in dieser Konsole die
     * teuerste: Er legt so viele Datenbankrollen an, wie die Datenbank Tabellen
     * hat — und sieht dabei aus wie ein Komfort.
     *
     * **Gesucht wird nur im Sichtbaren, und das war beim ersten Lauf rot.** Der
     * Quelltext erklärt an der Aufklapp-Funktion, warum es diesen Knopf nicht
     * gibt — und genau dieser Satz hat den Wächter ausgelöst.
     *
     * > **Ein Wächter, der Kommentare liest, bestraft das Dokumentieren genau
     * > des Fehlers, vor dem er schützt.** (Derselbe Fund wie in
     * > `PackagingTest`, nur in einer anderen Sprache.)
     */
    public function test_there_is_no_control_that_opens_everything(): void
    {
        $source = $this->console();

        // Erst die Kommentare heraus — beide Sorten, denn diese Datei hat
        // HTML-Kommentare in der Vorlage und Blockkommentare im Skript.
        $sichtbar = (string) preg_replace(
            ['/<!--.*?-->/su', '#/\*.*?\*/#su', '#(?<![:\'"])//[^\n]*#'],
            '',
            $source,
        );

        $this->assertStringContainsString(
            'aria-expanded',
            $sichtbar,
            'Nach dem Entfernen der Kommentare ist von der Vorlage nichts übrig — dann prüft dieser '
            .'Test nichts mehr.',
        );

        $this->assertSame(
            0,
            preg_match('/\ball(?:e|es)\s+(?:auf|zu)?klappen|\ball(?:e|es)\s+(?:öffnen|laden|holen)/iu', $sichtbar),
            'Die Konsole hat ein Bedienelement, das alles auf einmal öffnet. Es legt so viele '
            .'Datenbankrollen an, wie die Datenbank Tabellen hat (docs/46 §11.1).',
        );
    }
}
