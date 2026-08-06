<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Tsig;
use SrvPanel\Agent\AgentException;

/**
 * Die Unterschrift unter eine Zonenaktualisierung (RFC 8945).
 *
 * **Alles hier ist von Hand gerechnet und nicht mit `Tsig` selbst gebaut.** Die
 * Verlockung wäre, die Antwort mit derselben Klasse zu unterschreiben, die sie
 * gleich prüfen soll — und dann bestünde der Durchgang auch dann, wenn beide
 * Seiten denselben Fehler machen. Was hier steht, ist deshalb eine zweite
 * Umsetzung des Rechenwegs, Byte für Byte aus §4.3.3, und sie steht bewusst
 * ausgeschrieben da.
 *
 * **Der Fall, für den es diesen Durchgang gibt, ist die Zählung im Kopf.**
 * Gerechnet wird über die Nachricht *vor* dem TSIG-Satz, also mit dem alten
 * `ARCOUNT`; erhöht wird erst danach. Wer es andersherum macht, bekommt eine
 * Unterschrift, die in sich stimmig ist, und einen Nameserver, der `NOTAUTH`
 * antwortet — ohne zu sagen, an welcher der acht Grössen es lag.
 */
final class TsigTest extends TestCase
{
    private const KEY = 'srvpanel-key';

    private const SECRET = 'ein-geheimnis-mit-genug-bytes';

    private const TIME = 1_754_400_000;

    private function key(string $algorithm = Tsig::DEFAULT_ALGORITHM): Tsig
    {
        return new Tsig(self::KEY, $algorithm, self::SECRET);
    }

    /** Ein Name im Drahtformat — hier von Hand, damit es eine zweite Fassung ist. */
    private function wire(string $name): string
    {
        $encoded = '';

        foreach (explode('.', strtolower($name)) as $label) {
            $encoded .= chr(strlen($label)).$label;
        }

        return $encoded."\0";
    }

    private function time48(int $seconds): string
    {
        return substr(pack('J', $seconds), 2, 6);
    }

    /** Eine schlichte Nachricht ohne Zusatzabschnitt. */
    private function message(int $id = 0x4711, int $additional = 0): string
    {
        return pack('n6', $id, 0x2800, 1, 0, 0, $additional).$this->wire('example.de').pack('n2', 6, 1);
    }

    public function test_the_signature_is_appended_and_counted(): void
    {
        $message = $this->message();
        $signed = $this->key()->sign($message, self::TIME);

        /** @var array<string, int> $header */
        $header = unpack('nid/nflags/nqd/nan/nns/nar', $signed['message']);

        $this->assertSame(0x4711, $header['id']);
        $this->assertSame(1, $header['ar'], 'Der TSIG-Satz zählt im Kopf mit.');
        $this->assertStringStartsWith(substr($message, 12), substr($signed['message'], 12));

        // Der Satz steht am Ende und trägt Name, Typ 250 und Klasse ANY.
        $record = $this->wire(self::KEY).pack('n2N', Tsig::TYPE, Tsig::CLASS_ANY, 0);
        $this->assertStringContainsString($record, $signed['message']);
    }

    /**
     * Die Unterschrift selbst — nachgerechnet, nicht nachgeschlagen.
     *
     * Siehe die Klassenbeschreibung: Gerechnet wird über die Nachricht mit dem
     * alten Zähler.
     */
    public function test_the_mac_is_the_one_the_rfc_prescribes(): void
    {
        $message = $this->message();
        $signed = $this->key()->sign($message, self::TIME);

        $variables = $this->wire(self::KEY)
            .pack('nN', Tsig::CLASS_ANY, 0)
            .$this->wire(Tsig::DEFAULT_ALGORITHM)
            .$this->time48(self::TIME)
            .pack('n3', Tsig::FUDGE, 0, 0);

        $expected = hash_hmac('sha256', $message.$variables, self::SECRET, true);

        $this->assertSame(bin2hex($expected), bin2hex($signed['mac']));
    }

    /**
     * Und der Gegenbeweis: Über die erhöhte Zählung gerechnet stimmt sie nicht.
     *
     * Das ist der Fehler, den man einer laufenden Verbindung nicht ansieht.
     */
    public function test_signing_over_the_raised_count_would_be_a_different_mac(): void
    {
        $signed = $this->key()->sign($this->message(), self::TIME);

        $variables = $this->wire(self::KEY)
            .pack('nN', Tsig::CLASS_ANY, 0)
            .$this->wire(Tsig::DEFAULT_ALGORITHM)
            .$this->time48(self::TIME)
            .pack('n3', Tsig::FUDGE, 0, 0);

        $wrong = hash_hmac('sha256', $this->message(0x4711, 1).$variables, self::SECRET, true);

        $this->assertNotSame(bin2hex($wrong), bin2hex($signed['mac']));
    }

