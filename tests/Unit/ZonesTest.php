<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Zones;

/**
 * Der Zonenabgleich — die Regel, die bei jedem Anbieter dieselbe ist.
 *
 * Sie stand vor Hetzner zweimal als eigene Schleife da. Hier steht sie einmal,
 * und hier wird sie geprüft; `ZoneSourceTest` besteht darauf, dass es dabei
 * bleibt.
 */
final class ZonesTest extends TestCase
{
    public function test_the_longest_matching_zone_wins(): void
    {
        $this->assertSame(
            'kunde.example.de',
            Zones::pick('_acme-challenge.kunde.example.de', ['example.de', 'kunde.example.de']),
        );
    }

    /** Und die Reihenfolge der Liste ändert daran nichts. */
    public function test_the_order_of_the_list_does_not_decide(): void
    {
        $this->assertSame(
            'kunde.example.de',
            Zones::pick('_acme-challenge.kunde.example.de', ['kunde.example.de', 'example.de']),
        );
    }

    /**
     * Verglichen wird beschriftungsweise und nicht als Zeichenkette.
     *
     * `bösexample.de` endet auf `example.de` und liegt trotzdem nicht darin.
     * Wer hier `str_ends_with` allein nimmt, legt einen Eintrag in einer
     * fremden Zone an.
     */
    public function test_a_name_that_merely_ends_the_same_is_not_inside(): void
    {
        $this->assertNull(Zones::pick('_acme-challenge.boesexample.de', ['example.de']));
    }

    public function test_a_name_outside_every_zone_has_none(): void
    {
        $this->assertNull(Zones::pick('_acme-challenge.fremd.de', ['example.de', 'example.net']));
    }

    public function test_an_empty_list_has_none(): void
    {
        $this->assertNull(Zones::pick('_acme-challenge.example.de', []));
    }

    /** Ein Name kann die Zone selbst sein. */
    public function test_the_name_can_be_the_zone(): void
    {
        $this->assertSame('example.de', Zones::pick('example.de', ['example.de']));
        $this->assertSame('', Zones::prefix('example.de', 'example.de'));
    }

    public function test_the_prefix_is_what_stands_before_the_zone(): void
    {
        $this->assertSame('_acme-challenge', Zones::prefix('_acme-challenge.example.de', 'example.de'));
        $this->assertSame('_acme-challenge.kunde', Zones::prefix('_acme-challenge.kunde.example.de', 'example.de'));
    }

    /**
     * Schreibweise und abschliessender Punkt ändern nichts.
     *
     * **Der teure Fall ist der Punkt.** `example.de.` ist ein Zeichen länger als
     * `example.de`; wer die Länge der einen von der anderen abzieht, schneidet
     * um eine Stelle daneben und bekommt `_acme-challenge` mit einem Punkt zu
     * viel oder zu wenig.
     */
    public function test_case_and_trailing_dot_do_not_matter(): void
    {
        $this->assertSame('example.de', Zones::pick('_ACME-Challenge.Example.DE.', ['Example.DE.']));
        $this->assertSame('_acme-challenge', Zones::prefix('_ACME-Challenge.Example.DE.', 'Example.DE.'));
    }
}
