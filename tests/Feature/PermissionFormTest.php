<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Rechte werden geführt eingestellt und nicht als Zahl abgefragt.
 *
 * ## Der Anlass
 *
 * Bis zum 14. August 2026 fragte der Dateimanager mit einem `window.prompt`
 * nach einer Oktalzahl. Der Betreiber hat es in der Zwischenabnahme gemeldet
 * (`docs/53`, Befund 8):
 *
 * > **Eine Eingabe, die eine Zahl verlangt, die man auswendig können muss, ist
 * > keine Bedienung, sondern eine Prüfung.**
 *
 * Dazu kam, dass ein Browserdialog keine Farbe aus `app.css` nimmt, keine
 * Schrift und keinen Abstand — er stand als schwarzer Systemkasten mitten in
 * einem hellen Panel.
 *
 * ## Was hier festgehalten wird
 *
 * Drei Dinge, und das dritte ist das eigentliche:
 *
 * 1. Die Zahl wird nicht über einen Systemdialog erfragt.
 * 2. `setuid`, `setgid` und das Sticky-Bit werden nicht angeboten.
 * 3. **Der erklärende Satz unterscheidet Datei und Verzeichnis** — dasselbe Bit
 *    heisst dort etwas anderes, und ein Verzeichnis ohne `x` ist der häufigste
 *    selbstgemachte Fehler dieser Art.
 */
