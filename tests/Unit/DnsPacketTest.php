<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Packet;

/**
 * Das Drahtformat einer DNS-Antwort — gegen gebaute Pakete.
 *
 * **Warum gebaut und nicht gefragt.** Ein Durchgang gegen einen echten
 * Nameserver bräuchte Netz in der CI, eine Zone, die es gibt, und würde bei
 * jedem Aussetzer rot — und die Fälle, um die es hier geht, liessen sich gar
 * nicht bestellen: ein Paket mit gesetztem Abschneidebit, eine Antwort auf
 * eine fremde Frage, ein Satz, der über das Paketende hinausragt.
 *
 * **Der Zeiger ist der Fall, für den dieser Durchgang existiert.** Ein Name in
 * einer Antwort steht selten ausgeschrieben da; meistens sind es zwei Bytes,
 * die auf eine frühere Stelle zeigen (RFC 1035 §4.1.4). Wer das nicht erkennt,
 * liest die folgenden Felder um einige Bytes verschoben und bekommt Werte, die
 * fast stimmen — und im Protokoll steht nichts, was darauf hindeutet.
 */
final class DnsPacketTest extends TestCase
{
    private const ID = 0x1234;

    private const NAME = '_acme-challenge.example.de';

    /** Der Wert, wie ihn die Zertifizierungsstelle sehen will: 43 Zeichen. */
    private const VALUE = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    /** Zeigt auf Offset 12 — den Namen in der Frage. */
    private const POINTER = "\xc0\x0c";

    private function name(string $name): string
    {
        $encoded = '';

        foreach (explode('.', trim($name, '.')) as $label) {
            $encoded .= chr(strlen($label)).$label;
        }

        return $encoded."\0";
    }

    /** Der Inhalt eines TXT-Satzes: Zeichenketten mit vorangestellter Länge. */
    private function rdata(string ...$pieces): string
    {
        $data = '';

        foreach ($pieces as $piece) {
            $data .= chr(strlen($piece)).$piece;
        }

        return $data;
    }

    private function record(string $nameField, int $type, string $rdata): string
    {
        return $nameField.pack('n2Nn', $type, 1, 300, strlen($rdata)).$rdata;
    }

    /** @param list<string> $answers */
    private function answer(array $answers, int $id = self::ID, int $flags = 0x8400): string
    {
        $packet = pack('n6', $id, $flags, 1, count($answers), 0, 0);
        $packet .= $this->name(self::NAME).pack('n2', Packet::TYPE_TXT, Packet::CLASS_IN);

        return $packet.implode('', $answers);
    }

    public function test_the_question_asks_for_txt_without_recursion(): void
    {
        $query = Packet::query(self::ID, self::NAME);
        $header = unpack('nid/nflags/nqd/nan/nns/nar', $query) ?: [];

        $this->assertSame(self::ID, $header['id'] ?? null);

        // Gefragt wird ein Server, der die Zone selbst führt — er soll aus
        // seinem Bestand antworten und nicht anderswo nachsehen.
        $this->assertSame(0, $header['flags'] ?? null);
        $this->assertSame(1, $header['qd'] ?? null);

        $this->assertStringContainsString("\x0f_acme-challenge\x07example\x02de\x00", $query);
        $this->assertSame(pack('n2', Packet::TYPE_TXT, Packet::CLASS_IN), substr($query, -4));
    }

    public function test_a_compressed_name_is_read_correctly(): void
    {
        $answer = $this->answer([
            $this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata(self::VALUE)),
        ]);

        $this->assertSame([self::VALUE], Packet::txt($answer, self::ID));
    }

    public function test_a_name_written_out_is_read_correctly(): void
    {
        $answer = $this->answer([
            $this->record($this->name(self::NAME), Packet::TYPE_TXT, $this->rdata(self::VALUE)),
        ]);

        $this->assertSame([self::VALUE], Packet::txt($answer, self::ID));
    }

    /** Ein Satz anderer Art wird übersprungen und nicht mitgelesen. */
    public function test_another_record_type_is_skipped(): void
    {
        $answer = $this->answer([
            $this->record(self::POINTER, 1, "\x7f\x00\x00\x01"),
            $this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata(self::VALUE)),
        ]);

        $this->assertSame([self::VALUE], Packet::txt($answer, self::ID));
    }

    /**
     * Zwei Werte unter demselben Namen — der Regelfall bei einem Platzhalter.
     *
     * `example.de` und `*.example.de` in einer Bestellung ergeben zwei
     * Prüfwerte unter `_acme-challenge.example.de`, und beide müssen dastehen.
     */
    public function test_two_values_under_one_name(): void
    {
        $answer = $this->answer([
            $this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata('eins')),
            $this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata('zwei')),
        ]);

        $this->assertSame(['eins', 'zwei'], Packet::txt($answer, self::ID));
    }

    /** Ein Wert darf in Stücken kommen — zusammengesetzt wird er hier. */
    public function test_a_value_split_into_pieces_is_joined(): void
    {
        $answer = $this->answer([
            $this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata('teil-eins', 'teil-zwei')),
        ]);

        $this->assertSame(['teil-einsteil-zwei'], Packet::txt($answer, self::ID));
    }

    /** Über UDP kann alles ankommen — eine fremde Antwort ist keine. */
    public function test_an_answer_to_another_question_is_refused(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata(self::VALUE))],
            0x9999,
        );

        $this->assertSame([], Packet::txt($answer, self::ID));
    }

    /**
     * Abgeschnitten heisst „noch nicht" und nicht „nein".
     *
     * Die Antwort passte nicht in ein UDP-Paket. Der Aufrufer fragt gleich
     * noch einmal; einen halben Wert weiterzugeben wäre hier das Schlimmere.
     */
    public function test_a_truncated_answer_yields_nothing(): void
    {
        $answer = $this->answer(
            [$this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata(self::VALUE))],
            self::ID,
            0x8600,
        );

        $this->assertSame([], Packet::txt($answer, self::ID));
    }

    /** Und was gar kein Paket ist, ergibt kein Ergebnis und keinen Absturz. */
    public function test_garbage_yields_nothing(): void
    {
        $this->assertSame([], Packet::txt('', self::ID));
        $this->assertSame([], Packet::txt('abc', self::ID));

        $answer = $this->answer([
            $this->record(self::POINTER, Packet::TYPE_TXT, $this->rdata(self::VALUE)),
        ]);

        // Ein Satz, der über das Paketende hinausragt, wird nicht halb
        // gelesen — sonst käme ein Wert heraus, der fast stimmt.
        $this->assertSame([], Packet::txt(substr($answer, 0, strlen($answer) - 10), self::ID));
    }
}
