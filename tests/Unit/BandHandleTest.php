<?php

declare(strict_types=1);

namespace Tests\Unit;

use DOMElement;
use DOMXPath;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\CssRules;
use Tests\Support\TemplateDom;

/**
 * Ein Band bricht um, und seine Handgriffe schrumpfen nicht.
 *
 * ## Der Befund
 *
 * `docs/144 §0` Befund 1, gemessen am 11. Oktober 2026 bei 390 px: Im
 * Streifen der laufenden Vorgänge stand „ansehen" Buchstabe für Buchstabe
 * untereinander, in sechs Zeilen bei einem fertigen und in sieben bei einem
 * fehlgeschlagenen Vorgang, und das Band war 167 px hoch.
 * `tests/bilder-messen.js` sagte in allen vier Lagen `dokument = 0`.
 *
 * > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
 * > Betrachter.**
 *
 * Zwei Ursachen, und beide hängen an `app.css`. `.band` trägt
 * `overflow-wrap: anywhere`; damit darf jedes Flexkind bis auf ein Zeichen
 * schrumpfen — für den Text gewollt, für einen Handgriff nicht. Und umbrechen
 * durfte die Zeile nur im `<style scoped>` von `PanelLayout`, und das erreicht
 * die Bänder aus `OperationBand.vue` nicht: Eine Kindkomponente mit mehreren
 * Wurzeln trägt das Merkmal der gescopten Regel nicht. Gemessen am selben Tag:
 * Das Band der fremden Sicht, das in der Vorlage des Layouts selbst steht,
 * brach um; die Vorgänge nicht.
 *
 * ## Was hier gemessen wird
 *
 * **Die Handgriffe kommen aus den Vorlagen und nicht aus einer Liste.** Ein
 * Handgriff ist jedes direkte Kind eines Elements mit der Klasse `band`, das
 * ein Knopf oder ein Verweis ist — `<button>`, `<a>` oder Inertias `<Link>`.
 * Für jeden verlangt der Wächter in `app.css` eine Regel `.band > .x` mit
 * `flex: none` und eine `.band > span + .x` mit `margin-left: auto`, für eine
 * seiner Klassen. Dazu `flex-wrap: wrap` an `.band` selbst, und dass keine
 * Vorlage diese Regel in ihrem eigenen Stilblock ein zweites Mal hat.
 *
 * Gebaut ist er so, dass ein neues Band mit einem neuen Handgriff ihn rot
 * macht, bevor jemand den Handgriff bei 390 px gesehen hat.
 *
 * ## Was er nicht kann
 *
 * Ob das Band bei 390 px gut aussieht. Das hängt an der Länge der Texte und
 * an der Schrift; gemessen ist es in `docs/144 §6a`, vorher und nachher, in
 * acht Lagen. Und er sieht nur Handgriffe, die als direkte Kinder dastehen:
 * Ein Knopf in einem `<span>` daneben ist für ihn Text.
 */
