<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Was die Seite sagt, holt sich ins Bild.
 *
 * ## Der Fund auf dem Telefon
 *
 * Der Betreiber hat am 15. August 2026 „Entfernen" an einer Zeile weit unten
 * gedrückt (`docs/55`, Befund 19). Die Rückfrage erscheint oben auf der Seite
 * (`docs/19 §6`) — **und sichtbar geschah nichts.** Wer nicht von selbst nach
 * oben scrollt, hält den Knopf für kaputt.
 *
 * > **Eine Antwort, die ausserhalb des Bildes steht, ist für den Fragenden
 * > keine.**
 *
 * Das ist zum **dritten Mal** derselbe Eindruck in zwei Tagen: Befund 15 (der
 * `prompt`, den Safari abschaltet), Befund 16 (dasselbe für `confirm`) und jetzt
 * eine Antwort, die zwar da ist, aber nicht dort, wo jemand hinsieht.
 *
 * ## Und die Fehlerzusammenfassung hatte es genauso
 *
 * `FormErrors` steht oben, weil „die Seite nach der Antwort ohnehin nach oben
 * springt". Das stimmt — **ausser bei `preserveScroll: true`**, und allein
 * `Files/Index.vue` setzt es an zehn Griffen, weil eine Liste, die nach jedem
 * Klick nach oben springt, unbrauchbar wäre.
 *
 * > **Eine Regel, die sich auf ein Verhalten des Frameworks stützt, gilt nur
 * > dort, wo dieses Verhalten eingeschaltet ist.**
 */
final class InViewTest extends TestCase
{
    /**
     * Bausteine, die eine Antwort der Seite zeichnen — jeder mit seinem Grund.
     *
     * @var array<string, string>
     */
    private const SPEAKS = [
        'resources/js/Components/Confirmation.vue' => 'Die Rückfrage vor einer Handlung, die etwas kostet.',
        'resources/js/Components/FormErrors.vue' => 'Die Zusammenfassung dessen, was nicht gespeichert wurde.',
    ];

    public function test_every_block_that_speaks_brings_itself_into_view(): void
    {
        foreach (self::SPEAKS as $datei => $grund) {
            $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/'.$datei);

            $this->assertStringContainsString(
                'bringIntoView(',
                $quelle,
                sprintf(
                    "`%s` holt sich nicht mehr ins Bild.\n\n%s\n\n".
                    'Steht der Baustein ausserhalb des sichtbaren Bereichs — und mit `preserveScroll` '.
                    'tut er das oft —, sieht der Klick aus, als hätte er nichts bewirkt.',
                    $datei,
                    $grund,
                ),
            );

            $this->assertStringContainsString(
                'tabindex="-1"',
                $quelle,
                sprintf(
                    "`%s` nimmt keinen Fokus an.\n\n".
                    'Dann springt zwar das Bild, aber der Tastaturweg dorthin fehlt — und für '.
                    'jemanden, der die Seite hört, ist gar nichts geschehen.',
                    $datei,
                ),
            );
        }
    }

    /**
     * Und der Fokus landet nicht auf dem Knopf, der die Handlung auslöst.
     *
     * Eine Rückfrage, die „Entfernen" fokussiert, macht aus einem Druck auf die
     * Leertaste die Handlung, die gerade erst erfragt wurde.
     *
     * > **Eine Rückfrage, deren Antwort schon vorausgewählt ist, ist keine.**
     */
    public function test_the_focus_lands_on_the_block_and_not_on_its_button(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/scroll.ts');

        $this->assertStringContainsString(
            'element.focus(',
            $quelle,
            'Der Fokus wandert gar nicht mit — dann fehlt der Tastaturweg zur Antwort.',
        );

        $this->assertStringNotContainsString(
            'querySelector(\'button\')',
            $quelle,
            'Der Fokus sucht sich einen Knopf. Bei einer Rückfrage wäre das der, der zerstört.',
        );
    }

    /**
     * Und gescrollt wird nur, wenn wirklich etwas fehlt.
     *
     * **Ohne diese Bedingung reisst jede Meldung die Seite herum**, auch die,
     * die längst im Bild steht — auf einem Bildschirm mit 1440px ist das fast
     * immer der Fall, und der Sprung wäre dort ein Fehler statt einer Hilfe.
     */
    public function test_it_only_scrolls_when_something_is_out_of_view(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/scroll.ts');

        $this->assertStringContainsString(
            'getBoundingClientRect()',
            $quelle,
            'Es wird nicht mehr nachgesehen, ob der Baustein überhaupt ausserhalb des Bildes steht.',
        );

        $this->assertMatchesRegularExpression(
            '/if \(! fullyVisible\(element\)\) \{/',
            $quelle,
            "Gescrollt wird ohne Bedingung.\n\n".
            'Dann springt die Seite auch dann, wenn die Meldung schon zu sehen ist.',
        );
    }

    /** @see ValidationLanguageTest::test_every_exemption_carries_a_reason */
    public function test_every_listed_block_carries_a_reason(): void
    {
        foreach (self::SPEAKS as $datei => $grund) {
            $this->assertNotSame(
                '',
                trim($grund),
                sprintf('`%s` steht ohne Grund in der Liste.', $datei),
            );

            $this->assertFileExists(dirname(__DIR__, 2).'/'.$datei);
        }
    }
}