    /**
     * Eine Antwort, wie ein Nameserver sie unterschreibt.
     *
     * Der Unterschied zur Frage ist die Unterschrift der Frage, die mit ihrer
     * Länge davorgestellt wird — sonst wäre eine Antwort von gestern heute
     * noch gültig.
     */
    private function response(
        Tsig $key,
        string $requestMac,
        int $id = 0x4711,
        int $code = 0,
        int $time = self::TIME,
        int $error = 0,
        string $keyName = self::KEY,
        string $algorithm = Tsig::DEFAULT_ALGORITHM,
        ?string $mac = null,
    ): string {
        $body = pack('n6', $id, 0xA800 | $code, 1, 0, 0, 0).$this->wire('example.de').pack('n2', 6, 1);

        $variables = $this->wire($keyName)
            .pack('nN', Tsig::CLASS_ANY, 0)
            .$this->wire($algorithm)
            .$this->time48($time)
            .pack('n3', Tsig::FUDGE, $error, 0);

        $mac ??= hash_hmac(
            'sha256',
            pack('n', strlen($requestMac)).$requestMac.$body.$variables,
            self::SECRET,
            true,
        );

        $rdata = $this->wire($algorithm)
            .$this->time48($time)
            .pack('n2', Tsig::FUDGE, strlen($mac))
            .$mac
            .pack('n3', $id, $error, 0);

        $record = $this->wire($keyName)
            .pack('n2N', Tsig::TYPE, Tsig::CLASS_ANY, 0)
            .pack('n', strlen($rdata))
            .$rdata;

        return substr_replace($body, pack('n', 1), 10, 2).$record;
    }

    public function test_a_correctly_signed_answer_is_accepted(): void
    {
        $key = $this->key();
        $signed = $key->sign($this->message(), self::TIME);

        $this->assertTrue($key->verify(
            $this->response($key, $signed['mac']),
            $signed['mac'],
            self::TIME,
        ));
    }

    /** Eine Antwort auf die Frage von vorhin belegt die von jetzt nicht. */
    public function test_an_answer_to_another_request_is_refused(): void
    {
        $key = $this->key();
        $signed = $key->sign($this->message(), self::TIME);

        $this->assertFalse($key->verify(
            $this->response($key, str_repeat("\x00", 32)),
            $signed['mac'],
            self::TIME,
        ));
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $key = $this->key();
        $signed = $key->sign($this->message(), self::TIME);

        $this->assertFalse($key->verify(
            $this->response($key, $signed['mac'], mac: str_repeat("\x2a", 32)),
            $signed['mac'],
            self::TIME,
        ));
    }

    /** Ein fremder Schlüsselname, ein fremdes Verfahren — beides ist ein Nein. */
    public function test_another_key_or_algorithm_is_refused(): void
    {
        $key = $this->key();
        $signed = $key->sign($this->message(), self::TIME);

        $this->assertFalse($key->verify(
            $this->response($key, $signed['mac'], keyName: 'fremder-schluessel'),
            $signed['mac'],
            self::TIME,
        ));

        $this->assertFalse($key->verify(
            $this->response($key, $signed['mac'], algorithm: 'hmac-sha512'),
            $signed['mac'],
            self::TIME,
        ));
    }

    /**
     * Ein gesetztes Fehlerfeld ist eine Ablehnung mit gültiger Unterschrift.
     *
     * Sie stimmt — und die Aktualisierung ist trotzdem keine.
     */
    public function test_a_signed_refusal_is_not_a_success(): void
    {
        $key = $this->key();
        $signed = $key->sign($this->message(), self::TIME);

        $this->assertFalse($key->verify(
            $this->response($key, $signed['mac'], error: 16),
            $signed['mac'],
            self::TIME,
        ));
    }

    /** Und eine Antwort, die zu lange unterwegs war, gilt nicht mehr. */
    public function test_an_answer_outside_the_time_window_is_refused(): void
    {
        $key = $this->key();
        $signed = $key->sign($this->message(), self::TIME);
        $answer = $this->response($key, $signed['mac']);

        $this->assertTrue($key->verify($answer, $signed['mac'], self::TIME + Tsig::FUDGE));
        $this->assertFalse($key->verify($answer, $signed['mac'], self::TIME + Tsig::FUDGE + 1));
    }

    /** Was gar keine Antwort ist, ergibt kein Ja und keinen Absturz. */
    public function test_garbage_is_refused(): void
    {
        $key = $this->key();

        $this->assertFalse($key->verify('', 'x', self::TIME));
        $this->assertFalse($key->verify('abc', 'x', self::TIME));
        $this->assertFalse($key->verify($this->message(), 'x', self::TIME));
    }

    /**
     * Die Verfahren sind eine Positivliste.
     *
     * `hmac-md5` steht in RFC 8945 noch drin und ist dort sogar der alte
     * Standardwert; für einen Schlüssel, der heute eingerichtet wird, gibt es
     * keinen Grund dazu.
     */
    public function test_only_the_agreed_algorithms_are_signed(): void
    {
        $this->assertSame(['hmac-sha256', 'hmac-sha384', 'hmac-sha512'], Tsig::algorithms());
        $this->assertSame(Tsig::DEFAULT_ALGORITHM, Tsig::normalizeAlgorithm(null));
        $this->assertSame('hmac-sha512', Tsig::normalizeAlgorithm(' HMAC-SHA512 '));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Unbekanntes TSIG-Verfahren');

        Tsig::normalizeAlgorithm('hmac-md5');
    }
}