final class BandHandleTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function templates(): array
    {
        $dateien = [];

        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->getExtension() === 'vue') {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    /**
     * Die Bänder aller Vorlagen und ihre Handgriffe.
     *
     * @return array{bands: int, handles: list<array{file: string, classes: list<string>}>}
     */
    private function handles(): array
    {
        $baender = 0;
        $griffe = [];
        $tags = ['button', 'a', TemplateDom::component('Link')];

        foreach ($this->templates() as $pfad) {
            $xpath = new DOMXPath(TemplateDom::fromSource((string) file_get_contents($pfad)));
            $treffer = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' band ')]");

            foreach ($treffer ?: [] as $band) {
                if (! $band instanceof DOMElement) {
                    continue;
                }

                $baender++;

                foreach ($band->childNodes as $kind) {
                    if (! $kind instanceof DOMElement || ! in_array($kind->tagName, $tags, true)) {
                        continue;
                    }

                    $griffe[] = [
                        'file' => substr($pfad, strlen($this->root()) + 1),
                        'classes' => preg_split('/\s+/', trim($kind->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    ];
                }
            }
        }

        return ['bands' => $baender, 'handles' => $griffe];
    }

    /** @return list<array{selector: string, body: string}> */
    private function rules(): array
    {
        return CssRules::flatten((string) file_get_contents($this->root().'/resources/css/app.css'));
    }

    /**
     * Trägt eine Regel mit genau diesem Selektor die Deklaration?
     *
     * @param  list<array{selector: string, body: string}>  $regeln
     */
    private function declares(array $regeln, string $selektor, string $ausdruck): bool
    {
        foreach ($regeln as $regel) {
            if ($regel['selector'] === $selektor && preg_match($ausdruck, $regel['body']) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Untergrenze, ohne die jeder Fall darunter leer grün wäre.
     *
     * Gezählt am 11. Oktober 2026: vier Bänder (fremde Sicht und Wartung im
     * Layout, die Ankündigungen, die Vorgänge) und drei Handgriffe (der Knopf
     * der fremden Sicht, „Seite aktualisieren" und „ansehen").
     */
    public function test_the_reader_finds_the_bands_and_their_handles(): void
    {
        $gefunden = $this->handles();

        $this->assertGreaterThanOrEqual(4, $gefunden['bands'],
            'Es werden kaum Bänder gefunden — dann greift der Ausdruck über die Vorlagen ins Leere, '.
            'und jeder Fall hier ist grün, weil er nichts zu prüfen hat.');
        $this->assertGreaterThanOrEqual(3, count($gefunden['handles']),
            'Es werden kaum Handgriffe in Bändern gefunden — dieselbe Leere von der anderen Seite.');
    }

    public function test_a_band_may_wrap(): void
    {
        $this->assertTrue(
            $this->declares($this->rules(), '.band', '/\bflex-wrap:\s*wrap\b/'),
            "`.band` in app.css trägt kein `flex-wrap: wrap`.\n\n".
            'Ohne Umbruch schrumpft bei 390 px, was schrumpfen darf, und mit `overflow-wrap: anywhere` '.
            'darf das jedes Kind bis auf ein Zeichen. So stand „ansehen" am 11. Oktober 2026 in sechs '.
            'Zeilen (docs/144 §0 Befund 1).',
        );
    }

    public function test_a_handle_in_a_band_does_not_shrink(): void
    {
        $regeln = $this->rules();
        $offen = [];

        foreach ($this->handles()['handles'] as $griff) {
            $gedeckt = false;

            foreach ($griff['classes'] as $klasse) {
                $gedeckt = $gedeckt || $this->declares($regeln, '.band > .'.$klasse, '/\bflex:\s*none\b/');
            }

            if (! $gedeckt) {
                $offen[] = sprintf('%s: .%s', $griff['file'], implode('.', $griff['classes']));
            }
        }

        $this->assertSame([], $offen, sprintf(
            "Diese Handgriffe stehen in einem Band, und app.css hat für keine ihrer Klassen eine Regel\n".
            "`.band > .x { flex: none }`:\n  %s\n\n".
            '`.band` trägt `overflow-wrap: anywhere`, und ein Kind ohne `flex: none` gibt seine Breite her, '.
            'bis ein Zeichen je Zeile übrig ist.',
            implode("\n  ", $offen),
        ));
    }

    public function test_the_first_handle_after_the_text_goes_right(): void
    {
        $regeln = $this->rules();
        $offen = [];

        foreach ($this->handles()['handles'] as $griff) {
            $gedeckt = false;

            foreach ($griff['classes'] as $klasse) {
                $gedeckt = $gedeckt || $this->declares($regeln, '.band > span + .'.$klasse, '/\bmargin-left:\s*auto\b/');
            }

            if (! $gedeckt) {
                $offen[] = sprintf('%s: .%s', $griff['file'], implode('.', $griff['classes']));
            }
        }

        /*
         * **Für jeden Handgriff und nicht nur für den Knopf.** Ob ein Handgriff
         * der erste nach dem Text ist, entscheidet die Laufzeit: „Seite
         * aktualisieren" steht nur bei einem fertigen Vorgang da, und bei
         * einem wartenden folgt „ansehen" direkt dem Text. Die Vorlage zeigt
         * beide, also gilt die Regel für beide.
         */
        $this->assertSame([], $offen, sprintf(
            "Diese Handgriffe können direkt auf den Text eines Bandes folgen, und app.css schiebt sie nicht\n".
            "nach rechts (`.band > span + .x { margin-left: auto }`):\n  %s\n\n".
            'Mit `space-between` allein steht ein einzelner Handgriff in einer umgebrochenen Zeile links '.
            'unter dem Text, und bei 1440 px stand „Seite aktualisieren" in der Mitte des Bandes.',
            implode("\n  ", $offen),
        ));
    }

    /**
     * Und keine Vorlage hat die Regel ein zweites Mal.
     *
     * **Bis zum 11. Oktober 2026 war das die einzige Fassung**, im
     * `<style scoped>` von `PanelLayout` — und sie erreichte genau ein Band.
     * Eine zweite neben der in `app.css` wäre die, die veraltet; und kehrte
     * sie allein zurück, sähe die fremde Sicht richtig aus und der Streifen
     * nicht.
     */
    public function test_no_template_wraps_a_band_on_its_own(): void
    {
        $doppelt = [];

        foreach ($this->templates() as $pfad) {
            $gescopt = CssRules::flatten(CssRules::scoped((string) file_get_contents($pfad)));

            foreach ($gescopt as $regel) {
                if ($regel['selector'] === '.band' && preg_match('/\bflex-wrap\s*:/', $regel['body']) === 1) {
                    $doppelt[] = substr($pfad, strlen($this->root()) + 1);
                }
            }
        }

        $this->assertSame([], $doppelt, sprintf(
            "Diese Vorlagen setzen `flex-wrap` an `.band` in ihrem eigenen Stilblock:\n  %s\n\n".
            'Die Regel steht in app.css für jedes Band. Eine gescopte Fassung erreicht nur die Bänder der '.
            'eigenen Vorlage — die Vorgänge aus OperationBand.vue erreichte sie nie.',
            implode("\n  ", array_unique($doppelt)),
        ));
    }
}
