<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Der Fortschrittsbalken trägt die Farbe dieses Panels.
 *
 * ## Der Befund, den es dazu gibt
 *
 * Er war blau, und zwar seit es `app.ts` gibt. Ohne eine Angabe gilt Inertias
 * Voreinstellung — gemessen im Bündel von `@inertiajs/core`:
 * `delay = 250, color = "#29d", includeCSS = true, showSpinner = false`. Damit
 * stand auf **jeder** Seite dieses Panels ein Hexwert, den `app.css` nicht
 * kennt und den `DesignTokensTest` nie sehen konnte:
 *
 * > **Ein Wächter über den Quelltext sieht keine Farbe, die das Framework zur
 * > Laufzeit einsetzt.**
 *
 * Sie kommt aus einem `<style>`, das die Bibliothek beim Start ins Dokument
 * schreibt. Kein Ausdruck über `resources/` hätte sie gefunden — nur das
 * Fehlen der Angabe verrät sie, und genau das hält dieser Wächter.
 *
 * ## Was er nicht kann
 *
 * Er sagt nicht, ob die Farbe im Browser ankommt. Ob `var(--accent)` in der
 * eingespritzten Regel wirklich auflöst, misst Punkt 5 des Abnahmelaufs über
 * `getComputedStyle`; hier steht, was ohne einen Browser zu halten ist.
 */
final class ProgressColourTest extends TestCase
{
    use WithoutPhpComments;

    /** Wo der Einstieg steht. */
    private const ENTRY = 'resources/js/app.ts';

    /** Wo das Stylesheet steht. */
    private const STYLESHEET = 'resources/css/app.css';

    /**
     * Die Angabe steht da — sonst gilt die Voreinstellung der Bibliothek.
     *
     * **Ihr Fehlen ist der Fehler und nicht ein falscher Wert.** Ein Balken
     * ohne `progress:` sieht auf jeder Seite genauso aus wie einer mit einer
     * falschen Farbe; unterscheiden lässt sich beides nur hier.
     */
    public function test_the_bar_is_configured_at_all(): void
    {
        $this->assertMatchesRegularExpression(
            '/\bprogress\s*:\s*\{/',
            $this->entry(),
            'Der Fortschrittsbalken bekommt keine Angabe mehr. Damit gilt Inertias '
            .'Voreinstellung `#29d` — ein Blau, das `app.css` nicht kennt, auf jeder Seite.',
        );
    }

    /**
     * Und die Farbe kommt aus dem Gestaltungssystem.
     *
     * **`var(--…)` und kein gelesener Wert.** Die Marke wird **am Element**
     * aufgelöst und folgt damit dem Thema; ein über `getComputedStyle`
     * gelesener Wert wäre der des Themas beim Start und bliebe beim Umschalten
     * stehen. Eine Quelle, drei Themen, keine Zeile Pflege.
     */
    public function test_the_colour_comes_from_the_design_system(): void
    {
        $entry = $this->entry();

        $this->assertSame(
            [],
            $this->hexLiterals($entry),
            'Im Einstieg steht ein Hexwert. Jede Farbe kommt aus `app.css` (CLAUDE.md) — '
            .'auch die, die an eine Bibliothek weitergereicht wird.',
        );

        $treffer = [];
        $this->assertSame(
            1,
            preg_match("/\bprogress\s*:\s*\{[^}]*\bcolor\s*:\s*'var\(\s*(--[a-z0-9-]+)\s*\)'/", $entry, $treffer),
            'Die Farbe des Balkens ist keine Marke aus dem Gestaltungssystem.',
        );

        $marke = $treffer[1];

        $this->assertMatchesRegularExpression(
            '/^\s*'.preg_quote($marke, '/').'\s*:/m',
            (string) file_get_contents(dirname(__DIR__, 2).'/'.self::STYLESHEET),
            sprintf(
                'Der Balken zeigt auf die Marke „%s", und `app.css` kennt sie nicht. '
                .'Eine Custom Property, die es nicht gibt, ist im Browser kein Fehler — '
                .'sie ist eine fehlende Farbe, und der Balken bleibt unsichtbar.',
                $marke,
            ),
        );
    }

    /**
     * Die Verzögerung bleibt stehen.
     *
     * **Sie ist der Grund, dass auf einer schnellen Seite gar nichts blinkt.**
     * Dieselbe Überlegung wie bei der Schwelle des Platzhalters
     * (`docs/904 §2`): Ein Ladezustand, der kürzer steht als der Blick
     * braucht, ist kein Hinweis, sondern eine Bewegung ohne Aussage. Wer hier
     * `delay: 0` schriebe, bekäme auf jeder der elf schnellen Seiten ein
     * Zucken.
     *
     * Geprüft wird das **Fehlen** einer eigenen Angabe: Die Voreinstellung ist
     * hier die richtige, und eine Zahl, die sie wiederholt, wäre ihre zweite
     * Fassung.
     */
    public function test_the_delay_is_left_alone(): void
    {
        $treffer = [];

        $this->assertSame(
            1,
            preg_match('/\bprogress\s*:\s*\{([^}]*)\}/', $this->entry(), $treffer),
            'Die Angabe für den Balken ist anders gebaut als erwartet — dieser Test misst nichts mehr.',
        );

        $this->assertStringNotContainsString(
            'delay',
            $treffer[1],
            'Der Balken bekommt eine eigene Verzögerung. Inertias 250 ms sind hier die richtige '
            .'Zahl; eine zweite Fassung davon ist die, die veraltet.',
        );
    }

    /**
     * Die Hexwerte einer Datei.
     *
     * @return list<string>
     */
    private function hexLiterals(string $quelle): array
    {
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $quelle, $treffer);

        return $treffer[0];
    }

    /** Der Einstieg, ohne seine Kommentare. */
    private function entry(): string
    {
        $roh = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::ENTRY);

        /*
         * **Die Kommentare fallen weg, bevor gesucht wird.** Der Block über
         * `progress:` schreibt Inertias Voreinstellung `#29d` wörtlich hin —
         * roh gelesen meldete der Wächter oben einen Hexwert, den es im Code
         * nicht gibt. Derselbe Fall wie bei `OutcomeTest` am 1. September, nur
         * andersherum: Dort hielt ein Kommentar einen Wächter fälschlich grün,
         * hier machte er ihn fälschlich rot.
         *
         * `WithoutPhpComments` fragt den PHP-Parser und taugt für `.ts` nicht;
         * TypeScript kennt aber dieselben zwei Formen, und mehr braucht es
         * hier nicht.
         */
        $ohneBlock = (string) preg_replace('#/\*.*?\*/#s', '', $roh);

        return (string) preg_replace('#(^|\s)//[^\n]*#', '', $ohneBlock);
    }
}
