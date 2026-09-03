<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Models\Finding;
use App\Support\Diagnose\FindingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Kennung eines Befundes ist `check` + `subject` + `reason` — und nicht sein Text.
 *
 * **Gemessen an der Wirkung und nicht am Quelltext.** Ein Wächter, der nachsieht,
 * ob `detail` in der Schlüsselliste steht, sagt, dass die Teile zusammenpassen;
 * er sagt nicht, dass zwei Nächte eine Zeile ergeben. Derselbe Unterschied wie
 * bei `OriginHeaderTest`:
 *
 * > **Ein Wächter über den Quelltext sagt, dass die Teile zusammenpassen, nicht
 * > dass sie zusammen etwas tun.**
 *
 * **Der Prüfkörper ist der echte Wortlaut zweier nginx-Läufe.** Er trägt Datum
 * **und Prozessnummer** (`docs/81 §2.3o` M9) — an derselben kaputten Datei
 * gemessen ergeben zwei Läufe zwei Texte, und genau daran ist die Kennung
 * gebaut. Ein erfundener Prüfkörper („text A" gegen „text B") prüfte dieselbe
 * Regel und verlöre die Begründung.
 *
 * **Und dieser Wächter hat beim ersten Lauf zugebissen.** Die erste Fassung von
 * {@see FindingLog} benutzte `updateOrCreate` mit `first_seen_at` in der zweiten
 * Liste — die nimmt Laravel für **beide** Wege, also hätte jeder Lauf „steht
 * seit" auf heute gezogen. Der Kommentar daneben behauptete das Gegenteil.
 */
final class FindingIdentityTest extends TestCase
{
    use RefreshDatabase;

    /** Wie nginx an derselben kaputten Datei zweimal meldet. */
    private const ERST = '2026/09/02 03:00:11 [emerg] 8896#8896: unexpected end of file, expecting "}" in /etc/nginx/srvpanel.d/kunde.conf:6';

    private const DANN = '2026/09/03 03:00:07 [emerg] 12044#12044: unexpected end of file, expecting "}" in /etc/nginx/srvpanel.d/kunde.conf:6';

    public function test_the_same_damage_over_two_nights_is_one_row(): void
    {
        $log = new FindingLog;
        $erst = Carbon::parse('2026-09-02 03:00:00');
        $dann = Carbon::parse('2026-09-03 03:00:00');

        $log->replace(FindingCheck::WebConfig, [
            ['subject' => '/etc/nginx/nginx.conf', 'reason' => 'invalid', 'detail' => self::ERST],
        ], $erst);

        $log->replace(FindingCheck::WebConfig, [
            ['subject' => '/etc/nginx/nginx.conf', 'reason' => 'invalid', 'detail' => self::DANN],
        ], $dann);

        $this->assertSame(1, Finding::query()->count(), 'Zwei Nächte mit demselben Schaden haben zwei Zeilen erzeugt — dann ist der Wortlaut Teil der Kennung.');

        $finding = Finding::query()->sole();

        $this->assertTrue(
            $finding->first_seen_at->equalTo($erst),
            'Der zweite Lauf hat „steht seit" verschoben. Damit ist die Auskunft, wie lange etwas schon kaputt ist, wertlos.',
        );
        $this->assertTrue($finding->measured_at->equalTo($dann));
        $this->assertSame(self::DANN, $finding->detail, 'Der Wortlaut ist nicht Teil der Kennung, aber er soll der neueste sein.');
    }

    public function test_a_different_reason_is_a_different_finding(): void
    {
        $log = new FindingLog;
        $jetzt = Carbon::parse('2026-09-02 03:00:00');

        $log->replace(FindingCheck::TlsFile, [
            ['subject' => 'kunde.de', 'reason' => 'expiring', 'detail' => null],
        ], $jetzt);

        // Aus „läuft demnächst ab" wird „ist abgelaufen". Derselbe Gegenstand,
        // ein anderer Grund — und damit ein anderer Befund, der ein eigenes
        // „steht seit" verdient.
        $log->replace(FindingCheck::TlsFile, [
            ['subject' => 'kunde.de', 'reason' => 'expired', 'detail' => null],
        ], $jetzt->copy()->addDays(12));

        $this->assertSame(1, Finding::query()->count(), 'Der alte Befund ist stehengeblieben, obwohl der Lauf ihn nicht mehr genannt hat.');
        $this->assertSame('expired', Finding::query()->sole()->reason);
    }

