<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dns\DesiredRecords;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Der Sollzustand steht an einer Stelle — und stimmt dort.
 *
 * **Zwei Regeln, und die zweite ist die, an der dieses Projekt sonst verliert.**
 * Die erste: Was eine Domain braucht, ist richtig ausgerechnet. Die zweite:
 * Es wird nur *hier* ausgerechnet. Eine Ansicht, die daneben ihre eigene Liste
 * baut, sieht monatelang gleich aus und läuft beim ersten Zusatz auseinander.
 *
 * **Und ein Fund, der diesen Durchgang überhaupt ausgelöst hat.** `docs/72 §2.1`
 * verlangte bis zum 21. August `A`/`AAAA` auch auf `www`, weil „der
 * Standard-Vhost beide bedient". Er tut es nicht: `Site::serverNames()` ist
 * `array_merge([$domain], $aliases)`, und ein automatisches `www` legt dieses
 * Panel nirgends an. Geschrieben hatte ich das aus der Annahme.
 *
 * > **Wissen aus zweiter Hand sieht aus wie Wissen** — auch das eigene von
 * > vorgestern.
 */
final class DesiredRecordSourceTest extends TestCase
{
    private const V4 = '203.0.113.10';

    private const V6 = '2001:db8::10';

    public function test_a_domain_needs_addresses_on_its_own_name(): void
    {
        $this->assertSame([
            ['name' => 'example.de', 'type' => 'A', 'expected' => [self::V4]],
            ['name' => 'example.de', 'type' => 'AAAA', 'expected' => [self::V6]],
        ], DesiredRecords::for('example.de', [self::V4, self::V6]));
    }

    /**
     * Ohne IPv6 des Servers entsteht **kein** `AAAA` — und nicht etwa eines
     * mit leerer Erwartung.
     *
     * Der Unterschied ist der zwischen „hier fehlt etwas" und „danach wird
     * nicht gefragt". Punkt 5 des Abnahmekriteriums hängt genau daran.
     */
    public function test_without_ipv6_no_quad_a_is_demanded(): void
    {
        $desired = DesiredRecords::for('example.de', [self::V4]);

        $this->assertSame([
            ['name' => 'example.de', 'type' => 'A', 'expected' => [self::V4]],
        ], $desired);

        foreach ($desired as $entry) {
            $this->assertNotSame('AAAA', $entry['type'], 'Es darf gar keinen AAAA-Eintrag geben.');
        }
    }

    /** Und die Gegenprobe: mit IPv6 steht er da. */
    public function test_with_ipv6_a_quad_a_is_demanded(): void
    {
        $types = array_column(DesiredRecords::for('example.de', [self::V4, self::V6]), 'type');

        $this->assertSame(['A', 'AAAA'], $types);
    }

    /**
     * Die erwarteten Adressen sind vereinheitlicht.
     *
     * **Ohne das ergäbe der Abgleich einen Befund, den es nicht gibt.**
     * `2001:0db8:0000::0001` und `2001:db8::1` sind dieselbe Adresse; die
     * gemessene Seite kommt aus `inet_ntop` und ist immer die kurze.
     */
    public function test_the_expected_addresses_are_written_the_way_they_are_measured(): void
    {
        $desired = DesiredRecords::for('example.de', ['2001:0db8:0000:0000:0000:0000:0000:0001']);

        $this->assertSame([['name' => 'example.de', 'type' => 'AAAA', 'expected' => ['2001:db8::1']]], $desired);
    }

    /** Was keine Adresse ist, wird stillschweigend weggelassen. */
    public function test_something_that_is_no_address_is_left_out(): void
    {
        $desired = DesiredRecords::for('example.de', ['keine-adresse', '', self::V4, '203.0.113.999']);

        $this->assertSame([['name' => 'example.de', 'type' => 'A', 'expected' => [self::V4]]], $desired);
    }

    /** Dieselbe Adresse zweimal steht einmal da. */
    public function test_the_same_address_twice_is_listed_once(): void
    {
        $desired = DesiredRecords::for('example.de', [self::V4, '203.0.113.10']);

        $this->assertSame([self::V4], $desired[0]['expected']);
    }

