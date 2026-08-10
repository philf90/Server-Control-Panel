<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\XmlRpc;
use SrvPanel\Agent\AgentException;
use Tests\Support\WithoutPhpComments;

/**
 * XML-RPC — so wenig davon wie nötig, und geprüft in beide Richtungen.
 *
 * **Beim Schreiben ist die Gefahr klein**, denn was hinausgeht, kommt aus dem
 * Code; geprüft wird trotzdem die Maskierung, weil ein Zonenname aus einer
 * Kundeneingabe stammt.
 *
 * **Beim Lesen ist sie gross.** Was hereinkommt, bestimmt die Gegenstelle, und
 * dieser Prozess läuft als root. Die zwei Vorkehrungen dagegen — keine
 * Entitäten, gedeckelte Tiefe — haben hier je einen eigenen Fall.
 */
final class XmlRpcTest extends TestCase
{
    use WithoutPhpComments;

    private static function envelope(string $inner): string
    {
        return '<?xml version="1.0"?><methodResponse><params><param><value><struct>'.
            $inner.'</struct></value></param></params></methodResponse>';
    }

    public function test_a_call_carries_its_method_and_flat_params(): void
    {
        $xml = XmlRpc::request('account.login', ['user' => 'wer', 'pass' => 'geheim']);

        $this->assertStringContainsString('<methodName>account.login</methodName>', $xml);
        $this->assertStringContainsString(
            '<member><name>user</name><value><string>wer</string></value></member>',
            $xml,
        );
    }

    /** Eine Zahl geht als `int` hinaus und nicht als Text — INWX unterscheidet das. */
    public function test_an_integer_travels_as_an_integer(): void
    {
        $this->assertStringContainsString(
            '<value><int>4711</int></value>',
            XmlRpc::request('nameserver.deleteRecord', ['id' => 4711]),
        );
    }

    /**
     * Werte werden maskiert.
     *
     * Ein Zonenname kommt aus einer Kundeneingabe; ohne Maskierung wäre der Weg
     * von dort in fremdes Markup offen.
     */
    public function test_values_are_escaped(): void
    {
        $xml = XmlRpc::request('x', ['v' => 'a<b>&"c"']);

        $this->assertStringContainsString('a&lt;b&gt;&amp;&quot;c&quot;', $xml);
        $this->assertStringNotContainsString('<b>', $xml);
    }

    public function test_a_response_becomes_an_array(): void
    {
        $decoded = XmlRpc::response(self::envelope(
            '<member><name>code</name><value><int>1000</int></value></member>'.
            '<member><name>msg</name><value><string>Command completed successfully</string></value></member>',
        ));

        $this->assertSame(1000, $decoded['code']);
        $this->assertSame('Command completed successfully', $decoded['msg']);
    }

    public function test_nested_structs_and_arrays_are_read(): void
    {
        $decoded = XmlRpc::response(self::envelope(
            '<member><name>resData</name><value><struct>'.
            '<member><name>record</name><value><array><data>'.
            '<value><struct>'.
            '<member><name>id</name><value><int>7</int></value></member>'.
            '<member><name>name</name><value><string>_acme-challenge.example.de</string></value></member>'.
            '</struct></value>'.
            '</data></array></value></member>'.
            '</struct></value></member>',
        ));

        $this->assertCount(1, $decoded['resData']['record']);
        $this->assertSame(7, $decoded['resData']['record'][0]['id']);
        $this->assertSame('_acme-challenge.example.de', $decoded['resData']['record'][0]['name']);
    }

    /** Ein `<value>` ohne Kind ist eine Zeichenkette — so steht es im Standard. */
    public function test_a_value_without_a_child_is_a_string(): void
    {
        $decoded = XmlRpc::response(self::envelope(
            '<member><name>msg</name><value>nackt</value></member>',
        ));

        $this->assertSame('nackt', $decoded['msg']);
    }

    public function test_booleans_are_read_as_booleans(): void
    {
        $decoded = XmlRpc::response(self::envelope(
            '<member><name>ok</name><value><boolean>1</boolean></value></member>'.
            '<member><name>nein</name><value><boolean>0</boolean></value></member>',
        ));

        $this->assertTrue($decoded['ok']);
        $this->assertFalse($decoded['nein']);
    }

