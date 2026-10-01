<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Wo „SrvPanel" stand, steht der Name der Marke (B6, `docs/140 §0` Punkte 5
 * und 6).
 *
 * Der Betreiber kann den Namen einstellen. Bis zum 1. Oktober 2026 stand er an
 * drei Orten trotzdem als Wort: im Titel des Reiters, im Namen des Zeichens für
 * einen Vorleser und im Betreff jeder Mail. Gesehen hat das keiner der Fälle,
 * die es damals gab. `BrandReachTest` las den Titel, den der Server schreibt,
 * und den Rumpf der Mail und nicht ihren Betreff.
 *
 * > **Ein Name, den der Betreiber einstellen kann, steht überall dort falsch,
 * > wo ihn jemand als Wort hingeschrieben hat.**
 *
 * Gelesen wird ohne Kommentare: Die Kommentare an diesen Stellen erzählen, was
 * vorher dastand, und nennen dabei das Wort.
 *
 * **Was dieser Wächter nicht kann:** sagen, ob der Name im Browser ankommt.
 * Das misst `BrandReachTest` an den Mails und der Abnahmelauf `docs/140` am
 * Reiter. Hier steht, dass der Quelltext ihn nicht mehr als Wort hinschreibt.
 */
final class BrandNameTest extends TestCase
{
    use WithoutMarkupComments;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Der Titel-Rückruf nimmt den Namen aus den Daten der Seite.
     *
     * Inertia ersetzt den Titel des Servers, sobald es startet, und reicht dem
     * Rückruf die aktuelle Seite als zweiten Wert mit. Ein Rückruf ohne den
     * zweiten Wert kennt die Marke nicht.
     */
    public function test_the_tab_title_takes_the_name_of_the_brand(): void
    {
        $quelle = $this->withoutMarkupComments((string) file_get_contents($this->root().'/resources/js/app.ts'));

        self::assertSame(1, preg_match('/\btitle:\s*\(\s*\w+\s*,\s*(\w+)\s*\)\s*=>\s*\{(.*?)\n  \},/s', $quelle, $rueckruf),
            "Der Titel-Rückruf in app.ts nimmt die Seite nicht als zweiten Wert.\n\n"
            .'Dann kennt er die Marke nicht, und im Reiter steht, was er als Wort hinschreibt.');

        self::assertMatchesRegularExpression('/\b'.preg_quote($rueckruf[1], '/').'\??\.props\??\.brand\b/', $rueckruf[2],
            'Der Titel-Rückruf liest den Namen nicht aus den geteilten Daten der Seite.');

        self::assertDoesNotMatchRegularExpression('/·\s*SrvPanel/u', $rueckruf[2],
            'Im Titel-Rückruf steht hinter dem Trenner wieder das feste Wort.');
    }

    /**
     * Das Zeichen neben dem Namen ist für einen Vorleser Schmuck, und neben
     * ihm steht der Name.
     *
     * Mit `aria-label="SrvPanel"` las ein Vorleser „SrvPanel Muster Hosting"
     * und ohne Marke „SrvPanel SrvPanel". Wer das Zeichen ohne den Namen
     * einsetzt, braucht wieder einen Namen dafür; die zweite Hälfte dieses
     * Falls meldet die Stelle.
     */
    public function test_the_mark_beside_the_name_is_decoration(): void
    {
        $zeichen = $this->withoutMarkupComments(
            (string) file_get_contents($this->root().'/resources/js/Components/MarkIcon.vue'),
        );

        self::assertSame(1, preg_match('/<svg\b[^>]*>/s', $zeichen, $svg), 'Kein <svg> in MarkIcon.vue — dann misst dieser Fall nichts.');
        self::assertStringContainsString('aria-hidden="true"', $svg[0], 'Das Zeichen ist für einen Vorleser nicht verborgen.');
        self::assertStringNotContainsString('aria-label', $svg[0], 'Das Zeichen trägt wieder einen eigenen Namen.');

        $ohneName = [];
        $nutzer = 0;

        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/resources/js'));

        foreach ($dateien as $datei) {
            if (! $datei instanceof \SplFileInfo || $datei->getExtension() !== 'vue') {
                continue;
            }

            $quelle = $this->withoutMarkupComments((string) file_get_contents($datei->getPathname()));

            if (preg_match('/<MarkIcon\b/', $quelle) !== 1) {
                continue;
            }

            $nutzer++;

            if (! str_contains($quelle, 'class="brand-name"')) {
                $ohneName[] = str_replace($this->root().'/', '', $datei->getPathname());
            }
        }

        self::assertGreaterThan(0, $nutzer, 'Untergrenze: Niemand setzt das Zeichen ein — dann misst dieser Fall nichts.');
        self::assertSame([], $ohneName, sprintf(
            "Diese Vorlagen setzen das Zeichen ohne den Namen daneben ein:\n  %s\n\n"
            .'Für einen Vorleser steht dort dann nichts.',
            implode("\n  ", $ohneName),
        ));
    }

    /**
     * Keine Mail schreibt den eingebauten Namen als Wort.
     *
     * Gesucht wird in den Zeichenketten der Mail-Klassen und der Klasse, die
     * den Betreff bildet, und in den Vorlagen unter `mail/`. Ein `use
     * SrvPanel\…` ist ein Namensraum und kein Text; er steht in keiner
     * Zeichenkette.
     */
    public function test_no_mail_writes_the_shipped_name(): void
    {
        $klassen = array_merge(
            glob($this->root().'/app/Mail/*.php') ?: [],
            [$this->root().'/app/Support/Brand/MailSubject.php'],
        );
        $vorlagen = glob($this->root().'/resources/views/mail/*.blade.php') ?: [];

        self::assertGreaterThanOrEqual(4, count($klassen), 'Untergrenze: drei Mails und die Klasse für den Betreff.');
        self::assertGreaterThanOrEqual(4, count($vorlagen), 'Untergrenze: drei Mails und ihre Unterschrift.');

        $funde = [];

        foreach ($klassen as $pfad) {
            foreach (token_get_all((string) file_get_contents($pfad)) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && str_contains($token[1], 'SrvPanel')) {
                    $funde[] = sprintf('%s:%d %s', basename($pfad), $token[2], $token[1]);
                }
            }
        }

        foreach ($vorlagen as $pfad) {
            $vorlage = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($pfad));

            if (str_contains($vorlage, 'SrvPanel')) {
                $funde[] = basename($pfad);
            }
        }

        self::assertSame([], $funde, sprintf(
            "Hier steht der eingebaute Name als Wort:\n  %s\n\n"
            .'Den Namen kann der Betreiber einstellen; im Betreff kommt er aus MailSubject::of().',
            implode("\n  ", $funde),
        ));
    }
}
