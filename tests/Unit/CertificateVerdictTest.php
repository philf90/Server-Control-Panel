<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnose\Checks\Certificates;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Zwei Fragen an ein Zertifikat, und die zweite nur, wenn die erste heil ist
 * (A10 Schritt 5, `docs/98 §3 E`, Frage 3 mit **c** entschieden).
 *
 * ## Warum die Reihenfolge das Kriterium ist
 *
 * Ein abgelaufenes Zertifikat wird auch über die Leitung abgelaufen
 * ausgeliefert. Wer beide Fragen unabhängig stellt, schreibt **zwei Befunde für
 * eine Ursache** hin — die Falle aus `docs/98 §4`, an der ein Diagnoselauf nach
 * zwei Wochen ungelesen bleibt. Dieser Wächter zählt deshalb die Aufrufe an die
 * Leitung und nicht nur ihre Antworten.
 *
 * ## Und warum der Fingerabdruck
 *
 * Gemessen am 2. September (`docs/81 §2.3o` M23): Die Seriennummer ist nur je
 * Aussteller eindeutig, und dieses Panel erzeugt selbstsignierte Zertifikate;
 * das Ablaufdatum teilen zwei Zertifikate derselben Stunde. Der Fingerabdruck
 * hat beide Vorbehalte nicht — und über einer `fullchain.pem` liefert
 * `openssl_x509_fingerprint` den Leaf, also das, was auch über die Leitung
 * kommt.
 *
 * Framework-frei bis auf `Carbon`.
 */
final class CertificateVerdictTest extends TestCase
{
    private const FP_DATEI = 'AA11BB22CC33DD44EE55FF6677889900AA11BB22CC33DD44EE55FF6677889900';

    private const FP_ANDERS = '99887766554433221100FFEEDDCCBBAA99887766554433221100FFEEDDCCBBAA';

