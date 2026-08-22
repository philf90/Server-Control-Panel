<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DnsRecordState;
use App\Support\Dns\Comparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Vergleich — und die eine Frage, an der er hängt.
 *
 * **Wann zeigt ein Name hierher?** Nicht, wenn die Werte gleich sind. Ein
 * Server kann zwei Adressen führen und die Website unter beiden bedienen; ein
 * Kunde, der auf eine davon zeigt, ist richtig unterwegs. Umgekehrt genügt
 * **ein** fremder Wert daneben, damit ein Teil der Anfragen woanders landet.
 *
 * > **Ein Eintrag, der überwiegend stimmt, ist ein Ausfall, den man für ein
 * > Netzproblem hält.**
 */
final class DnsComparisonTest extends TestCase
{
    private const V4 = '203.0.113.10';

    private const V4B = '203.0.113.11';

    private const FREMD = '198.51.100.7';

    /**
     * @param  list<string>  $expected
     * @return list<array{name: string, type: string, expected: list<string>}>
     */
    private function desired(array $expected = [self::V4]): array
    {
        return [['name' => 'example.de', 'type' => 'A', 'expected' => $expected]];
    }

    /**
     * @param  list<string>  $values
     * @return list<array{name: string, type: string, asked: int, answered: int, values: list<string>, consistent: bool}>
     */
    private function measured(
        array $values = [self::V4],
        int $asked = 2,
        int $answered = 2,
        bool $consistent = true,
        string $name = 'example.de',
        string $type = 'A',
    ): array {
        return [[
            'name' => $name,
            'type' => $type,
            'asked' => $asked,
            'answered' => $answered,
            'values' => $values,
            'consistent' => $consistent,
        ]];
    }

    /**
     * @param  list<array{name: string, type: string, expected: list<string>}>  $desired
     * @param  list<array{name: string, type: string, asked: int, answered: int, values: list<string>, consistent: bool}>  $measured
     */
    private function state(array $desired, array $measured): DnsRecordState
    {
        return Comparison::of($desired, $measured)[0]['state'];
    }

    // ------------------------------------------------------------------
    // Die Kernregel
    // ------------------------------------------------------------------

    public function test_a_matching_record_points_here(): void
    {
        $this->assertSame(DnsRecordState::Here, $this->state($this->desired(), $this->measured()));
    }

    /**
     * Der Kunde zeigt auf **eine** von zwei Adressen dieses Servers — richtig.
     *
     * Das ist die Hälfte der Regel, die eine Gleichheitsprüfung falsch machen
     * würde: Der Server führt zwei Adressen, der Kunde nennt eine.
     */
    public function test_one_of_our_two_addresses_is_enough(): void
    {
        $this->assertSame(
            DnsRecordState::Here,
            $this->state($this->desired([self::V4, self::V4B]), $this->measured([self::V4])),
        );
    }

    /**
     * Und **ein** fremder Wert daneben genügt für „zeigt woandershin".
     *
     * Das ist die andere Hälfte, und sie ist die teurere: Die Seite
     * funktioniert dann meistens, und der Ausfall sieht aus wie ein
     * Netzproblem.
     */
    public function test_one_foreign_value_alongside_is_already_elsewhere(): void
    {
        $this->assertSame(
            DnsRecordState::Elsewhere,
            $this->state($this->desired(), $this->measured([self::V4, self::FREMD])),
        );
    }

    public function test_a_completely_foreign_value_is_elsewhere(): void
    {
        $this->assertSame(
            DnsRecordState::Elsewhere,
            $this->state($this->desired(), $this->measured([self::FREMD])),
        );
    }

    public function test_no_value_at_all_is_missing(): void
    {
        $this->assertSame(DnsRecordState::Missing, $this->state($this->desired(), $this->measured([])));
    }

    // ------------------------------------------------------------------
    // Die zwei Zustände, die nichts über die Zone sagen
    // ------------------------------------------------------------------

