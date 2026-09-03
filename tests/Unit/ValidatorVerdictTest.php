<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Result;
use Tests\Support\MethodBody;
use Tests\Support\WithoutPhpComments;

/**
 * Die drei Prüfer werden am Rückgabewert gewertet — und an sonst nichts.
 *
 * **Die Prüfkörper sind die gemessenen Ausgaben** (`docs/81 §2.3o` M4, M5),
 * nicht erfundene. Der wichtigste ist der Lauf, in dem nginx `syntax is ok`
 * **und** `test failed` in dieselbe Ausgabe schreibt: Ein Leser, der die
 * erste Zeile sucht, meldet Grün für einen Lauf, der mit `rc=1` endete.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht.** Hier ist es der Prüfling, der das nicht tun darf.
 */
final class ValidatorVerdictTest extends TestCase
{
    use MethodBody;
    use WithoutPhpComments;

    /** nginx auf einer heilen Konfiguration — zwei Zeilen auf stderr, rc 0. */
    private const NGINX_HEIL = "nginx: the configuration file /etc/nginx/nginx.conf syntax is ok\nnginx: configuration file /etc/nginx/nginx.conf test is successful";

    /** M4: `syntax is ok` steht da — und der Lauf ist gescheitert. */
    private const NGINX_M4 = "nginx: the configuration file /etc/nginx/nginx.conf syntax is ok\nnginx: [emerg] socket() [::]:80 failed (97: Address family not supported by protocol)\nnginx: configuration file /etc/nginx/nginx.conf test failed";

    /** php-fpm meldet seinen Erfolg als NOTICE — auf stderr, mit Datum. */
    private const PHPFPM_HEIL = '[02-Sep-2026 10:12:50] NOTICE: configuration file /etc/php/8.3/fpm/php-fpm.conf test is successful';

    /**
     * @return array<string, array{0: Result, 1: null|string}>
     */
    public static function measured(): array
    {
        return [
            'nginx heil' => [new Result(0, '', self::NGINX_HEIL), null],
            'nginx M4: syntax ok, test failed' => [new Result(1, '', self::NGINX_M4), 'invalid'],
            'nginx fehlendes Zertifikat, ohne syntax-ok-Zeile' => [new Result(1, '', '2026/09/02 10:12:50 [emerg] 8915#8915: cannot load certificate "…": BIO_new_file() failed'), 'invalid'],
            'sshd heil: null Byte' => [new Result(0, '', ''), null],
            'sshd unbekannte Anweisung' => [new Result(255, '', "/etc/ssh/sshd_config: line 133: Bad configuration option: GibtEsNicht\n/etc/ssh/sshd_config: terminating, 1 bad configuration options"), 'invalid'],
            'sshd fehlender Hostkey: rc 1' => [new Result(1, '', "Unable to load host key: /etc/ssh/gibtsnicht_key\nsshd: no hostkeys available -- exiting."), 'invalid'],
            'php-fpm heil: NOTICE auf stderr' => [new Result(0, '', self::PHPFPM_HEIL), null],
            'php-fpm rc 78' => [new Result(78, '', '[02-Sep-2026 10:12:50] ERROR: [/etc/php/8.3/fpm/pool.d/x.conf:4] unable to parse value'), 'invalid'],
        ];
    }

    #[DataProvider('measured')]
    public function test_every_measured_output_gets_the_verdict_of_its_return_code(Result $result, ?string $expected): void
    {
        $this->assertSame($expected, Verdict::validator($result));
    }

    /**
     * Ein voller stderr ist kein Fehlschlag, und ein leerer kein Erfolg.
     *
     * M5: Alle drei schreiben auf stderr, auch im Erfolgsfall; sshd schreibt im
     * Erfolgsfall nichts. Wer den Kanal liest, meldet nginx und php-fpm
     * dauerhaft kaputt — oder hält ein stummes `rc=255` für gesund.
     */
    public function test_the_channel_decides_nothing(): void
    {
        $this->assertNull(Verdict::validator(new Result(0, '', str_repeat('viel auf stderr', 100))));
        $this->assertSame('invalid', Verdict::validator(new Result(255, '', '')));
        $this->assertSame('invalid', Verdict::validator(new Result(1, 'alles gut', '')));
    }

    /**
     * Die Zeichenkette `syntax is ok` wird nirgends gelesen — auch nicht
     * `successful`.
     *
     * Gelesen wird der Rumpf ohne Kommentare: Der Kommentar über der Methode
     * zitiert genau diese Zeichenketten, um zu erklären, warum sie nicht gelesen
     * werden — und ein Wächter, der roh liest, fände sie dort (`OutcomeTest`,
     * 1. September 2026).
     */
    public function test_the_verdict_reads_no_wording(): void
    {
        $source = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Diagnose/Verdict.php'));
        $body = $this->methodBody($source, 'public static function validator(');

        $this->assertStringContainsString('->code', $body, 'Der Rumpf liest den Rückgabewert nicht — der Wächter hat die falsche Methode.');

        foreach (['syntax is ok', 'successful', 'test failed', 'stderr', 'stdout', 'message('] as $verboten) {
            $this->assertStringNotContainsString($verboten, $body, sprintf('validator() liest „%s" — das Urteil hängt am Rückgabewert und an sonst nichts.', $verboten));
        }
    }
}