    private function now(): Carbon
    {
        return Carbon::parse('2026-09-02 03:00:00');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed> die Antwort von `acme.certificate.info`
     */
    private function info(array $overrides = []): array
    {
        return $overrides + [
            'present' => true,
            'valid_from' => $this->now()->getTimestamp() - 86400,
            'valid_to' => $this->now()->getTimestamp() + 60 * 86400,
            'names' => ['kunde.invalid', 'www.kunde.invalid'],
            'fingerprint' => self::FP_DATEI,
        ];
    }

    /** @return list<array{name: string, names: list<string>, storage: null|string}> */
    private function rows(?string $storage = 'kunde.invalid'): array
    {
        return [['name' => 'kunde.invalid', 'names' => ['kunde.invalid', 'www.kunde.invalid'], 'storage' => $storage]];
    }

    public function test_a_healthy_certificate_yields_nothing(): void
    {
        $gefragt = [];
        $verdict = Certificates::judge(
            $this->rows(),
            ['kunde.invalid' => $this->info()],
            function (string $name) use (&$gefragt): string {
                $gefragt[] = $name;

                return self::FP_DATEI;
            },
            $this->now(),
        );

        $this->assertSame([], $verdict['file']);
        $this->assertSame([], $verdict['wire']);
        $this->assertSame(['kunde.invalid'], $gefragt, 'Die Leitung wurde nicht gefragt — dann prüft dieser Fall die Reihenfolge nicht.');
    }

    /**
     * Die Leitung wird gar nicht erst gefragt, wenn die Datei schon rot ist.
     *
     * Das Kriterium dieses Wächters: **null** Aufrufe, nicht bloss kein
     * zusätzlicher Befund.
     */
    public function test_the_wire_is_not_asked_when_the_file_is_already_a_finding(): void
    {
        $gefragt = 0;
        $verdict = Certificates::judge(
            $this->rows(),
            ['kunde.invalid' => $this->info(['valid_to' => $this->now()->getTimestamp() - 3600])],
            function () use (&$gefragt): string {
                $gefragt++;

                return self::FP_DATEI;
            },
            $this->now(),
        );

        $this->assertSame('expired', $verdict['file'][0]['reason']);
        $this->assertSame([], $verdict['wire']);
        $this->assertSame(0, $gefragt, 'Zwei Befunde für eine Ursache — genau die Falle aus docs/98 §4.');
    }

    public function test_a_certificate_that_is_gone_is_missing_and_carries_the_reason(): void
    {
        $verdict = Certificates::judge(
            $this->rows(),
            ['kunde.invalid' => ['present' => false, 'reason' => 'Es liegt kein Zertifikat unter /etc/srvpanel/tls/certs/kunde.invalid/fullchain.pem.']],
            fn (): string => self::FP_DATEI,
            $this->now(),
        );

        $this->assertSame('missing', $verdict['file'][0]['reason']);
        $this->assertStringContainsString('fullchain.pem', (string) $verdict['file'][0]['detail']);
    }

    /** Ein Name, den das Zertifikat nicht deckt — auch wenn es gültig ist. */
    public function test_a_name_the_certificate_does_not_cover(): void
    {
        $verdict = Certificates::judge(
            $this->rows(),
            ['kunde.invalid' => $this->info(['names' => ['kunde.invalid']])],
            fn (): string => self::FP_DATEI,
            $this->now(),
        );

        $this->assertSame('name_mismatch', $verdict['file'][0]['reason']);
        $this->assertStringContainsString('www.kunde.invalid', (string) $verdict['file'][0]['detail']);
    }

    /** Ein Platzhalter deckt genau eine Beschriftung — dieselbe Regel wie im Modell. */
    public function test_a_wildcard_covers_one_label(): void
    {
        $this->assertNull(Certificates::file(['www.kunde.invalid'], $this->info(['names' => ['*.kunde.invalid']]), $this->now()));

        $verdict = Certificates::file(['a.b.kunde.invalid'], $this->info(['names' => ['*.kunde.invalid']]), $this->now());
        $this->assertSame('name_mismatch', $verdict['reason'] ?? null, 'Ein Platzhalter deckt zwei Beschriftungen — dann ist die Regel doppelt geschrieben.');
    }

    /** Punkt 6 des Abnahmekriteriums: dreissig Tage sind `warn`, abgelaufen ist `fail`. */
    public function test_the_expiring_window_is_thirty_days(): void
    {
        $this->assertSame(30, Certificates::EXPIRING_DAYS);

        $knapp = $this->info(['valid_to' => $this->now()->getTimestamp() + 29 * 86400]);
        $this->assertSame('expiring', Certificates::file(['kunde.invalid'], $knapp, $this->now())['reason'] ?? null);

        $weit = $this->info(['valid_to' => $this->now()->getTimestamp() + 31 * 86400]);
        $this->assertNull(Certificates::file(['kunde.invalid'], $weit, $this->now()));
    }

    /** Der Fall, den nur die Leitung fängt. */
    public function test_a_different_certificate_on_the_wire(): void
    {
        $verdict = Certificates::judge(
            $this->rows(),
            ['kunde.invalid' => $this->info()],
            fn (): string => self::FP_ANDERS,
            $this->now(),
        );

        $this->assertSame([], $verdict['file']);
        $this->assertSame('not_served', $verdict['wire'][0]['reason']);
        $this->assertStringContainsString(self::FP_ANDERS, (string) $verdict['wire'][0]['detail']);
    }

    /** Schreibweise ist keine Auskunft. */
    public function test_the_comparison_ignores_case(): void
    {
        $this->assertNull(Certificates::wire(self::FP_DATEI, strtolower(self::FP_DATEI)));
    }

    /**
     * Keine Antwort trägt keinen Text.
     *
     * Gemessen (M23): Ein Port, der lauscht und kein TLS spricht, meldet einen
     * Fehlschlag mit **leerer** Meldung. Wer sie durchreicht, schreibt eine
     * leere Zeile hin.
     */
    public function test_no_answer_carries_no_empty_text(): void
    {
        $this->assertSame(['reason' => 'no_answer', 'detail' => null], Certificates::wire(self::FP_DATEI, null));
    }

    /** Eine Domain ohne gewähltes Zertifikat ist kein Befund — das behebt die Automatik. */
    public function test_a_domain_without_a_certificate_is_not_a_finding(): void
    {
        $gefragt = 0;
        $verdict = Certificates::judge($this->rows(null), [], function () use (&$gefragt): ?string {
            $gefragt++;

            return null;
        }, $this->now());

        $this->assertSame([], $verdict['file']);
        $this->assertSame([], $verdict['wire']);
        $this->assertSame(0, $gefragt);
    }

    /**
     * Ein Agent ohne Fingerabdruck bricht ab, statt jede Domain zu melden.
     *
     * Panel und Agent kommen aus einem Paket; ein fehlendes Feld ist ein
     * Programmierfehler und keine Messung. Ein `not_served` für jede Domain
     * wäre eine falsche Auskunft und kein Ausfall.
     */
    public function test_a_missing_fingerprint_stops_the_run(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Certificates::judge(
            $this->rows(),
            ['kunde.invalid' => $this->info(['fingerprint' => null])],
            fn (): string => self::FP_DATEI,
            $this->now(),
        );
    }
}