    /**
     * Kein Nameserver hat geantwortet — das ist nicht „fehlt".
     *
     * **Der Unterschied ist der Grund, aus dem `Lookup` seit P7 `null`
     * kennt.** Ohne ihn schickte die Anzeige den Kunden dorthin, wo nichts zu
     * ändern ist.
     *
     * @param  int  $asked  So viele Server wurden gefragt
     * @param  int  $answered  So viele haben geantwortet
     */
    #[DataProvider('stummeLagen')]
    public function test_a_silent_zone_says_nothing_about_the_record(int $asked, int $answered): void
    {
        $this->assertSame(
            DnsRecordState::Unreachable,
            $this->state($this->desired(), $this->measured([], $asked, $answered)),
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function stummeLagen(): iterable
    {
        yield 'keiner hat geantwortet' => [2, 0];
        yield 'es gab keine Nameserver zu fragen' => [0, 0];
    }

    /**
     * Und die Gegenprobe: Haben sie geantwortet, ist dieselbe leere Liste
     * „fehlt".
     *
     * **Ohne diese Zeile wäre die vorige keine Messung** — beide Lagen haben
     * dieselbe leere Werteliste, unterschieden werden sie allein an `answered`.
     */
    public function test_the_same_empty_list_is_missing_when_someone_answered(): void
    {
        $this->assertSame(
            DnsRecordState::Missing,
            $this->state($this->desired(), $this->measured([], 2, 2)),
        );
    }

    /**
     * Uneinige Nameserver werden **vor** dem Wertevergleich erkannt.
     *
     * Sonst hiesse eine Zone, deren einer Server das Richtige und deren
     * anderer etwas Falsches sagt, „zeigt woandershin" — und der Kunde suchte
     * den Fehler in seinem Eintrag statt bei seinem Anbieter.
     */
    public function test_disagreeing_nameservers_are_recognised_before_the_values(): void
    {
        $this->assertSame(
            DnsRecordState::Inconsistent,
            $this->state($this->desired(), $this->measured([self::V4, self::FREMD], 2, 2, consistent: false)),
        );
    }

    /**
     * Auch dann, wenn die vereinigten Werte zufällig alle unsere sind.
     *
     * Der eine Server liefert `.10`, der andere `.11` — beide gehören uns, und
     * die Zone ist trotzdem kaputt.
     */
    public function test_disagreement_counts_even_when_every_value_is_ours(): void
    {
        $this->assertSame(
            DnsRecordState::Inconsistent,
            $this->state(
                $this->desired([self::V4, self::V4B]),
                $this->measured([self::V4, self::V4B], 2, 2, consistent: false),
            ),
        );
    }

    // ------------------------------------------------------------------
    // Die Zuordnung
    // ------------------------------------------------------------------

    /** Ein erwarteter Eintrag ohne Messung ist „nicht erreichbar" und nicht „fehlt". */
    public function test_an_expected_record_without_a_measurement_is_unreachable(): void
    {
        $this->assertSame(DnsRecordState::Unreachable, $this->state($this->desired(), []));
    }

    /** Zugeordnet wird über Namen und Typ, unabhängig von der Schreibweise. */
    public function test_name_and_type_are_matched_regardless_of_spelling(): void
    {
        $this->assertSame(
            DnsRecordState::Here,
            $this->state(
                [['name' => 'example.de', 'type' => 'A', 'expected' => [self::V4]]],
                $this->measured([self::V4], name: 'Example.DE.', type: 'a'),
            ),
        );
    }

    /** Eine Messung, die zu keinem Sollwert gehört, taucht im Ergebnis nicht auf. */
    public function test_a_measurement_without_an_expectation_is_not_reported(): void
    {
        $result = Comparison::of(
            $this->desired(),
            array_merge($this->measured(), $this->measured([self::FREMD], name: 'fremd.example.de')),
        );

        $this->assertCount(1, $result);
        $this->assertSame('example.de', $result[0]['name']);
    }

    /** Das Ergebnis trägt beides — was erwartet wurde und was dasteht. */
    public function test_the_result_carries_both_sides(): void
    {
        $result = Comparison::of($this->desired(), $this->measured([self::FREMD]))[0];

        $this->assertSame([self::V4], $result['expected']);
        $this->assertSame([self::FREMD], $result['found'], 'der gefundene Wert gehört in die Anzeige');
    }

    // ------------------------------------------------------------------
    // Was die Zustände über sich sagen
    // ------------------------------------------------------------------

    /**
     * „Zeigt woandershin" steht der Website nicht im Weg.
     *
     * **Das ist die Entscheidung aus `docs/72 §2.3` und keine Nachlässigkeit.**
     * Wer über ein CDN fährt, hat genau diesen Zustand absichtlich.
     */
    public function test_pointing_elsewhere_is_not_treated_as_a_fault(): void
    {
        $this->assertFalse(DnsRecordState::Elsewhere->blocking());
        $this->assertFalse(DnsRecordState::Here->blocking());
        $this->assertTrue(DnsRecordState::Missing->blocking());
        $this->assertTrue(DnsRecordState::Inconsistent->blocking());

        // Über eine schweigende Zone ist nichts gesagt — auch nicht, dass
        // etwas im Weg steht.
        $this->assertFalse(DnsRecordState::Unreachable->blocking());
    }

    /** Jeder Zustand sagt, was der Kunde tun kann — oder dass er nichts tun kann. */
    public function test_every_state_has_a_label_and_a_hint(): void
    {
        foreach (DnsRecordState::cases() as $state) {
            $this->assertNotSame('', $state->label(), $state->value.' hat keine Beschriftung.');
            $this->assertNotSame('', $state->hint(), $state->value.' sagt nicht, was zu tun ist.');
        }

        // Und der Hinweis bei „nicht erreichbar" fordert nicht zum Ändern auf:
        // Er schickte sonst jemanden dorthin, wo nichts zu ändern ist.
        $this->assertStringContainsString('nichts gesagt', DnsRecordState::Unreachable->hint());
    }
}
