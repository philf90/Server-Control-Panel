<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Packet;

/**
 * A, AAAA und CAA aus gebauten Paketen — die drei Typen, die P7 dazubekommt.
 *
 * **Warum gebaut und nicht gefragt** — derselbe Grund wie bei
 * {@see DnsPacketTest}: Ein Durchgang gegen einen echten Nameserver bräuchte
 * Netz in der CI und würde bei jedem Aussetzer rot. Vor allem aber liessen sich
 * die Fälle, um die es hier geht, gar nicht bestellen: eine Adresse mit drei
 * Bytes, ein CAA mit einer Marke, die über den Satz hinausreicht, ein
 * `A`-Satz in einer anderen Klasse.
 *
 * **Und es sind genau die Fälle, die etwas kaputt machen, ohne aufzufallen.**
 * Ein falsch gelesenes Rdata nimmt keine Zone vom Netz — es zeigt dem Kunden
 * einen Zustand, den es nicht gibt, und schickt ihn dorthin, wo nichts zu
 * ändern ist (`docs/72 §9`).
 *
 * > **Ein roter Rand am Feld behauptet, das Feld sei falsch. Wer ihn für einen
 * > Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern
 * > ist.**
 */
final class RecordRdataTest extends TestCase
{
    private const ID = 0x4711;

    private const NAME = 'www.example.de';

    /** Zeigt auf Offset 12 — den Namen in der Frage. */
    private const POINTER = "\xc0\x0c";

    /**
     * Ein Name im Drahtformat.
     *
     * **Nicht `name()`.** Der gehört PHPUnit und ist dort `final`; die Datei
     * liesse sich damit nicht einmal laden. Vierter Fall dieser Art in diesem
     * Projekt, und `BaseMethodClashTest` hält ihn seitdem fest.
     */
    private function encoded(string $name): string
    {
        $encoded = '';

        foreach (explode('.', trim($name, '.')) as $label) {
            $encoded .= chr(strlen($label)).$label;
        }

        return $encoded."\0";
    }

    private function record(string $nameField, int $type, string $rdata, int $class = Packet::CLASS_IN): string
    {
        return $nameField.pack('n2Nn', $type, $class, 300, strlen($rdata)).$rdata;
    }

    /** @param list<string> $answers */
    private function answer(array $answers, int $type, int $id = self::ID, int $flags = 0x8400): string
    {
        $packet = pack('n6', $id, $flags, 1, count($answers), 0, 0);
        $packet .= $this->encoded(self::NAME).pack('n2', $type, Packet::CLASS_IN);

        return $packet.implode('', $answers);
    }

    // ------------------------------------------------------------------
    // A und AAAA
    // ------------------------------------------------------------------

    public function test_an_ipv4_address_is_read(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.10') ?: '')],
            Packet::TYPE_A,
        );

