<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Jeder Statuscode, den dieses Panel einem Browser zeigen kann, hat seine
 * eigene Seite — deutsch und in „Kontor".
 *
 * ## Der Befund
 *
 * Befund 3 aus `docs/84`. Auf allen acht Bildern von Punkt 3 stand
 * *„This action is unauthorized."* — englisch, in Times, ausserhalb des
 * Gestaltungssystems. `resources/views/errors/` gab es nicht, und weder
 * `bootstrap/app.php` noch `app/Exceptions/` fassten den Fall an; Laravel
 * rendert dann selbst.
 *
 * `docs/19` ist bindend: alle Texte der Oberfläche deutsch. `WordChoiceTest`
 * setzt das durch — er liest `resources/js/**\/*.vue`, und dort war es auch
 * eingehalten.
 *
 * > **Ein Wächter, der die geschriebenen Seiten prüft, sagt nichts über die,
 * > die niemand geschrieben hat.**
 *
 * ## Warum A9 es wichtig gemacht hat
 *
 * Vor der Rollentrennung bekam kaum jemand einen 403 zu sehen — wer nicht
 * durfte, war Kunde, und der sah den Menüpunkt gar nicht erst. Seit Schritt 2
 * ist der 403 der **entworfene** Zustand für acht Seiten: Ein Administrator,
 * der eine Betreiberadresse aufruft, soll ihn bekommen.
 *
 * > **Eine Seite, die niemand sehen sollte, wird durch eine Rollentrennung zu
 * > einer, die jemand sehen soll.**
 *
 * ## Woher die Liste kommt
 *
 * Nicht aus dem Gedächtnis, sondern aus dem, was dieses Panel erzeugt:
 *
 *   403  die acht Seiten des Betreibers, für einen Administrator
 *   404  jede Adresse mit einer Kennung, die es nicht mehr gibt
 *   419  ein Formular, das über die Sitzungsdauer offen lag
 *   429  die Ratenbegrenzung der Anmeldung
 *   500  jede unbehandelte Ausnahme
 *   503  die Wartung
 */
final class ErrorPageTest extends TestCase
{
    use WithoutMarkupComments;

    /**
     * Die Statuscodes, die ein Browser hier zu sehen bekommt.
     *
     * @var list<string>
     */
    private const STATUS = ['403', '404', '419', '429', '500', '503'];

    public function test_every_status_the_panel_can_show_has_its_own_page(): void
    {
        $fehlend = [];

        foreach (self::STATUS as $code) {
            if (! is_file($this->errors().'/'.$code.'.blade.php')) {
                $fehlend[] = $code;
            }
        }

        $this->assertSame([], $fehlend, sprintf(
            "Für diese Statuscodes gibt es keine eigene Seite: %s\n\n".
            'Ohne sie rendert Laravel selbst — englisch und ausserhalb von „Kontor".',
            implode(', ', $fehlend),
        ));
    }

    /**
     * Jede Fehlerseite trägt eine Zahl, eine Überschrift und einen Satz.
     *
     * **Ohne den Satz wäre es keine Auskunft.** Eine Seite, auf der „403" steht
     * und sonst nichts, sagt dem Kunden dasselbe wie Laravels Vorgabe — nur auf
     * deutsch.
     */
    public function test_every_error_page_says_what_happened(): void
    {
        $funde = [];
        $geprueft = 0;

        foreach (self::STATUS as $code) {
            $datei = $this->errors().'/'.$code.'.blade.php';

            if (! is_file($datei)) {
                continue;
            }

            $geprueft++;
            $quelle = (string) file_get_contents($datei);

            foreach (['code', 'title', 'message'] as $abschnitt) {
                if (! str_contains($quelle, "@section('".$abschnitt."'")) {
                    $funde[] = sprintf('%s.blade.php — ohne @section(\'%s\')', $code, $abschnitt);
                }
            }

            if (! str_contains($quelle, "@extends('errors.layout')")) {
                $funde[] = sprintf('%s.blade.php — hängt nicht an errors.layout', $code);
            }
        }

        /*
         * **Die Untergrenze.** Ohne sie wäre dieser Test grün, wenn es keine
         * einzige Seite gäbe — der `continue` oben übergeht jede fehlende.
         *
         * > Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
         * > Null steht.
         */
        $this->assertSame(count(self::STATUS), $geprueft,
            'Es wurden nicht alle Seiten gelesen — dann sagt der Rest dieses Tests nichts.');

        $this->assertSame([], $funde, sprintf("Diese Seiten sind unvollständig:\n  %s",
            implode("\n  ", $funde)));
    }

    /**
     * Die Hülle lädt das Stylesheet und **nicht** das Skriptbündel.
     *
     * `resources/js/app.ts` setzt Inertia auf `#app` auf; eine Blade-Seite hat
     * keines, und das Bündel scheiterte dort. Wichtiger noch: Eine Seite, die
     * es gerade dann geben muss, wenn etwas kaputt ist, lädt so wenig wie
     * möglich — kein Inertia, kein `auth()`, keine Abfrage.
     *
     * Ein 500, den eine tote Datenbankverbindung ausgelöst hat, scheiterte
     * sonst ein zweites Mal, und der Betrachter bekäme gar nichts.
     */
    public function test_the_layout_loads_the_stylesheet_and_nothing_else(): void
    {
        $quelle = $this->withoutMarkupComments((string) file_get_contents($this->errors().'/layout.blade.php'));

        $this->assertStringContainsString("@vite('resources/css/app.css')", $quelle,
            'Die Hülle lädt das Stylesheet nicht — die Seite stünde ohne Gestaltung da.');

        $this->assertStringNotContainsString('app.ts', $quelle,
            'Die Hülle zieht das Skriptbündel herein. Es setzt Inertia auf #app auf, '
            .'das es hier nicht gibt.');

        foreach (['auth()', '@inertia', 'Auth::'] as $verboten) {
            $this->assertStringNotContainsString($verboten, $quelle, sprintf(
                'Die Hülle ruft `%s`. Eine Fehlerseite fragt niemanden — sonst scheitert sie '
                .'ein zweites Mal an dem, was den Fehler ausgelöst hat.',
                $verboten,
            ));
        }
    }

    /**
     * Das Stylesheet ist ein eigener Vite-Eingang.
     *
     * `@vite('resources/css/app.css')` schlägt fehl, wenn die Datei nicht im
     * Manifest steht — und zwar erst zur Laufzeit, auf genau der Seite, die
     * tragen soll.
     *
     * > **Ein Verweis auf einen Eingang, den es nicht gibt, fällt dort auf, wo
     * > man am wenigsten hinsehen kann.**
     */
    public function test_the_stylesheet_is_a_vite_entry(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 2).'/vite.config.js');

        $this->assertStringContainsString("'resources/css/app.css'", $config,
            'resources/css/app.css steht nicht in den Vite-Eingängen — @vite() findet es dann nicht.');
    }

    private function errors(): string
    {
        return dirname(__DIR__, 2).'/resources/views/errors';
    }
}
