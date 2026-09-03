<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Was die Seite der Bestandsdiagnose zeigt und was nicht (A10 Schritt 7).
 *
 * ## Drei Regeln, die kein Feature-Test halten kann
 *
 * Sie hängen am Markup und nicht am Payload: dass `unknown` als eigener Zustand
 * dasteht, dass „steht seit" den **ersten** Zeitpunkt zeigt und nicht den
 * letzten, und dass die leere Liste nicht als Entwarnung gelesen wird.
 *
 * Framework-frei; gelesen wird die `.vue`.
 */
final class DiagnoseViewTest extends TestCase
{
    private const SEITE = 'resources/js/Pages/Diagnose/Index.vue';

    private function quelle(): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2).'/'.self::SEITE);
        $this->assertIsString($inhalt, self::SEITE.' ist nicht lesbar.');

        return $inhalt;
    }

    /**
     * Der Rumpf des Vorlagenblocks — ohne den Skriptteil.
     *
     * Ein Ausdruck über die ganze Datei fände seine Wörter auch in der Prosa
     * des Kopfes. Derselbe Grund, aus dem `ClassReachTest` den Vorlagenblock
     * herausschneidet.
     *
     * **Bis zum letzten `</template>` und nicht bis zum ersten.** Der erste
     * Wurf endete am ersten — und in dieser Seite steht ein
     * `<template v-if>` für einen bedingten Satz, das genauso schliesst. Der
     * Ausschnitt war damit 822 Zeichen lang statt der ganzen Vorlage, und drei
     * Behauptungen fielen über Text, der einfach nicht im Ausschnitt lag.
     *
     * > **Ein Leser, der am ersten schliessenden Zeichen aufhört, liest bis zur
     * > ersten Verschachtelung — und meldet das, was danach fehlt, als
     * > Befund.** Derselbe Fehler wie bei `ManagedBlock::managed()` (M22), nur
     * > in einer `.vue`.
     */
    private function vorlage(): string
    {
        $quelle = $this->quelle();
        $anfang = strpos($quelle, '<template>');
        $this->assertIsInt($anfang, 'Die Seite hat keinen Vorlagenblock.');

        $ende = strrpos($quelle, '</template>');
        $this->assertIsInt($ende);
        $this->assertGreaterThan($anfang, $ende);

        return substr($quelle, $anfang, $ende - $anfang);
    }

    /**
     * `unknown` steht als eigener Zustand da und wird nicht mit `fail` verrührt.
     *
     * Punkt 7 des Abnahmekriteriums: Bei angehaltenem Agenten steht jede
     * Prüfung auf `unknown` und keine auf `ok`. Eine Seite, die daraus „kaputt"
     * macht, meldet einen Schaden, den niemand gemessen hat — und eine, die es
     * verschweigt, gibt Entwarnung.
     */
    public function test_the_unknown_state_has_its_own_sentence(): void
    {
        $vorlage = $this->vorlage();

        $this->assertStringContainsString("'unknown'", $this->quelle(), 'Die Seite zählt die ungemessenen Prüfungen nicht.');
        $this->assertStringContainsString('nicht durchgelaufen', $vorlage, 'Kein Satz nennt die Prüfungen, die nicht gelaufen sind.');
        $this->assertStringContainsString('weder im Guten noch im Schlechten', $vorlage, 'Die Seite sagt nicht, dass „nicht gemessen" keine Entwarnung ist.');
    }

    /**
     * „Steht seit" zeigt den **ersten** Zeitpunkt.
     *
     * Punkt 8 des Abnahmekriteriums hängt daran: Derselbe Schaden über zwei
     * Nächte ergibt eine Zeile mit einem „steht seit" vom ersten Lauf. Zeigte
     * die Spalte `measured_at`, stünde dort jede Nacht das heutige Datum, und
     * niemand sähe mehr, wie lange etwas schon so ist.
     */
    public function test_the_column_shows_the_first_sighting_and_not_the_last(): void
    {
        $vorlage = $this->vorlage();

        $this->assertStringContainsString('Steht seit', $vorlage);
        $this->assertStringContainsString('first_seen_at', $vorlage);
        $this->assertStringNotContainsString('zeile.measured_at', $vorlage, 'Die Spalte zeigt den letzten Lauf — dann sagt sie nicht, wie lange etwas schon so ist.');
    }

    /**
     * Vor dem ersten Lauf ist die leere Liste keine Entwarnung.
     *
     * Dieselbe leere Tabelle bedeutet zweierlei, und nur eine der beiden
     * Bedeutungen ist gut.
     */
    public function test_an_empty_list_before_the_first_run_is_not_an_all_clear(): void
    {
        $vorlage = $this->vorlage();

        $this->assertStringContainsString('noch nie gelaufen', $vorlage);
        $this->assertStringContainsString('noch nicht nachgesehen', $vorlage);
    }

    /**
     * Der Wortlaut steht in einem `pre`, das umbricht.
     *
     * Ein `pre` bricht von sich aus nicht; bei 390 px rollt die Zelle dann
     * waagerecht, und das Dokument meldet dafür keine Zahl.
     */
    public function test_the_verbatim_output_wraps(): void
    {
        $quelle = $this->quelle();

        $this->assertStringContainsString('<pre v-if="zeile.detail" class="output detail"', $this->vorlage());
        $this->assertMatchesRegularExpression('/\.detail\s*\{[^}]*white-space:\s*pre-wrap/s', $quelle, 'Der Wortlaut bricht nicht um — die Zelle rollt dann bei 390 px.');
        $this->assertMatchesRegularExpression('/\.detail\s*\{[^}]*overflow-wrap:\s*anywhere/s', $quelle);
    }

    /** Und die Seite misst nichts selbst — sie liest, was der Nachtlauf hinterlassen hat. */
    public function test_the_page_asks_nothing(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/DiagnoseController.php');

        foreach (['->call(', 'Client ', 'system.diagnose'] as $aufruf) {
            $this->assertStringNotContainsString($aufruf, $controller, sprintf(
                'Die Seite fragt den Agenten (%s). Der Lauf hat eine Frist von 1800 Sekunden — das gehört an einen Timer.',
                $aufruf,
            ));
        }
    }
}
