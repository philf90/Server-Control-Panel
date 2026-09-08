<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Wo nichts feststeht, steht der Satz — und kein leerer Bereich.
 *
 * ## Der Befund, aus dem es diesen Wächter gibt
 *
 * **Gemessen am 8. September 2026 auf `cloudsrv24`** (`docs/114 §6`): Bei
 * angehaltenem Agenten antwortete `/services` mit 200, der Ports-Bereich sagte
 * „nicht feststellbar" — und unter der Überschrift „Regelwerk" stand
 * **nichts**. Die Konsole druckte `unter Regelwerk: "Regelwerk"` und danach den
 * Seitenfuss.
 *
 * > **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
 * > behauptet etwas, das sie nicht weiss.**
 *
 * Der Controller gibt bei einer `AgentException` `['readable' => false,
 * 'reason' => 'unreachable']` zurück — **ohne** den Schlüssel `filter`. Die
 * ganze Tabelle hängt an `v-if="props.ports.filter"`, und ohne Gegenzweig fällt
 * sie ersatzlos weg.
 *
 * **Es ist derselbe Befund wie Befund 3 des A6-Laufs**, am selben Tag auf
 * `/schedules` behoben und hier an einer zweiten Seite wieder da.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * ## Warum `v-else` und nicht eine zweite Bedingung
 *
 * Auf `/schedules` hält `CronPayloadTest` **zwei** Bedingungen aneinander, weil
 * dort Streifen und Bereiche getrennt stehen. Hier stehen Tabelle und Satz
 * unmittelbar nebeneinander, und dann gibt es die bessere Form:
 *
 * > **Ein `v-else` kann nicht auseinanderlaufen — die zweite Bedingung ist die,
 * > die veraltet, und hier gibt es keine zweite.**
 *
 * ## Was dieser Wächter nicht kann
 *
 * Er hält **diese** Seite. „Kein Bereich dieses Panels rendert eine Überschrift
 * über nichts" ist eine Aussage über das gerenderte Ergebnis und nicht über den
 * Quelltext; sie hängt daran, was der Agent gerade antwortet. Was ein Test
 * nicht halten kann, gehört als Frage aufgeschrieben und nicht als Zusage:
 *
 * > **Wer einen Bereich baut, dessen Inhalt vom Agenten kommt: Was steht dort,
 * > wenn der Agent nicht antwortet?**
 */
final class UnknownStateTest extends TestCase
{
    use WithoutMarkupComments;

    private const SEITE = __DIR__.'/../../resources/js/Pages/Services/Index.vue';

    /**
     * Die Regelwerkstabelle hat einen Gegenzweig, und er sagt etwas.
     */
    public function test_the_filter_section_speaks_in_both_states(): void
    {
        $quelle = file_get_contents(self::SEITE);

        $this->assertIsString($quelle, 'Die Seite ist nicht lesbar.');

        // **Ohne die Kommentare** — der über der Behebung schreibt die
        // Zielstelle wörtlich hin (`WithoutMarkupComments`, seit 25. August).
        $vorlage = $this->withoutMarkupComments($quelle);

        $this->assertMatchesRegularExpression(
            '/<table\s+v-if="props\.ports\.filter"/',
            $vorlage,
            'Die Regelwerkstabelle hängt nicht mehr an props.ports.filter — der Wächter misst die falsche Stelle.',
        );

        /*
         * **Gesucht wird der Gegenzweig und nicht irgendein `v-else`.** Er muss
         * *nach* der Tabelle und *vor* dem nächsten `v-if` stehen, sonst gehört
         * er einer anderen Bedingung.
         */
        $ab = strpos($vorlage, '<table v-if="props.ports.filter"');
        $rest = substr($vorlage, (int) $ab);
        $bis = strpos($rest, '</table>');

        $danach = substr($rest, (int) $bis);
        $naechstesIf = strpos($danach, 'v-if=');
        $abschnitt = $naechstesIf === false ? $danach : substr($danach, 0, $naechstesIf);

        $this->assertMatchesRegularExpression(
            '/<p\s+v-else\s+class="notice/',
            $abschnitt,
            'Auf die Regelwerkstabelle folgt kein v-else mit einer Meldung — bei angehaltenem Agenten stünde die Überschrift über nichts.',
        );

        $this->assertStringContainsString(
            'nicht feststellbar',
            $abschnitt,
            'Der Gegenzweig sagt nicht „nicht feststellbar" — und „keine Regeln" wäre eine Aussage über den Server statt über den Aufruf.',
        );
    }
}
