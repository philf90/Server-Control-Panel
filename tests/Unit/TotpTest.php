<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP gegen die Testvektoren des Standards.
 *
 * Das ist der Grund, warum diese Umsetzung ohne Bibliothek vertretbar ist:
 * Sie wird nicht gegen sich selbst geprüft, sondern gegen RFC 6238, Anhang B.
 * Wer die Abschneideregel falsch versteht oder die Zeitschritte um eins
 * verschiebt, fällt hier durch — und nicht erst bei einem Nutzer, dessen App
 * plötzlich keine gültigen Codes mehr liefert.
 */
final class TotpTest extends TestCase
{
    /**
     * Der Seed aus dem RFC ist die ASCII-Folge „12345678901234567890" — zwanzig
     * Bytes, base32 kodiert GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ.
     *
     * Die ersten sechzehn Zeichen davon sind ein gültiges base32 und ergeben
     * einen anderen Seed („1234567890"). Wer den nimmt, bekommt lauter Codes,
     * die zu nichts passen — und sucht den Fehler in der Abschneideregel.
     */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return list<array{0:int, 1:string}>
     */
    public static function vectors(): array
    {
        // RFC 6238, Anhang B, SHA1-Spalte. Der RFC nennt achtstellige Codes;
        // die letzten sechs Stellen sind der sechsstellige Code.
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    #[DataProvider('vectors')]
    public function test_the_rfc_vectors_match(int $timestamp, string $expected): void
    {
        $this->assertSame($expected, Totp::codeAt(self::SECRET, intdiv($timestamp, Totp::PERIOD)));
    }

    public function test_a_current_code_is_accepted(): void
    {
        $secret = Totp::generateSecret();
        $now = time();
        $code = Totp::codeAt($secret, intdiv($now, Totp::PERIOD));

        $this->assertNotNull(Totp::verify($secret, $code, 1, $now));
    }

    public function test_the_window_covers_clocks_that_drift(): void
    {
        $secret = Totp::generateSecret();
        $now = time();

        foreach ([-1, 0, 1] as $offset) {
            $code = Totp::codeAt($secret, intdiv($now, Totp::PERIOD) + $offset);
            $this->assertNotNull(
                Totp::verify($secret, $code, 1, $now),
                "Abweichung um {$offset} Schritte wurde nicht angenommen.",
            );
        }

        // Zwei Schritte daneben ist keine ungenaue Uhr mehr.
        $tooOld = Totp::codeAt($secret, intdiv($now, Totp::PERIOD) - 2);
        $this->assertNull(Totp::verify($secret, $tooOld, 1, $now));
    }

    public function test_the_matched_step_is_returned(): void
    {
        // Ohne diesen Rückgabewert kann der Aufrufer nicht merken, welcher
        // Code verbraucht ist — und derselbe ließe sich in seinem Fenster ein
        // zweites Mal verwenden.
        $secret = Totp::generateSecret();
        $now = time();
        $step = intdiv($now, Totp::PERIOD);

        $this->assertSame($step, Totp::verify($secret, Totp::codeAt($secret, $step), 1, $now));
    }

    public function test_nonsense_is_rejected_without_computing(): void
    {
        $secret = Totp::generateSecret();

        foreach (['', 'abcdef', '12345', '1234567', '12 34 56'] as $input) {
            $this->assertNull(Totp::verify($secret, $input), "Eingabe {$input} wurde angenommen.");
        }
    }

    public function test_spaces_in_the_code_do_not_matter(): void
    {
        $secret = Totp::generateSecret();
        $now = time();
        $code = Totp::codeAt($secret, intdiv($now, Totp::PERIOD));

        // Apps zeigen „123 456"; wer das abtippt, tippt oft das Leerzeichen mit.
        $spaced = substr($code, 0, 3).' '.substr($code, 3);

        $this->assertNotNull(Totp::verify($secret, $spaced, 1, $now));
    }

    public function test_base32_round_trips(): void
    {
        foreach ([1, 10, 20, 32] as $length) {
            $bytes = random_bytes($length);
            $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }
    }

    public function test_a_mistyped_secret_gives_a_wrong_code_not_a_crash(): void
    {
        // Menschen tippen Geheimnisse ab. „0" statt „O" soll zu einem falschen
        // Code führen, nicht zu einem Absturz.
        $this->assertMatchesRegularExpression('/^\d{6}$/', Totp::codeAt('GEZDGNBVGY3TQOJ0', 1));

        // Und er ist tatsächlich ein anderer — sonst wäre das „falsch" nur
        // behauptet.
        $this->assertNotSame(
            Totp::codeAt('GEZDGNBVGY3TQOJQ', 1),
            Totp::codeAt('GEZDGNBVGY3TQOJ0', 1),
        );
    }

    public function test_the_provisioning_uri_carries_what_apps_read(): void
    {
        $uri = Totp::provisioningUri('GEZDGNBVGY3TQOJQ', 'erika@example.test', 'SrvPanel');

        $this->assertStringStartsWith('otpauth://totp/SrvPanel:erika%40example.test?', $uri);
        $this->assertStringContainsString('secret=GEZDGNBVGY3TQOJQ', $uri);
        $this->assertStringContainsString('issuer=SrvPanel', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
