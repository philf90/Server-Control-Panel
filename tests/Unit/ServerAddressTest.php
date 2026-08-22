<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dns\ServerAddresses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Welche Adressen als Sollwert einer Kundendomain taugen.
 *
 * **Die Grenzfälle sind gemessen und nicht nachgelesen** (21. August 2026).
 * `filter_var` mit `NO_PRIV_RANGE|NO_RES_RANGE` wirft privat, link-lokal,
 * Rückschleife, unbestimmt und ULA hinaus — **und lässt CGNAT und Multicast
 * durch.** Genau diese beiden stehen unten als eigene Fälle, weil sie der
 * Grund sind, dass diese Klasse mehr tut als ein `filter_var`.
 *
 * > **Ein Filter, den man für vollständig hält, ist eine Lücke mit
 * > Fussnote.**
 *
 * Ein Server hinter Anbieter-NAT ist von aussen nicht erreichbar; seine
 * Adresse als `A`-Eintrag wäre ein Sollwert, den keine Domain je erfüllen
 * kann — und der Abgleich meldete jede als „zeigt woandershin".
 */
final class ServerAddressTest extends TestCase
{
    /** @param string $address Was geprüft wird */
    #[DataProvider('brauchbare')]
    public function test_a_routable_address_is_kept(string $address): void
    {
        $this->assertNull(ServerAddresses::rejected($address), $address.' sollte durchgehen.');
        $this->assertNotSame([], ServerAddresses::routable([$address]));
    }

    /** @return iterable<string, array{string}> */
    public static function brauchbare(): iterable
    {
        yield 'IPv4' => ['203.0.113.10'];
        yield 'IPv4 ausserhalb 172.16/12' => ['172.32.0.1'];
        yield 'ein echter Anbieter' => ['8.8.8.8'];
        yield 'IPv6' => ['2001:db8::1'];
        yield 'IPv6 eines Anbieters' => ['2a01:4f8::1'];
    }

    /**
     * @param  string  $address  Was geprüft wird
     * @param  string  $grund  Ein Stück des Satzes, den der Betreiber liest
     */
    #[DataProvider('unbrauchbare')]
    public function test_an_unroutable_address_is_refused_with_a_reason(string $address, string $grund): void
    {
        $meldung = ServerAddresses::rejected($address);

        $this->assertNotNull($meldung, $address.' sollte abgewiesen werden.');
        $this->assertStringContainsString($grund, $meldung);
        $this->assertSame([], ServerAddresses::routable([$address]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unbrauchbare(): iterable
    {
        yield 'privat 10/8' => ['10.1.2.3', 'privat'];
        yield 'privat 172.16/12' => ['172.16.0.1', 'privat'];
        yield 'privat 192.168/16' => ['192.168.1.1', 'privat'];
        yield 'link-lokal' => ['169.254.1.1', 'privat'];
        yield 'Rückschleife' => ['127.0.0.1', 'privat'];
        yield 'unbestimmt' => ['0.0.0.0', 'privat'];
        yield 'ULA' => ['fd00::1', 'privat'];
        yield 'link-lokal v6' => ['fe80::1', 'privat'];
        yield 'Rückschleife v6' => ['::1', 'privat'];

        // **Die zwei, die filter_var durchlässt.** Sie sind der Grund für
        // diese Klasse.
        yield 'CGNAT' => ['100.64.0.1', 'Anbieter-NAT'];
        yield 'CGNAT am oberen Rand' => ['100.127.255.254', 'Anbieter-NAT'];
        yield 'Multicast v4' => ['224.0.0.1', 'Multicast'];
        yield 'Multicast v6' => ['ff02::1', 'Multicast'];

        yield 'gar keine Adresse' => ['nichts', 'keine IP-Adresse'];
        yield 'leer' => ['', 'leer'];
    }

    /**
     * `100.128.0.0` liegt **ausserhalb** von `100.64.0.0/10` — die Gegenprobe
     * zur CGNAT-Regel.
     *
     * Ohne sie hiesse „CGNAT wird abgewiesen" womöglich nur „alles, was mit
     * 100 anfängt, wird abgewiesen".
     */
    public function test_just_outside_the_carrier_grade_range_is_kept(): void
    {
        $this->assertNull(ServerAddresses::rejected('100.128.0.1'));
        $this->assertNull(ServerAddresses::rejected('100.63.255.254'));
    }

    /** Die Adressen kommen in derselben Schreibweise heraus wie auf der gemessenen Seite. */
    public function test_the_addresses_are_written_the_way_they_are_measured(): void
    {
        $this->assertSame(
            ['2001:db8::1'],
            ServerAddresses::routable(['2001:0db8:0000:0000:0000:0000:0000:0001']),
        );
    }

    public function test_the_same_address_twice_is_listed_once(): void
    {
        $this->assertSame(['203.0.113.10'], ServerAddresses::routable(['203.0.113.10', '203.0.113.10']));
    }

    // ------------------------------------------------------------------
    // Abgeleitet und übersteuert
    // ------------------------------------------------------------------

    /** Ohne Eintrag gilt das Abgeleitete. */
    public function test_without_an_override_the_derived_addresses_count(): void
    {
        $this->assertSame(
            ['203.0.113.10'],
            ServerAddresses::effective(['203.0.113.10', '10.1.2.3'], []),
        );
    }

    /**
     * Und das Abgeleitete wird dabei gesiebt.
     *
     * Eine private Adresse von einer Schnittstelle ist kein Sollwert für eine
     * Kundendomain — sie wäre einer, den keine Domain je erfüllen kann.
     */
    public function test_the_derived_addresses_are_filtered(): void
    {
        $this->assertSame([], ServerAddresses::effective(['192.168.1.10', 'fe80::1'], []));
    }

    /**
     * Mit Eintrag gilt der Eintrag — auch wenn der Server etwas anderes führt.
     *
     * **Das ist der NAT-Fall**, für den es die Übersteuerung überhaupt gibt:
     * Der Server sieht nur `10.0.0.5`, erreichbar ist er unter `203.0.113.10`.
     */
    public function test_an_override_wins_over_what_the_server_sees(): void
    {
        $this->assertSame(
            ['203.0.113.10'],
            ServerAddresses::effective(['10.0.0.5'], ['203.0.113.10']),
        );
    }

    /**
     * Der Eintrag wird beim Lesen **nicht** noch einmal gesiebt.
     *
     * **Geprüft wird er beim Eintragen** ({@see ServerAddresses::rejected}).
     * Wer ihn hier ein zweites Mal siebte, hätte zwei Fassungen derselben
     * Regel — und die zweite entschiede stillschweigend anders, als die
     * Meldung am Formular gesagt hat.
     *
     * > **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist
     * > die, die veraltet.**
     */
    public function test_the_override_is_trusted_because_it_was_checked_when_it_was_entered(): void
    {
        $this->assertSame(
            ['10.0.0.5'],
            ServerAddresses::effective(['203.0.113.10'], ['10.0.0.5']),
            'Der Eintrag gilt — geprüft wurde er am Formular.',
        );
    }

    /** Auch der Eintrag kommt vereinheitlicht heraus. */
    public function test_the_override_is_written_the_way_it_is_measured(): void
    {
        $this->assertSame(
            ['2001:db8::1'],
            ServerAddresses::effective([], ['2001:0db8::0001']),
        );
    }
}
