<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Das Protokoll der Konsole führt, **wer** und **welche Zeile** — nicht **was**.
 *
 * ## Zwei Regeln, und die zweite sieht man nicht
 *
 * **1. Kein Eintrag trägt einen Zellenwert** (`docs/46 §3`, Entscheidung 4). Ein
 * Protokoll, das den Inhalt einer geänderten Zeile führt, ist eine zweite Kopie
 * der Kundendaten an einer Stelle, an der sie niemand vermutet — und sie
 * überlebt das Löschen der Zeile.
 *
 * > **Ein Protokoll, das den Inhalt mitschreibt, ist eine Datenhaltung mit einem
 * > anderen Namen.**
 *
 * Der **Schlüssel** gehört ausdrücklich hinein: Er sagt, *welche* Zeile es war,
 * und das ist die Frage, die das Protokoll beantworten soll. Die geänderten
 * Werte sagen *worauf*, und das ist die, die es nicht beantworten soll.
 *
 * **2. Der Eintrag beim Öffnen ist entprellt** — einer je Datenbank und Stunde
 * (Entscheidung 5, Punkt 4).
 *
 * ## Warum die zweite die ist, die einen Wächter braucht
 *
 * **Ein Eintrag entsteht sichtbar.** Wer die Konsole öffnet und ins Protokoll
 * sieht, bemerkt sofort, ob eine Zeile dasteht. Eine **fehlende** Entprellung
 * bemerkt niemand: Sie sieht beim ersten Mal genauso aus und fällt erst nach
 * einer Woche auf, wenn das Protokoll nur noch aus Konsolenzeilen besteht — also
 * genau dann, wenn es gebraucht wird und nichts mehr hergibt.
 *
 * > **Ein Fehler, der beim ersten Mal richtig aussieht, hat keinen Finder.**
 *
 * ## Warum dieser Wächter Text liest
 *
 * Er misst am **Aufruf** und nicht an der Datenbank: Dieser Container hat kein
 * Laravel, und die Regel ist eine Eigenschaft dessen, was der Griff dem
 * Protokolldienst übergibt. Dieselbe Bauform wie `WriteBackTest` — und aus
 * demselben Grund richtig: Der Schaden ist gerade der, den man am Ergebnis nicht
 * sieht.
 */