    /**
     * Ein `fault` ist eine Antwort und kein Bruch.
     *
     * Was seine Nummern bedeuten, weiss nur der Aufrufer — er kennt die
     * Nummernkreise seines Anbieters.
     */
    public function test_a_fault_is_readable_like_any_answer(): void
    {
        $decoded = XmlRpc::response(
            '<?xml version="1.0"?><methodResponse><fault><value><struct>'.
            '<member><name>faultCode</name><value><int>2200</int></value></member>'.
            '<member><name>faultString</name><value><string>Authentication error</string></value></member>'.
            '</struct></value></fault></methodResponse>',
        );

        $this->assertSame(2200, $decoded['faultCode']);
        $this->assertSame('Authentication error', $decoded['faultString']);
    }

    public function test_something_that_is_not_xml_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('kein lesbares XML');

        XmlRpc::response('das ist kein xml <<<');
    }

    public function test_xml_that_is_not_an_answer_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine XML-RPC-Antwort');

        XmlRpc::response('<?xml version="1.0"?><etwas><anderes/></etwas>');
    }

    /**
     * Eine externe Entität holt nichts.
     *
     * **Das ist gemessen und nicht angenommen:** Mit `LIBXML_NOENT` steht der
     * Inhalt von `/etc/hostname` in diesem Wert, mit den Marken dieser Klasse
     * ist er leer. Ein Prozess mit Systemrechten, der einer fremden Antwort
     * erlaubt, eine Datei zu zitieren, ist keine Grenze, sondern eine Tür.
     */
    public function test_an_external_entity_fetches_nothing(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/hostname">]>'.
            self::envelope('<member><name>msg</name><value><string>&x;</string></value></member>');

        try {
            $decoded = XmlRpc::response($xml);
        } catch (AgentException $exception) {
            // Abgewiesen ist ebenfalls richtig: Dann kam die Entität gar nicht
            // erst durch den Parser.
            $this->assertNotSame('', $exception->getMessage());

            return;
        }

        $this->assertSame('', (string) ($decoded['msg'] ?? ''));
    }

    /**
     * Und der Parser bekommt die Marken dafür gesagt.
     *
     * **Der Fall darüber misst die Wirkung — nur nicht überall.** Am 10. August
     * 2026 ist der Bruch dazu (`LIBXML_NONET | LIBXML_NOCDATA` gegen
     * `LIBXML_NOENT` getauscht) in der CI grün durchgelaufen, und hier im
     * Container nicht. Gemessen ist der Unterschied die Fassung von libxml:
     * mit 2.9.14 steht der Inhalt von `/etc/hostname` im Wert, mit den neueren
     * Fassungen der CI ist er auch mit `LIBXML_NOENT` leer, weil das Laden
     * externer Entitäten dort schon in der Bibliothek abgeschaltet ist.
     *
     * > **Ein Wächter, dessen Befund an der Fassung einer Systembibliothek
     * > hängt, misst die Maschine und nicht die Regel.**
     *
     * Die Regel gilt trotzdem, und zwar dort, wo dieses Panel läuft: Debian 12
     * liefert libxml 2.9.14. Deshalb bleibt der Fall darüber stehen — er ist
     * die Messung — und dieser hier kommt dazu: Er liest die Marken im
     * Quelltext und beisst auf jeder Maschine.
     */
    public function test_the_parser_is_told_to_leave_entities_alone(): void
    {
        $file = dirname(__DIR__, 2).'/agent/src/Acme/Dns/XmlRpc.php';

        // Ohne Kommentare gelesen: Über der Zeile steht ein Absatz, der beide
        // Marken beim Namen nennt — ein Wächter, der ihn mitliest, prüft die
        // Erklärung statt des Codes.
        $code = $this->withoutComments((string) file_get_contents($file));

        $this->assertStringContainsString('LIBXML_NONET', $code, 'Der Parser darf ins Netz.');
        $this->assertStringNotContainsString('LIBXML_NOENT', $code, 'Der Parser löst Entitäten auf.');
    }

    /**
     * Und eine Antwort, die sich endlos schachtelt, wird abgewiesen.
     *
     * Sie ist keine Antwort, sondern ein Weg, den Speicher dieses Prozesses zu
     * füllen.
     */
    public function test_a_response_that_nests_too_deeply_is_refused(): void
    {
        $depth = XmlRpc::MAX_DEPTH + 10;

        $inner = str_repeat('<value><array><data>', $depth).
            '<value><string>x</string></value>'.
            str_repeat('</data></array></value>', $depth);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('zu tief verschachtelt');

        XmlRpc::response('<?xml version="1.0"?><methodResponse><params><param>'.$inner.'</param></params></methodResponse>');
    }
}