        $this->assertSame(['203.0.113.10'], Packet::addresses($answer, self::ID, Packet::TYPE_A));
    }

    public function test_an_ipv6_address_comes_back_in_its_short_form(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_AAAA, inet_pton('2001:0db8:0000:0000:0000:0000:0000:0001') ?: '')],
            Packet::TYPE_AAAA,
        );

        // **Das ist der Grund für `inet_ntop`.** Ausgeschrieben und verkürzt
        // sind dieselbe Adresse und nicht dieselbe Zeichenkette; der Abgleich
        // vergleicht später Zeichenketten.
        $this->assertSame(['2001:db8::1'], Packet::addresses($answer, self::ID, Packet::TYPE_AAAA));
    }

    public function test_several_addresses_under_one_name(): void
    {
        $answer = $this->answer([
            $this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.10') ?: ''),
            $this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.11') ?: ''),
        ], Packet::TYPE_A);

        // Ein Satz mit mehreren Werten wird als Menge gelesen und nicht am
        // ersten — `docs/72 §9` nennt genau den Fall, in dem nur eine der
        // Adressen hierher zeigt.
        $this->assertSame(
            ['203.0.113.10', '203.0.113.11'],
            Packet::addresses($answer, self::ID, Packet::TYPE_A),
        );
    }

    public function test_the_same_address_twice_is_listed_once(): void
    {
        $answer = $this->answer([
            $this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.10') ?: ''),
            $this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.10') ?: ''),
        ], Packet::TYPE_A);

        $this->assertSame(['203.0.113.10'], Packet::addresses($answer, self::ID, Packet::TYPE_A));
    }

    /**
     * Eine Adresse mit der falschen Zahl von Bytes ergibt kein Ergebnis.
     *
     * **Zwei dieser Fälle sind die eigentlichen**, und sie sind beim
     * Gegenprüfen aufgefallen: Ohne die Längenprüfung wurden nur `A mit
     * sechzehn Bytes` und `AAAA mit vier Bytes` rot, die übrigen vier nicht.
     * `inet_ntop` weist nämlich nicht jede falsche Länge ab — es entscheidet
     * die **Adressfamilie an der Länge** und gibt für vier und sechzehn Bytes
     * immer etwas zurück. Ein `A`-Satz mit sechzehn Bytes käme also als
     * IPv6-Adresse heraus.
     *
     * Die anderen vier bleiben trotzdem stehen: Sie belegen, dass der Weg auch
     * dort kein halbes Ergebnis liefert, und sie kosten nichts.
     *
     * @param  int  $type  Der gefragte Typ
     * @param  int  $bytes  So viele Bytes stehen im Satz
     */
    #[DataProvider('falscheLaengen')]
    public function test_an_address_of_the_wrong_length_is_no_address(int $type, int $bytes): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, $type, str_repeat("\x01", $bytes))],
            $type,
        );

        $this->assertSame([], Packet::addresses($answer, self::ID, $type));
    }

    /** @return iterable<string, array{int, int}> */
    public static function falscheLaengen(): iterable
    {
        yield 'A mit drei Bytes' => [Packet::TYPE_A, 3];
        yield 'A mit fünf Bytes' => [Packet::TYPE_A, 5];
        yield 'A mit null Bytes' => [Packet::TYPE_A, 0];
        yield 'A mit sechzehn Bytes' => [Packet::TYPE_A, 16];
        yield 'AAAA mit vier Bytes' => [Packet::TYPE_AAAA, 4];
        yield 'AAAA mit fünfzehn Bytes' => [Packet::TYPE_AAAA, 15];
    }

    /**
     * Ein heiler Satz neben dem kaputten kommt trotzdem durch.
     *
     * **Ohne diesen Fall wäre der vorige keine Messung.** „Kein Ergebnis" sähe
     * genauso aus, wenn die Satzwanderung beim ersten kaputten Satz abbräche —
     * und dann verlöre eine einzige krumme Antwort die ganze Auskunft.
     */
    public function test_a_broken_record_does_not_swallow_the_healthy_one(): void
    {
        $answer = $this->answer([
            $this->record(self::POINTER, Packet::TYPE_A, str_repeat("\x01", 3)),
            $this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.10') ?: ''),
        ], Packet::TYPE_A);

        $this->assertSame(['203.0.113.10'], Packet::addresses($answer, self::ID, Packet::TYPE_A));
    }

    /**
     * Ein Satz in einer anderen Klasse zählt nicht.
     *
     * Klasse 3 ist `CH` (Chaos). Praktisch kommt das nicht vor — und genau
     * deshalb steht die Prüfung hier: Was nie vorkommt, prüft auch niemand von
     * Hand.
     */
    public function test_a_record_of_another_class_is_skipped(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.10') ?: '', 3)],
            Packet::TYPE_A,
        );

        $this->assertSame([], Packet::addresses($answer, self::ID, Packet::TYPE_A));
    }

    /**
     * Die Adressen des Ziels eines `CNAME` stehen unter dessen Namen.
     *
     * **Und werden mitgelesen, absichtlich.** Gefragt war, wohin `www` am Ende
     * auflöst; die Antwort enthält den `CNAME` und darunter die `A`-Sätze des
     * Ziels. Ein Vergleich des Eigentümernamens würde hier genau das
     * wegwerfen, was die Frage beantwortet.
     */
    public function test_addresses_behind_a_cname_are_read(): void
    {
        $cname = 5;

        $answer = $this->answer([
            $this->record(self::POINTER, $cname, $this->encoded('ziel.example.de')),
            $this->record($this->encoded('ziel.example.de'), Packet::TYPE_A, inet_pton('203.0.113.10') ?: ''),
        ], Packet::TYPE_A);

        $this->assertSame(['203.0.113.10'], Packet::addresses($answer, self::ID, Packet::TYPE_A));
    }

    // ------------------------------------------------------------------
    // CAA
    // ------------------------------------------------------------------

    public function test_a_caa_record_is_read(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_CAA, $this->caa(0, 'issue', 'letsencrypt.org'))],
            Packet::TYPE_CAA,
        );

        $this->assertSame(
            [['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']],
            Packet::authorities($answer, self::ID),
        );
    }

    /**
     * Die Marke wird kleingeschrieben verglichen.
     *
     * RFC 8659 §4.1 sagt, sie sei unabhängig von der Schreibweise. Wer sie
     * nimmt, wie sie ankommt, übersieht ein `ISSUE` und meldet „kein CAA" —
     * also grün für eine Zone, die jede Bestellung abweist.
     */
    public function test_the_tag_is_compared_in_lower_case(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_CAA, $this->caa(128, 'ISSUE', 'pki.example'))],
            Packet::TYPE_CAA,
        );

        $this->assertSame(
            [['flags' => 128, 'tag' => 'issue', 'value' => 'pki.example']],
            Packet::authorities($answer, self::ID),
        );
    }

    public function test_a_caa_with_an_empty_value_is_read(): void
    {
        // `issue ";"` verbietet jede Ausstellung — der Wert ist da, aber kurz.
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_CAA, $this->caa(0, 'issue', ';'))],
            Packet::TYPE_CAA,
        );

        $this->assertSame([['flags' => 0, 'tag' => 'issue', 'value' => ';']], Packet::authorities($answer, self::ID));
    }

    /**
     * Ein krummer CAA-Satz ergibt kein Ergebnis statt eines falschen.
     *
     * @param  string  $rdata  Die Bytes des Satzes
     */
    #[DataProvider('krummeCaa')]
    public function test_a_malformed_caa_yields_nothing(string $rdata): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_CAA, $rdata)],
            Packet::TYPE_CAA,
        );

        $this->assertSame([], Packet::authorities($answer, self::ID));
    }

    /** @return iterable<string, array{string}> */
    public static function krummeCaa(): iterable
    {
        yield 'leer' => [''];
        yield 'nur das Flag' => ["\x00"];
        yield 'Marke der Länge null' => ["\x00\x00issue"];
        yield 'Marke reicht über den Satz hinaus' => ["\x00\x40issue"];
        yield 'Marke genau eins zu lang' => ["\x00\x06issue"];
    }

    /**
     * Und die Gegenprobe dazu: eine Marke, die genau passt, kommt durch.
     *
     * Ohne sie hiesse „Marke genau eins zu lang" nur, dass irgendetwas an
     * dieser Länge scheitert.
     */
    public function test_a_tag_that_fills_the_record_exactly_is_read(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_CAA, "\x00\x05issue")],
            Packet::TYPE_CAA,
        );

        $this->assertSame([['flags' => 0, 'tag' => 'issue', 'value' => '']], Packet::authorities($answer, self::ID));
    }

    // ------------------------------------------------------------------
    // Der Antwortcode
    // ------------------------------------------------------------------

    /**
     * Drei Zustände statt zwei.
     *
     * `docs/72 §2.3` verlangt „zeigt hierher", „zeigt woandershin" und „fehlt"
     * — und daneben „nicht erreichbar". Ohne den Antwortcode wäre das letzte
     * von „fehlt" nicht zu unterscheiden, weil beide eine leere Liste ergeben.
     */
    public function test_the_response_code_comes_through(): void
    {
        $noerror = $this->answer([], Packet::TYPE_A, flags: 0x8400);
        $nxdomain = $this->answer([], Packet::TYPE_A, flags: 0x8403);

        $this->assertSame(Packet::RCODE_NOERROR, Packet::rcode($noerror, self::ID));
        $this->assertSame(Packet::RCODE_NXDOMAIN, Packet::rcode($nxdomain, self::ID));
    }

    /**
     * Eine unbrauchbare Antwort hat keinen Code.
     *
     * `null` heisst „es kam nichts an, das zu dieser Frage gehört" — und das
     * ist ein anderer Zustand als „der Name existiert nicht".
     */
    public function test_an_unusable_answer_has_no_code(): void
    {
        $this->assertNull(Packet::rcode('', self::ID), 'leer');
        $this->assertNull(Packet::rcode('abc', self::ID), 'zu kurz');
        $this->assertNull(
            Packet::rcode($this->answer([], Packet::TYPE_A, id: 0x9999), self::ID),
            'Antwort auf eine andere Frage',
        );
        $this->assertNull(
            Packet::rcode($this->answer([], Packet::TYPE_A, flags: 0x8600), self::ID),
            'abgeschnitten',
        );
    }

    /**
     * Und dieselben vier Fälle geben auch keine Sätze zurück.
     *
     * Die Prüfung des Kopfes steht seit P7 an einer Stelle für beide Wege; ohne
     * diesen Durchgang wäre nicht belegt, dass der zweite sie auch benutzt.
     */
    public function test_an_unusable_answer_yields_no_records(): void
    {
        $satz = $this->record(self::POINTER, Packet::TYPE_A, inet_pton('203.0.113.10') ?: '');

        $this->assertSame([], Packet::addresses('', self::ID, Packet::TYPE_A));
        $this->assertSame(
            [],
            Packet::addresses($this->answer([$satz], Packet::TYPE_A, id: 0x9999), self::ID, Packet::TYPE_A),
            'Antwort auf eine andere Frage',
        );
        $this->assertSame(
            [],
            Packet::addresses($this->answer([$satz], Packet::TYPE_A, flags: 0x8600), self::ID, Packet::TYPE_A),
            'abgeschnitten',
        );

        // Gegenprobe: dieselbe Antwort ohne Mangel liefert den Satz.
        $this->assertSame(
            ['203.0.113.10'],
            Packet::addresses($this->answer([$satz], Packet::TYPE_A), self::ID, Packet::TYPE_A),
        );
    }

    /**
     * Ein Satz, der über das Paketende hinausragt, wird nicht halb gelesen.
     */
    public function test_a_record_running_past_the_end_is_not_read_in_half(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_AAAA, inet_pton('2001:db8::1') ?: '')],
            Packet::TYPE_AAAA,
        );

        $this->assertSame(
            [],
            Packet::addresses(substr($answer, 0, strlen($answer) - 8), self::ID, Packet::TYPE_AAAA),
        );
    }

    /** Ein CAA-Satz aus seinen drei Teilen. */
    private function caa(int $flags, string $tag, string $value): string
    {
        return chr($flags).chr(strlen($tag)).$tag.$value;
    }
}
