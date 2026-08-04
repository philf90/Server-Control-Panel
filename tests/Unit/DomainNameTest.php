<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\DomainName;

/**
 * Der Angriffsdurchgang gegen die Namensregel — dieselbe Gewohnheit wie in
 * {@see GuardTest}, nur für den Wert, aus dem in P3 ein `server_name`, ein
 * Verzeichnisname und ein Protokolldateiname wird.
 *
 * Die Liste unten ist die Sammlung dessen, was an einem Domainnamen schiefgehen
 * kann, wenn er ungeprüft weiterläuft: Pfade, Kommandozeilen, Platzhalter,
 * Adressen. Jeder Eintrag ist eine Zeile, die ohne diese Prüfung in einer
 * Konfigurationsdatei stünde, die als root gelesen wird.
 */
final class DomainNameTest extends TestCase
{
    /** @return list<array{0:string, 1:string}> */
    public static function validNames(): array
    {
        return [
            ['beispiel.de', 'beispiel.de'],

            // Normalform: Grossschreibung, abschliessender Punkt der
            // DNS-Notation und Leerraum sind kein anderer Name.
            ['Beispiel.DE', 'beispiel.de'],
            ['beispiel.de.', 'beispiel.de'],
            ['  beispiel.de  ', 'beispiel.de'],

            ['shop.beispiel.de', 'shop.beispiel.de'],
            ['a-b.beispiel-zwei.de', 'a-b.beispiel-zwei.de'],
            ['x1.co.uk', 'x1.co.uk'],

            // Punycode — Umlautdomains kommen so an, umgewandelt wird im Panel.
            ['xn--mllerstrae-w5b.de', 'xn--mllerstrae-w5b.de'],
            ['beispiel.xn--vermgensberatung-pwb', 'beispiel.xn--vermgensberatung-pwb'],
        ];
    }

    #[DataProvider('validNames')]
    public function test_accepts_and_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, DomainName::normalize($input));
    }

    /** @return list<array{0:string}> */
    public static function rejectedNames(): array
    {
        return [
            [''],
            ['.'],
            ['..'],

            // Ohne Punkt ist es ein Rechnername. `localhost` als vhost
            // beantwortete jede Anfrage, die keinen anderen Namen trifft.
            ['localhost'],

            // Pfade und Kommandozeilen.
            ['../../etc/nginx'],
            ['/etc/passwd'],
            ['beispiel.de/../etc'],
            ['beispiel.de; rm -rf /'],
            ['beispiel.de && reboot'],
            ['$(reboot).de'],
            ['`reboot`.de'],

            // Was in einer nginx-Konfiguration den Block beenden würde.
            ["beispiel.de;\n    root /etc;"],
            ['beispiel.de }'],
            ['beispiel.de #kommentar'],

            // Platzhalter: gültiger server_name, unmöglicher Verzeichnisname.
            ['*.beispiel.de'],
            ['~^www\.(.+)$'],

            // Adressen sind keine Domains.
            ['192.168.0.1'],
            ['::1'],

            // Bindestrich am Rand, doppelter Punkt, Unterstrich, Leerzeichen.
            ['-beispiel.de'],
            ['beispiel-.de'],
            ['beispiel..de'],
            ['bei_spiel.de'],
            ['beispiel .de'],

            // Nicht-ASCII: Umlautdomains gehören in Punycode umgewandelt,
            // bevor sie den Agenten erreichen — er hat kein intl (§4.1).
            ['müller.de'],

            // Ein Label über 63 Zeichen, ein Name über 253.
            [str_repeat('a', 64).'.de'],
            [str_repeat('a.', 130).'de'],

            // Die letzte Stelle trägt Buchstaben.
            ['beispiel.12'],
            ['beispiel.d'],
        ];
    }

    #[DataProvider('rejectedNames')]
    public function test_rejects(string $name): void
    {
        $this->expectException(AgentException::class);

        DomainName::normalize($name);
    }

    public function test_rejects_values_that_are_not_strings(): void
    {
        $this->expectException(AgentException::class);

        DomainName::normalize(['beispiel.de']);
    }

    /**
     * Der Vergleich, bei dem `str_ends_with` allein falsch liegt.
     *
     * `bösebeispiel.de` endet auf `beispiel.de` und ist trotzdem eine fremde
     * Domain. Ohne den Punkt im Vergleich liesse sich ein fremder Name als
     * Subdomain eines eigenen eintragen — und damit ein vhost für eine
     * Domain anlegen, die einem anderen gehört.
     */
    public function test_below_compares_labels_and_not_characters(): void
    {
        $this->assertTrue(DomainName::isBelow('shop.beispiel.de', 'beispiel.de'));
        $this->assertTrue(DomainName::isBelow('a.b.beispiel.de', 'beispiel.de'));

        $this->assertFalse(DomainName::isBelow('boesebeispiel.de', 'beispiel.de'));
        $this->assertFalse(DomainName::isBelow('beispiel.de', 'beispiel.de'));
        $this->assertFalse(DomainName::isBelow('beispiel.de', 'shop.beispiel.de'));
    }
}