final class PermissionFormTest extends TestCase
{
    private function editor(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Components/PermissionEditor.vue',
        );
    }

    private function listing(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Files/Index.vue',
        );
    }

    /**
     * Die Rechte kommen aus dem Baustein und nicht aus einem Systemdialog.
     *
     * Gefragt wird nach beidem: dass der Baustein benutzt wird **und** dass der
     * alte Weg fort ist. Nur das erste zu prüfen liesse zu, dass beide
     * nebeneinander stehen — und dann entscheidet die Reihenfolge im Code, was
     * der Kunde sieht.
     */
    public function test_the_mode_is_not_asked_for_in_a_browser_dialog(): void
    {
        $quelle = $this->listing();

        $this->assertStringContainsString(
            '<PermissionEditor',
            $quelle,
            'Der Dateimanager benutzt den geführten Baustein für die Rechte nicht.',
        );

        $this->assertStringNotContainsString(
            'parseInt(wanted, 8)',
            $quelle,
            'Die Rechte werden weiter als Oktalzahl aus einem Systemdialog gelesen. '.
            'Eine Eingabe, die eine Zahl verlangt, die man auswendig können muss, ist keine '.
            'Bedienung, sondern eine Prüfung.',
        );
    }

    /**
     * Und der Baustein gestaltet sich nicht selbst.
     *
     * Ein Hexwert in einer Komponente ist in diesem Projekt ein Fehler und
     * keine Ausnahme — `DesignTokensTest` prüft das für alle. Hier steht
     * zusätzlich, dass die neun Kästchen **dieselbe** Klasse tragen wie jedes
     * andere Kästchen des Panels: Eine Komponente, die ihr eigenes `input`
     * gestaltet, ist derselbe Fehler eine Ebene höher.
     */
    public function test_the_checkboxes_are_the_ordinary_ones(): void
    {
        $this->assertStringContainsString(
            'class="toggle"',
            $this->editor(),
            'Die Kästchen tragen nicht `.toggle`, also gestaltet dieser Baustein sein eigenes '.
            'Kontrollkästchen.',
        );
    }

    /**
     * `setuid`, `setgid` und Sticky werden nicht angeboten.
     *
     * Das ist eine Entscheidung und kein Vergessen: Ihre Wirkung lässt sich in
     * einer Zeile nicht ehrlich erklären, und ein `setuid` auf eine Kundendatei
     * ist nichts, wozu eine Oberfläche einladen soll. Wer sie braucht, hat
     * SFTP.
     *
     * Geprüft wird an den **Vorlagen**: Keine darf über neun Bits hinausgehen.
     */
    public function test_the_presets_stay_within_nine_bits(): void
    {
        preg_match_all('/\{ mode: 0o(\d+),/', $this->editor(), $treffer);

        $this->assertNotEmpty($treffer[1], 'Es werden gar keine Vorlagen gefunden — dann prüft dieser Wächter nichts.');

        foreach ($treffer[1] as $oktal) {
            $mode = (int) octdec($oktal);

            $this->assertSame(
                0,
                $mode & ~0o777,
                sprintf(
                    'Die Vorlage `%s` setzt ein Bit ausserhalb der neun. setuid, setgid und das '.
                    'Sticky-Bit werden hier nicht angeboten — ihre Wirkung lässt sich in einer '.
                    'Zeile nicht ehrlich erklären.',
                    $oktal,
                ),
            );
        }
    }

    /**
     * Der erklärende Satz unterscheidet Datei und Verzeichnis.
     *
     * **Das ist der Punkt, wegen dem dieser Baustein überhaupt gebaut wurde.**
     * Neun Kästchen und eine Zahl sind bequemer als ein `prompt` und erklären
     * genauso wenig. Was erklärt, ist der Satz darunter — und er wäre falsch,
     * wenn er `x` bei einem Ordner „ausführbar" nennt.
     *
     * Ein Verzeichnis ohne `x` sperrt seinen Eigentümer aus, und das sieht aus
     * wie ein Serverfehler. Deshalb wird auch nach dem Warnsatz gefragt.
     */
    public function test_the_explanation_knows_what_it_is_talking_about(): void
    {
        $quelle = $this->editor();

        $this->assertStringContainsString(
            'betreten',
            $quelle,
            'Der erklärende Satz kennt den Unterschied zwischen einer ausführbaren Datei und '.
            'einem betretbaren Verzeichnis nicht.',
        );

        $this->assertStringContainsString(
            'props.isDirectory',
            $quelle,
            'Die Erklärung fragt gar nicht, ob der Eintrag ein Verzeichnis ist.',
        );

        $this->assertStringContainsString(
            'lässt sich der Ordner nicht öffnen',
            $quelle,
            'Ein Ordner ohne „Ausführen" sperrt seinen Eigentümer aus, und die neun Kästchen '.
            'zeigen das nicht. Ohne den Warnsatz sieht der Kunde zwei gesetzte Haken und nicht, '.
            'dass der eine den anderen braucht.',
        );
    }

    /**
     * Und der Satz über den Webserver hängt an der Gruppe.
     *
     * Seit `httpdocs` setgid trägt (Schritt 6c), gehört alles darin der Gruppe
     * `www-data`, und der Webserver liest darüber. Hinge der Satz weiter am
     * **Weltbit**, wäre er die Auskunft von vor dem Umbau — und damit genau die
     * Halbwahrheit, die Befund 3 ausgemacht hat.
     */
    public function test_the_sentence_about_the_webserver_follows_the_group(): void
    {
        $quelle = $this->editor();

        $this->assertStringContainsString(
            'props.served',
            $quelle,
            'Der Baustein entscheidet selbst, ob ein Verzeichnis ausgeliefert wird. Das weiss '.
            'nur der Server: `httpdocs` ist der DocumentRoot der Hauptdomain, jede weitere '.
            'heisst wie ihre Domain.',
        );

        $this->assertStringContainsString(
            'has(3, 4)',
            $quelle,
            'Der Satz über den Webserver hängt nicht am Gruppenbit. Am Weltbit wäre er die '.
            'Auskunft von vor Schritt 6c.',
        );
    }

    /**
     * Und der Server nennt die ausgelieferten Verzeichnisse.
     *
     * Ohne diese Angabe müsste die Seite `httpdocs` hineinschreiben — richtig
     * für die Hauptdomain, falsch für jede weitere.
     *
     * > **Ein Satz, der an einer Stelle stimmt und an der nächsten nicht, ist
     * > schlechter als kein Satz.**
     */
    public function test_the_server_names_the_served_directories(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/FileController.php',
        );

        $this->assertStringContainsString(
            "'documentRoots' =>",
            $controller,
            'Der Controller schickt die ausgelieferten Verzeichnisse nicht mit.',
        );

        $this->assertStringContainsString(
            'documentRoots: string[]',
            $this->listing(),
            'Die Seite nimmt die ausgelieferten Verzeichnisse nicht entgegen.',
        );
    }
}
