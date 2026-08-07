<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\TxtValue;
use SrvPanel\Agent\AgentException;

/**
 * Der TXT-Wert — die Regel, die Hetzner und Cloudflare teilen.
 *
 * Sie stand bei Hetzner allein, bis Cloudflare dazukam; seitdem steht sie
 * einmal, und `TxtValueSourceTest` besteht darauf, dass es dabei bleibt.
 */
final class TxtValueTest extends TestCase
{
    public function test_a_value_goes_out_in_quotes(): void
    {
        $this->assertSame('"abc123"', TxtValue::quoted('abc123'));
    }

    /** @return list<array{0: string}> */
    public static function unwritable(): array
    {
        return [['mit"zitat'], ['mit\\rueckstrich'], [''], [str_repeat('a', TxtValue::MAX_LENGTH + 1)]];
    }

    /**
     * Ein Wert, der eine Fluchtregel bräuchte, wird abgewiesen.
     *
     * Eine Fluchtregel wäre eine eigene kleine Sprache mit eigenen Fehlern —
     * noch dazu eine, die jeder Anbieter anders auslegt. Ein zu langer Wert ist
     * derselbe Fall aus der anderen Richtung: Anbieter teilen ihn stillschweigend
     * in zwei character-strings, und ein TXT-Satz aus zwei Teilen ist für die
     * Prüfung ein anderer Wert.
     */
    #[DataProvider('unwritable')]
    public function test_a_value_that_cannot_be_written_is_refused(string $value): void
    {
        $this->expectException(AgentException::class);

        TxtValue::quoted($value);
    }

    public function test_a_value_of_the_maximum_length_still_goes(): void
    {
        $value = str_repeat('a', TxtValue::MAX_LENGTH);

        $this->assertSame('"'.$value.'"', TxtValue::quoted($value));
    }

    /**
     * Beim Wiedererkennen zählen beide Formen.
     *
     * Cloudflare legt die Anführungszeichen ab und gibt sie zurück; andere
     * geben den nackten Wert. Wer nur die eine Form vergleicht, findet beim
     * Abräumen seinen eigenen Eintrag nicht — und lässt eine Aussage über die
     * Zone stehen, die niemand mehr zurücknimmt.
     */
    public function test_both_forms_are_recognised(): void
    {
        $this->assertTrue(TxtValue::matches('abc123', 'abc123'));
        $this->assertTrue(TxtValue::matches('"abc123"', 'abc123'));
    }

    /**
     * Und ein anderer Wert nicht — auch keiner, der nur anders geschrieben ist.
     *
     * Ein ACME-Prüfwert ist Base64 und damit sehr wohl auf Gross- und
     * Kleinschreibung bedacht; die Filter von Cloudflare sind es ausdrücklich
     * nicht. Deshalb wird hier Zeichen für Zeichen verglichen.
     */
    public function test_a_different_value_is_not_recognised(): void
    {
        $this->assertFalse(TxtValue::matches('"abc124"', 'abc123'));
        $this->assertFalse(TxtValue::matches('"ABC123"', 'abc123'));
        $this->assertFalse(TxtValue::matches('abc123"', 'abc123'));
    }
}
