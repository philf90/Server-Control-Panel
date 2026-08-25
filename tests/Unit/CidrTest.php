<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Net\Cidr;
use SrvPanel\Agent\Pg\Hba;

/**
 * Die Netzrechnung — und die Politik, die nicht darin steht.
 *
 * ## Warum es diesen Wächter erst jetzt gibt
 *
 * Die Rechnung stand seit P5b in `Pg\Hba::cidr()` und war **von keinem einzigen
 * Test abgedeckt** — nachgesehen am 25. August 2026. Vier Klassen nennen sie im
 * Kommentar als *die* Schreibweise, das Panel ruft sie über die
 * Namensraumgrenze hinweg auf, `PgRemoteAccess` schickt jede Zeile hindurch:
 * Ein Fehler darin hätte den Fernzugriff jedes Kunden getroffen, und gemerkt
 * hätte es niemand.
 *
 * > **Eine Rechnung, auf die sich vier Stellen berufen, hat nicht deshalb einen
 * > Test, weil vier Stellen sie nennen.**
 *
 * ## Was hier geprüft wird und warum in dieser Teilung
 *
 * {@see Cidr} rechnet, {@see Hba} entscheidet. Der Unterschied ist an genau
 * einer Stelle sichtbar und wird unten auch dort gemessen: `0.0.0.0/0` kommt
 * durch die Rechnung und scheitert an der Politik.
 *
 * **Die Fälle mit gemischter Familie sind kein Beiwerk.** Eine Liste von Netzen
 * darf IPv4 und IPv6 mischen, und der Abgleich läuft über alle — würfe er bei
 * der falschen Familie, wäre eine gemischte Liste ein Fehlerfall statt einer
 * Antwort.
 */
final class CidrTest extends TestCase
{
    /**
     * Die kanonische Schreibweise.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function normalising(): array
    {
        return [
            ['192.0.2.7', '192.0.2.7/32'],
            ['2001:db8::1', '2001:db8::1/128'],
            ['192.0.2.0/24', '192.0.2.0/24'],
            ['2001:db8::/32', '2001:db8::/32'],

            // Ohne Politik: Die Rechnung sagt dazu nichts.
            ['0.0.0.0/0', '0.0.0.0/0'],

            // Die Schreibweise kommt zurück, wie `inet_ntop` sie schreibt —
            // nicht, wie sie hineinging.
            ['2001:0db8:0000::1', '2001:db8::1/128'],
        ];
    }

    #[DataProvider('normalising')]
    public function test_a_network_comes_back_in_its_canonical_form(string $raw, string $expected): void
    {
        $this->assertSame($expected, Cidr::normalize($raw));
    }

    /**
     * Was keine gültige Angabe ist.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function refusing(): array
    {
        return [
            ['192.0.2.7/24', 'gesetzte Wirtsbits — gemeint war der Rechner oder das Netz, nicht beides'],
            ['kein.netz', 'keine Adresse'],
            ['192.0.2.0/33', 'Präfixlänge über der Breite von IPv4'],
            ['2001:db8::/129', 'Präfixlänge über der Breite von IPv6'],
            ['192.0.2.0/x', 'Präfixlänge ist keine Zahl'],
        ];
    }

    #[DataProvider('refusing')]
    public function test_an_invalid_network_is_refused(string $raw, string $why): void
    {
        $this->expectException(AgentException::class);

        Cidr::normalize($raw);
    }

    /**
     * **Die eine Stelle, an der Rechnung und Politik auseinandergehen.**
     *
     * `0.0.0.0/0` ist eine gültige Angabe und für einen Datenbankzugang trotzdem
     * eine Ablehnung wert. Stünde die Ablehnung in der Rechnung, könnte die
     * Anmeldebeschränkung sie nicht anders entscheiden.
     */
    public function test_the_database_side_refuses_the_whole_internet(): void
    {
        $this->assertSame('0.0.0.0/0', Cidr::normalize('0.0.0.0/0'),
            'Die Rechnung soll dazu nichts sagen.');

        $this->expectException(AgentException::class);

        Hba::cidr('0.0.0.0/0');
    }

    /** Und sonst normalisiert die Datenbankseite unverändert. */
    public function test_the_database_side_normalises_as_before(): void
    {
        $this->assertSame('192.0.2.0/24', Hba::cidr('192.0.2.0/24'));
        $this->assertSame('192.0.2.7/32', Hba::cidr('192.0.2.7'));
    }

    /**
     * Der Abgleich.
     *
     * @return list<array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function matching(): array
    {
        return [
            ['192.0.2.0/24', '192.0.2.7', true, 'im Netz'],
            ['192.0.2.0/24', '192.0.3.7', false, 'daneben'],
            ['192.0.2.7/32', '192.0.2.7', true, 'genau dieser Rechner'],
            ['192.0.2.7/32', '192.0.2.8', false, 'der Rechner daneben'],
            ['2001:db8::/32', '2001:db8::1', true, 'IPv6 im Netz'],
            ['2001:db8::/32', '2001:db9::1', false, 'IPv6 daneben'],
            ['0.0.0.0/0', '203.0.113.9', true, 'alles'],

            // **Die Grenze innerhalb eines Bytes.** Eine Rechnung, die nur
            // ganze Bytes vergleicht, gäbe hier zweimal `true`.
            ['192.0.2.0/25', '192.0.2.127', true, 'oberes Ende von /25'],
            ['192.0.2.0/25', '192.0.2.128', false, 'erstes Byte hinter /25'],

            // Gemischte Familien: eine Antwort, kein Zwischenfall.
            ['192.0.2.0/24', '2001:db8::1', false, 'IPv6 gegen ein IPv4-Netz'],
            ['2001:db8::/32', '192.0.2.7', false, 'IPv4 gegen ein IPv6-Netz'],

            // Unlesbares: Auf „darf der herein" lautet die Antwort nein.
            ['192.0.2.0/24', 'unfug', false, 'keine Adresse'],
            ['kein-netz', '192.0.2.7', false, 'kein Netz'],
            ['192.0.2.0', '192.0.2.7', false, 'Netz ohne Präfixlänge'],
        ];
    }

    #[DataProvider('matching')]
    public function test_an_address_is_inside_a_network_or_it_is_not(
        string $network,
        string $address,
        bool $expected,
        string $case,
    ): void {
        $this->assertSame($expected, Cidr::contains($network, $address), $case);
    }
}
