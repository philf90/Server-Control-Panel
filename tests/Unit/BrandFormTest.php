<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Das Markenformular lässt ein leeres Feld zu — im Browser und nicht nur an
 * der Tür (`docs/140 §6d`).
 *
 * Entschieden vom Betreiber am 3. Oktober 2026: Ein leeres Feld für Name oder
 * Farbe heisst „die Vorgabe". `BrandReachTest` misst das durch die Tür. Was er
 * nicht sehen kann, ist der Browser: Trägt das Feld `required`, schickt der
 * Browser das leere Formular gar nicht erst ab, und die Tür bekommt den Fall
 * nie zu Gesicht. Bis zum 3. Oktober trugen alle drei Felder genau das.
 *
 * > **Ein Wächter über die Antwort des Servers sieht nicht, was der Browser
 * > vorher abweist.**
 *
 * Daneben hält er den Platzhalter: Er ist die Stelle, an der das leere Feld
 * sagt, was dann gilt. Gelesen wird er aus den Vorgaben des Servers und nicht
 * als Hexwert in der Vorlage — sonst stünde die Vorgabe ein zweites Mal da.
 *
 * Die Tags liest dasselbe Muster, mit dem `TemplateDom` sie liest: Ein
 * Attributwert darf ein `>` tragen, und ein Ausdruck bis zum nächsten `>` läse
 * dann ein halbes Tag.
 */
final class BrandFormTest extends TestCase
{
    use WithoutMarkupComments;

    private const FELDER = ['name', 'accent_light', 'accent_dark'];

    /**
     * Die Eingabefelder des Markenformulars, je Feld die Attribute des Tags.
     *
     * @return array<string, list<string>>
     */
    private function felder(): array
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Settings/General.vue');
        $ohneKommentare = $this->withoutMarkupComments($quelle);

        preg_match_all('#<input((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#s', $ohneKommentare, $tags);

        $felder = [];

        foreach ($tags[1] as $attribute) {
            if (preg_match('/\sv-model="marke\.(\w+)"/', $attribute, $feld) === 1) {
                $felder[$feld[1]][] = $attribute;
            }
        }

        return $felder;
    }

    public function test_every_field_is_found_once(): void
    {
        $felder = $this->felder();

        foreach (self::FELDER as $feld) {
            self::assertCount(1, $felder[$feld] ?? [], "Das Feld marke.{$feld} steht nicht genau einmal in der Vorlage — dann misst dieser Wächter nichts.");
        }
    }

    public function test_no_field_requires_a_value(): void
    {
        foreach ($this->felder() as $feld => $tags) {
            if (! in_array($feld, self::FELDER, true)) {
                continue;
            }

            foreach ($tags as $attribute) {
                self::assertDoesNotMatchRegularExpression(
                    '/\s(?::|v-bind:)?required\b/',
                    $attribute,
                    "marke.{$feld} trägt required — der Browser schickt ein leeres Feld dann gar nicht ab, und „leer heisst die Vorgabe\" erreicht die Tür nie (docs/140 §6d).",
                );
            }
        }
    }

    public function test_every_field_shows_its_default_as_placeholder(): void
    {
        $felder = $this->felder();

        foreach (self::FELDER as $feld) {
            foreach ($felder[$feld] ?? [] as $attribute) {
                self::assertStringContainsString(
                    ":placeholder=\"props.brandDefaults.{$feld}\"",
                    $attribute,
                    "marke.{$feld} zeigt nicht, welche Vorgabe ohne Eintrag gilt.",
                );
            }
        }
    }
}