    /** Der Name wird vereinheitlicht — Grossschreibung und Schlusspunkt fallen weg. */
    public function test_the_name_is_normalised(): void
    {
        $this->assertSame('example.de', DesiredRecords::for('Example.DE.', [self::V4])[0]['name']);
        $this->assertSame([], DesiredRecords::for('  ', [self::V4]), 'ein leerer Name ergibt nichts');
    }

    /**
     * Mehrere Namen auf einmal — und jeder Name nur einmal gefragt.
     *
     * Zwei Zeilen mit demselben Namen gibt es nicht, aber eine Liste, die von
     * zwei Stellen zusammengesetzt wurde, kann es. Dieselbe Frage zweimal zu
     * stellen kostet einen fremden Nameserver.
     */
    public function test_several_names_at_once_and_each_only_once(): void
    {
        $desired = DesiredRecords::forAll(
            ['example.de', 'shop.example.de', 'EXAMPLE.DE.', ''],
            [self::V4],
        );

        $this->assertSame(
            [
                ['name' => 'example.de', 'type' => 'A', 'expected' => [self::V4]],
                ['name' => 'shop.example.de', 'type' => 'A', 'expected' => [self::V4]],
            ],
            $desired,
        );
    }

    /**
     * `CAA` gehört nicht in den Sollzustand.
     *
     * Es wird gelesen und nicht gefordert: **Kein CAA ist der richtige
     * Zustand**, und ein Sollwert dafür wäre eine Forderung, die dieses Panel
     * nicht stellen will.
     */
    public function test_caa_is_not_part_of_the_desired_state(): void
    {
        $types = array_column(DesiredRecords::for('example.de', [self::V4, self::V6]), 'type');

        $this->assertNotContains('CAA', $types);
    }

    /**
     * Und `www` ebenfalls nicht.
     *
     * **Der Fund, der `docs/72 §2.1` berichtigt hat.** Ein `www`, das der Kunde
     * will, legt er als Alias an — dann ist es eine eigene Zeile in `domains`
     * und steht von selbst im Sollzustand.
     */
    public function test_no_www_is_invented(): void
    {
        foreach (DesiredRecords::for('example.de', [self::V4, self::V6]) as $entry) {
            $this->assertSame('example.de', $entry['name'], 'Es wird kein Name erfunden.');
        }
    }

    /**
     * Und niemand sonst rechnet den Sollzustand aus.
     *
     * **Das ist die eigentliche Regel dieses Durchgangs.** Der Fehler, der hier
     * am häufigsten kostet, ist derselbe Gedanke an zwei Orten: eine Ansicht,
     * die ihre eigene Liste baut, weil es gerade schneller ging.
     */
    public function test_only_one_place_works_out_what_a_domain_needs(): void
    {
        $found = [];
        $seen = 0;

        foreach ($this->sources() as $relative => $source) {
            $seen++;

            if ($relative === 'app/Support/Dns/DesiredRecords.php') {
                continue;
            }

            /*
             * **Zwei Formen, weil ein Nachbau nicht wissen kann, wonach
             * gesucht wird.** Die erste ist die Liste, wie sie jemand in einem
             * Controller hinschreibt; die zweite ist der Satztyp selbst.
             * Gegen den einen Ausdruck allein käme ein Nachbau durch, der
             * seine Typen in einer Konstante oder einem `match` führt — und
             * das ist keine ausgefallene Schreibweise, sondern die
             * aufgeräumtere.
             *
             * > **Ein Wächter, der eine Form sucht, findet die andere nicht —
             * > und meldet Grün für die aufgeräumtere Fassung des Fehlers.**
             */
            if (preg_match("/'type'\s*=>\s*'(A|AAAA)'/", $source) === 1
                || preg_match('/([\'"])AAAA\1/', $source) === 1) {
                $found[] = $relative;
            }
        }

        $this->assertGreaterThan(50, $seen, 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Dateien bauen den Sollzustand selbst:\n  %s\n\n".
            'Was eine Domain braucht, steht in App\\Support\\Dns\\DesiredRecords. Eine zweite Liste '.
            'daneben sieht monatelang gleich aus und läuft beim ersten Zusatz auseinander.',
            implode("\n  ", $found),
        ));
    }

    /** @return iterable<string, string> */
    private function sources(): iterable
    {
        $root = dirname(__DIR__, 2);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield str_replace($root.'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}
