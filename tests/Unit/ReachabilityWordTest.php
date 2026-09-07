<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Bereich „Ports und Regelwerk" sagt nicht, was dieser Server nicht weiss.
 *
 * **Das ist die tragende Regel des ersten Wurfs von A3.** Gemessen
 * (`docs/81 §2.3s` M20) ist der Blick von innen Feld für Feld derselbe, ob eine
 * Sperre davorsteht oder nicht: einmal `LISTEN 0 5 0.0.0.0:19100` mit leerem
 * Regelwerk und von aussen erreichbar, einmal dieselben Zeichen und nicht
 * erreichbar.
 *
 * > **Ein Port, der lauscht, und ein Regelwerk, das nichts verbietet, sagen
 * > über die Erreichbarkeit von aussen nichts — und sie sagen es in beiden
 * > Fällen mit denselben Zeichen.**
 *
 * Auf einem gemieteten Server steht regelmässig eine Cloud-Firewall davor. Eine
 * Anzeige „Port offen" wäre dort **schweigend falsch**, und das ist schlimmer
 * als keine Anzeige.
 *
 * Framework-frei: gelesen wird die `.vue` als Text.
 */
final class ReachabilityWordTest extends TestCase
{
    private const SEITE = __DIR__.'/../../resources/js/Pages/Services/Index.vue';

    /**
     * Die drei Wörter, die eine Aussage über den Weg von aussen machen.
     *
     * „geschlossen" steht mit dabei, weil die Gegenrichtung dieselbe Behauptung
     * ist: Wer einen Port „geschlossen" nennt, sagt, dass von aussen niemand
     * hereinkommt — und das weiss diese Maschine genauso wenig.
     */
    private const VERBOTEN = ['offen', 'erreichbar', 'geschlossen'];

    /**
     * Nur der Bereich, **und ohne die Kommentare**.
     *
     * **Der erste Lauf dieses Wächters war rot, und zwar an sich selbst.** Der
     * Kommentar über dem Bereich erklärt, dass „offen", „erreichbar" und
     * „geschlossen" dort nicht vorkommen dürfen — und schreibt sie dabei hin.
     *
     * > **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht
     * > eine Messung fälschlich rot.** Der Satz steht seit dem 1. September in
     * `CLAUDE.md`, dort für Shell und YAML; `WithoutPhpComments` und
     * `WithoutHashComments` gibt es genau dafür. Für eine `.vue` gab es
     * nichts — bis hier.
     *
     * Gefragt ist ohnehin, was ein **Leser der Seite** sieht, und der sieht
     * keinen Kommentar.
     */
    private function bereich(): string
    {
        $quelle = file_get_contents(self::SEITE);

        $this->assertIsString($quelle, 'Die Seite ist nicht lesbar.');

        $anfang = strpos($quelle, '<Section title="Ports und Regelwerk"');

        $this->assertNotFalse($anfang, 'Den Bereich gibt es nicht mehr — dann prüft dieser Wächter nichts.');

        $ende = strpos($quelle, '</Section>', $anfang);

        $this->assertNotFalse($ende);

        return self::ohneKommentare(substr($quelle, $anfang, $ende - $anfang));
    }

    /** Ein Markup ohne seine `<!-- … -->`-Blöcke. */
    private static function ohneKommentare(string $markup): string
    {
        return preg_replace('/<!--.*?-->/su', '', $markup) ?? $markup;
    }

    /**
     * Keines der drei Wörter steht im Bereich.
     *
     * **Der Satz über die Firewall des Anbieters ist die Ausnahme**, und er
     * wird vorher herausgeschnitten: Er *muss* „von aussen" sagen, denn er ist
     * die Auskunft, dass es unbekannt ist.
     */
    public function test_the_section_never_claims_reachability(): void
    {
        $bereich = $this->bereich();

        // Der eine erlaubte Satz — ohne ihn wäre die Prüfung unten sinnlos
        // streng, mit ihm im Text wäre sie sinnlos milde.
        $ohne = preg_replace('/Ob diese Ports.*?horcht.*?\./su', '', $bereich) ?? $bereich;

        $this->assertNotSame($bereich, $ohne, 'Der Satz, der die Unwissenheit benennt, steht nicht im Bereich.');

        foreach (self::VERBOTEN as $wort) {
            $this->assertStringNotContainsString(
                $wort,
                $ohne,
                sprintf(
                    'Das Wort „%s" ist eine Aussage über den Weg von aussen, und den misst hier niemand (M20).',
                    $wort,
                ),
            );
        }
    }

    /**
     * Der Satz, der die Unwissenheit benennt, steht **genau einmal**.
     *
     * Zweimal wäre er eine Wand, keinmal eine stille Zusage. Gezählt wird an
     * der Wendung, die ihn trägt.
     */
    public function test_the_sentence_about_the_unknown_stands_exactly_once(): void
    {
        $quelle = file_get_contents(self::SEITE);

        $this->assertIsString($quelle);
        $this->assertSame(
            1,
            substr_count($quelle, 'Steht eine Firewall des Anbieters davor'),
            'Der Satz gehört einmal auf die Seite — nicht je Zeile und nicht gar nicht.',
        );
    }

    /**
     * Die Reichweite kommt aus einer Funktion und nicht aus dem Zustand roh.
     *
     * `any`, `loopback` und `specific` sind Werte des Agenten. Stünde einer
     * davon in der Vorlage, läse der Kunde ein englisches Wort — derselbe
     * Befund wie `active` auf der Übersicht (`docs/91 §13`, Befund 5).
     */
    public function test_no_raw_scope_value_reaches_the_page(): void
    {
        $bereich = $this->bereich();

        foreach (['{{ l.scope }}', '{{ l.family }}'] as $roh) {
            $this->assertStringNotContainsString(
                $roh,
                $bereich,
                'Ein Rohwert des Agenten gehört nicht in die Anzeige.',
            );
        }

        $this->assertStringContainsString('reichweite(l)', $bereich, 'Die Reichweite kommt aus der Funktion.');
    }
}