final class AuditContentTest extends TestCase
{
    private function controller(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/DatabaseController.php',
        );
    }

    /**
     * Der Griff, der schreibt — ohne Kommentare.
     *
     * **Kommentare fliegen raus, weil dieser Griff sich in einem erklärt, der
     * das Wort `values` nennt.** Ein Wächter, der sie liest, wird von der
     * Dokumentation des Fehlers beruhigt, vor dem er schützt — oder von ihr
     * beschuldigt; beides ist hier schon passiert (`docs/46 §20.38`).
     */
    private function write(): string
    {
        $source = (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            $this->controller(),
        );

        $this->assertSame(
            1,
            preg_match('/public function consoleWrite\(.*?\n    \}/su', $source, $treffer),
            'Es gibt keinen Schreibgriff mehr in der Konsole — dann prüft dieser Test nichts.',
        );

        return $treffer[0];
    }

    /**
     * Die drei ändernden Handlungen stehen im Protokoll.
     *
     * **Drei Namen und nicht einer mit der Art im Kontext.** Wer nach „hier
     * wurde gelöscht" sucht, filtert nach einer Aktion und nicht nach einem Feld
     * in einem JSON — so machen es die vorhandenen Namen dieser Fläche auch.
     */
    public function test_all_three_changing_actions_are_recorded(): void
    {
        $source = $this->controller();

        $this->assertSame(
            1,
            preg_match('/CONSOLE_WRITE_ACTIONS = \[(.*?)\];/su', $source, $treffer),
            'Es gibt keine Liste der ändernden Handlungen mehr — dann prüft dieser Test nichts.',
        );

        foreach (['insert', 'update', 'delete'] as $art) {
            $this->assertMatchesRegularExpression(
                "/'".$art."' => 'database\.console\.row\.\w+'/",
                $treffer[1],
                sprintf(
                    'Für `%s` steht keine eigene Protokollaktion in der Liste. Die drei ändernden '
                    .'Handlungen gehören ins Protokoll (docs/46 §3, Entscheidung 4).',
                    $art,
                ),
            );
        }

        $this->assertStringContainsString(
            'CONSOLE_WRITE_ACTIONS[$mode]',
            $this->write(),
            'Der Schreibgriff benutzt die Liste der Aktionen nicht. Ein Name, der danebensteht, ist '
            .'eine zweite Fassung — und die zweite ist die, die veraltet.',
        );
    }

    /**
     * Kein Eintrag trägt einen Zellenwert.
     *
     * **Der Schlüssel ist erlaubt und die Werte sind es nicht.** Der Test prüft
     * beide Richtungen, denn eine davon allein wäre zu umgehen: Wer nur `values`
     * verbietet, kann sie unter einem anderen Namen mitgeben; wer nur `key`
     * verlangt, hat nichts über den Rest gesagt.
     */
    public function test_no_entry_carries_a_cell_value(): void
    {
        $write = $this->write();

        $this->assertSame(
            1,
            preg_match('/context: array_filter\(\[(.*?)\],/su', $write, $treffer),
            'Der Schreibgriff gibt keinen Kontext mehr mit — dann prüft dieser Test nichts.',
        );

        $kontext = $treffer[1];

        foreach (['table', 'key'] as $muss) {
            $this->assertStringContainsString(
                "'".$muss."' =>",
                $kontext,
                sprintf(
                    'Der Kontext nennt `%s` nicht. Ohne ihn sagt der Eintrag nicht, **welche** Zeile '
                    .'geändert wurde — und genau das ist die Frage, die das Protokoll beantworten '
                    .'soll (docs/46 §3, Entscheidung 4).',
                    $muss,
                ),
            );
        }

        foreach (['values', 'value', 'before', 'after'] as $darf_nicht) {
            $this->assertStringNotContainsString(
                "'".$darf_nicht."' =>",
                $kontext,
                sprintf(
                    "Der Kontext des Protokolleintrags trägt `%s`:\n\n%s\n\n"
                    .'Ein Protokoll, das den Inhalt einer geänderten Zeile führt, ist eine zweite '
                    .'Kopie der Kundendaten an einer Stelle, an der sie niemand vermutet — und sie '
                    .'überlebt das Löschen der Zeile.',
                    $darf_nicht,
                    trim($kontext),
                ),
            );
        }
    }

    /**
     * Und der Eintrag beim Öffnen ist entprellt — einer je Stunde.
     *
     * **Dieser Test nennt die Zahl**, und das ist hier ausnahmsweise richtig:
     * Sonst gilt in diesem Projekt, dass ein Kriterium, das nach einer Anzahl
     * fragt, nicht prüft, *was* gezählt wurde (`docs/46 §20.x`, aus P4). Hier
     * **ist** die Anzahl die Regel — „einer je Datenbank und Stunde" ist der
     * Wortlaut von Entscheidung 5, Punkt 4.
     */
    public function test_the_entry_on_opening_is_debounced_to_one_per_hour(): void
    {
        $source = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $this->controller());

        $this->assertSame(
            1,
            preg_match('/public function console\(Request.*?\n    \}/su', $source, $treffer),
            'Es gibt keinen Einstieg in die Konsole mehr — dann prüft dieser Test nichts.',
        );

        $this->assertStringContainsString(
            'throttled(',
            $treffer[0],
            'Der Einstieg in die Konsole protokolliert ohne Entprellung. Die Konsole wird beim '
            .'Arbeiten mehrfach betreten und verlassen; ohne sie besteht das Protokoll nach einer '
            .'Woche nur noch aus Konsolenzeilen (docs/46 §3, Entscheidung 5, Punkt 4).',
        );

        $this->assertStringContainsString(
            'CONSOLE_AUDIT_SECONDS',
            $treffer[0],
            'Die Spanne der Entprellung steht als Zahl im Griff statt als Konstante mit Namen.',
        );

        $this->assertSame(
            1,
            preg_match('/CONSOLE_AUDIT_SECONDS = (\d+);/', $source, $spanne),
            'Es gibt keine Spanne für die Entprellung mehr.',
        );

        $this->assertSame(
            3600,
            (int) $spanne[1],
            sprintf(
                'Die Entprellung läuft über %s Sekunden und nicht über eine Stunde. „Einer je '
                .'Datenbank und Stunde" ist der Wortlaut von Entscheidung 5, Punkt 4 — hier ist die '
                .'Zahl ausnahmsweise selbst die Regel.',
                $spanne[1],
            ),
        );
    }

    /**
     * Die Entprellung fragt nach Aktion, Ziel **und** handelnder Person.
     *
     * **Ohne die dritte Bedingung verschluckt sie den Fall, für den man das
     * Protokoll liest.** Sieht ein Admin über „Anmelden als" in dieselbe
     * Datenbank, in der der Kunde gerade war, gehört das in einen eigenen
     * Eintrag — sonst schweigt das Protokoll genau dort.
     */
    public function test_the_debounce_asks_who_and_not_only_what(): void
    {
        $audit = (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/app/Support/Audit/Audit.php'),
        );

        $this->assertSame(
            1,
            preg_match('/public function throttled\(.*?\n    \}/su', $audit, $treffer),
            'Es gibt keine entprellte Aufzeichnung mehr — dann prüft dieser Test nichts.',
        );

        foreach (['action', 'target_type', 'target_id', 'account_id', 'created_at'] as $spalte) {
            $this->assertStringContainsString(
                "'".$spalte."'",
                $treffer[0],
                sprintf(
                    'Die Entprellung fragt nicht nach `%s`. Ohne Aktion, Ziel, handelnde Person und '
                    .'Zeitspanne unterdrückt sie entweder zu viel oder zu wenig — und beides sieht '
                    .'beim ersten Eintrag gleich aus.',
                    $spalte,
                ),
            );
        }
    }
}