    public function test_what_a_run_no_longer_names_is_gone(): void
    {
        $log = new FindingLog;
        $jetzt = Carbon::parse('2026-09-02 03:00:00');

        $log->replace(FindingCheck::BlockIntegrity, [
            ['subject' => '/etc/ssh/sshd_config', 'reason' => 'begin_without_end', 'detail' => null],
            ['subject' => '/etc/postgresql/16/main/pg_hba.conf', 'reason' => 'foreign_line', 'detail' => null],
        ], $jetzt);

        $this->assertSame(2, Finding::query()->count());

        // Der Betreiber hat den Block zurückgelegt. Punkt 2 des
        // Abnahmekriteriums: im übernächsten Lauf ist der Befund fort.
        $log->replace(FindingCheck::BlockIntegrity, [
            ['subject' => '/etc/postgresql/16/main/pg_hba.conf', 'reason' => 'foreign_line', 'detail' => null],
        ], $jetzt->copy()->addDay());

        $this->assertSame(
            ['/etc/postgresql/16/main/pg_hba.conf'],
            Finding::query()->pluck('subject')->all(),
            'Ein behobener Befund ist stehengeblieben.',
        );
    }

    /**
     * Eine Prüfung, die ausgefallen ist, löscht nichts.
     *
     * **Das ist die Hälfte, die still bricht.** Ein Lauf, der bei einem
     * Fehlschlag „nichts gefunden" meldete, machte aus „nicht gemessen" ein
     * „alles in Ordnung" — der Fehler aus `docs/44`, und mit ihm verschwänden
     * genau die Befunde, die niemand mehr sieht.
     */
    public function test_an_unreachable_check_removes_nothing(): void
    {
        $log = new FindingLog;
        $jetzt = Carbon::parse('2026-09-02 03:00:00');

        $log->replace(FindingCheck::UnitSchedule, [
            ['subject' => 'srvpanel-cron.timer', 'reason' => 'no_next', 'detail' => null],
        ], $jetzt);

        $log->unreachable(FindingCheck::UnitSchedule, ['srvpanel-cron.timer'], $jetzt->copy()->addDay());

        $gruende = Finding::query()->orderBy('reason')->pluck('reason')->all();

        $this->assertSame(
            ['no_next', 'unreachable'],
            $gruende,
            'Der alte Befund ist verschwunden, weil die Prüfung ausgefallen ist. Damit sähe ein Ausfall wie eine Behebung aus.',
        );
    }

    /**
     * Der Wortlaut wird gekürzt, bevor er die Spalte erreicht.
     *
     * `docs/45`: Die Begründung, an der ein Abnahmekriterium hing, passte nicht
     * in ihre Spalte, die `PDOException` riss den `catch`-Zweig mit, und der
     * Vorgang meldete „vermutlich Zeitüberschreitung".
     *
     * > **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
     */
    public function test_an_overlong_detail_is_cut_before_it_is_written(): void
    {
        $log = new FindingLog;

        $log->replace(FindingCheck::PhpConfig, [
            ['subject' => '/etc/php/8.3/fpm/php-fpm.conf', 'reason' => 'invalid', 'detail' => str_repeat('x', Finding::DETAIL_MAX * 2)],
        ], Carbon::now());

        $detail = Finding::query()->sole()->detail;

        $this->assertNotNull($detail);
        $this->assertLessThanOrEqual(Finding::DETAIL_MAX, mb_strlen($detail));
    }

    /** Ein leerer Wortlaut ist kein Wortlaut. */
    public function test_an_empty_detail_becomes_null(): void
    {
        $this->assertNull(Finding::trimDetail(''));
        $this->assertNull(Finding::trimDetail("  \n "));
        $this->assertNull(Finding::trimDetail(null));
        $this->assertSame('etwas', Finding::trimDetail("  etwas\n"));
    }

    /**
     * Ein Grund, den die Prüfung nicht kennt, kommt nicht in die Datenbank.
     *
     * Er kommt immer aus dem Code, der den Befund anlegt, und nie von aussen —
     * also ist er ein Programmierfehler und soll einer bleiben.
     */
    public function test_an_unknown_reason_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FindingLog)->replace(FindingCheck::QuotaState, [
            ['subject' => '/', 'reason' => 'gibtsnicht', 'detail' => null],
        ], Carbon::now());
    }
}
