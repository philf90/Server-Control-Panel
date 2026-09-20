<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CollectTraffic;
use App\Support\Web\AccessCounts;
use PHPUnit\Framework\TestCase;

/**
 * Zählt der Nachtlauf nur die Tage, die er zählen darf?
 *
 * **Die Regel kommt aus `docs/129 §5` und ist eine Entscheidung.** Beim
 * Übergang auf das neue Protokollformat stehen in einer Datei beide Zeitalter.
 * Zur Wahl standen: den Übergang für immer im Leser tragen — oder einen Tag,
 * der nicht vollständig im neuen Format steht, gar nicht zählen. Der Plan hat
 * das zweite genommen:
 *
 * > **Ein Tag ohne Zahlen ist ehrlicher als ein Tag mit halben.**
 *
 * Diese Prüfungen brauchen weder Agent noch Platte noch Uhr — der laufende Tag
 * wird übergeben. Ein Prüfstand, der auf die echte Uhr sieht, lässt sich nur
 * zur richtigen Tageszeit fahren.
 */
final class TrafficEraTest extends TestCase
{
    private const HEUTE = '2026-09-21';

    /**
     * @param  array<string, array<string, int>>  $tage
     * @return array<string, mixed>
     */
    private function meldung(array $tage, string $domain = 'beispiel.de'): array
    {
        return ['domains' => [[
            'subscription' => 'p1001',
            'domain' => $domain,
            'days' => $tage,
        ]]];
    }

    /** @return array<string, int> */
    private function tag(int $legacy = 0): array
    {
        return ['requests' => 4, 'sent' => 3011, 'received' => 372, 'errors' => 1, 'legacy' => $legacy];
    }

    public function test_a_finished_clean_day_is_counted(): void
    {
        $topf = AccessCounts::split($this->meldung(['2026-09-20' => $this->tag()]), self::HEUTE);

        $this->assertSame([[
            'subscription' => 'p1001',
            'domain' => 'beispiel.de',
            'day' => '2026-09-20',
            'requests' => 4,
            'sent' => 3011,
            'received' => 372,
            'errors' => 1,
        ]], $topf['countable']);

        $this->assertSame([], $topf['skipped']);
        $this->assertSame([], $topf['open']);
        $this->assertSame(0, $topf['incomplete']);
    }

    /**
     * **Was das Budget liegen liess, macht den Lauf unvollständig.**
     *
     * Der Agent lässt Domains liegen, statt in das Zeitlimit des Aufrufers zu
     * laufen und gar nichts zu liefern. Was er liegen liess, ist kein
     * Schönheitsfehler: Dieser Lauf hat seinen Tag nicht fertig gezählt, und
     * der nächste wird es auch nicht — Protokolle werden nicht kürzer. Ein
     * Timer, der darüber grün bliebe, verschwiege die Meldung, für die es ihn
     * gibt.
     */
    public function test_pending_domains_make_the_run_incomplete(): void
    {
        $meldung = $this->meldung(['2026-09-20' => $this->tag()]);
        $meldung['pending'] = [
            ['subscription' => 'p1002', 'domain' => 'zwei.de'],
            ['subscription' => 'p1003', 'domain' => 'drei.de'],
        ];

        $topf = AccessCounts::split($meldung, self::HEUTE);

        $this->assertSame(2, $topf['incomplete']);

        // Und was gezählt wurde, bleibt gezählt — das Liegengebliebene macht
        // die fertigen Tage nicht ungültig.
        $this->assertCount(1, $topf['countable']);
    }

    /**
     * **Eine einzige alte Zeile reicht.**
     *
     * Nicht „überwiegend neu" und nicht „ab der Hälfte": Der Tag ist entweder
     * ganz im neuen Format geschrieben oder er wird nicht gezählt. Eine
     * Schwelle wäre eine Zahl, die jemand später anders setzt.
     */
    public function test_one_legacy_line_skips_the_whole_day(): void
    {
        $topf = AccessCounts::split($this->meldung(['2026-09-20' => $this->tag(legacy: 1)]), self::HEUTE);

        $this->assertSame([], $topf['countable']);
        $this->assertSame([[
            'subscription' => 'p1001',
            'domain' => 'beispiel.de',
            'day' => '2026-09-20',
            'legacy' => 1,
        ]], $topf['skipped']);
    }

    /**
     * **Der laufende Tag ist nicht falsch, er ist noch nicht fertig.**
     *
     * Er steht deshalb im eigenen Topf. Käme er unter „übersprungen", suchte
     * der Betreiber nach einem Server-Block, der längst umgestellt ist.
     */
    public function test_the_running_day_is_open_and_not_counted(): void
    {
        $topf = AccessCounts::split($this->meldung([self::HEUTE => $this->tag()]), self::HEUTE);

        $this->assertSame([], $topf['countable']);
        $this->assertSame([], $topf['skipped']);
        $this->assertSame([[
            'subscription' => 'p1001',
            'domain' => 'beispiel.de',
            'day' => self::HEUTE,
        ]], $topf['open']);
    }

    /**
     * **Und die Reihenfolge der beiden Gründe trägt mit.**
     *
     * Ein laufender Tag mit alten Zeilen ist „noch offen" und nicht
     * „übersprungen" — sonst meldete der Lauf jede Nacht eine Domain als
     * falsch formatiert, deren heutiger Tag schlicht noch läuft.
     */
    public function test_a_running_day_with_legacy_lines_is_still_only_open(): void
    {
        $topf = AccessCounts::split($this->meldung([self::HEUTE => $this->tag(legacy: 9)]), self::HEUTE);

        $this->assertSame([], $topf['skipped']);
        $this->assertCount(1, $topf['open']);
    }

    /** Jede Domain und jeder Tag landen für sich — nicht je Domain gebündelt. */
    public function test_every_day_of_every_domain_is_decided_on_its_own(): void
    {
        $meldung = ['domains' => [
            ['subscription' => 'p1001', 'domain' => 'eins.de', 'days' => [
                '2026-09-19' => $this->tag(),
                '2026-09-20' => $this->tag(legacy: 2),
                self::HEUTE => $this->tag(),
            ]],
            ['subscription' => 'p1002', 'domain' => 'zwei.de', 'days' => [
                '2026-09-20' => $this->tag(),
            ]],
        ]];

        $topf = AccessCounts::split($meldung, self::HEUTE);

        $this->assertCount(2, $topf['countable']);
        $this->assertCount(1, $topf['skipped']);
        $this->assertCount(1, $topf['open']);
        $this->assertSame('eins.de', $topf['skipped'][0]['domain']);
        $this->assertSame('zwei.de', $topf['countable'][1]['domain']);
    }

    /**
     * **Eine Meldung ohne Domains ergibt drei leere Töpfe und keine Ausnahme.**
     *
     * Der Nachtlauf läuft auf jedem Server, auch auf einem ohne ein einziges
     * Abonnement. Ein Absturz dort wäre eine rote Unit jede Nacht, für nichts.
     */
    public function test_a_report_without_domains_is_empty_and_not_an_error(): void
    {
        foreach ([[], ['domains' => []], ['domains' => 'Unrat'], ['domains' => [null, 'Unrat']]] as $meldung) {
            $topf = AccessCounts::split($meldung, self::HEUTE);

            $this->assertSame([], $topf['countable']);
            $this->assertSame([], $topf['skipped']);
            $this->assertSame([], $topf['open']);
            $this->assertSame(0, $topf['incomplete']);
        }
    }

    /**
     * **Und der Nachtlauf benutzt die Regel auch.**
     *
     * Die Prüfungen darüber halten {@see AccessCounts} in Ordnung. Sie sagen
     * nichts darüber, ob {@see CollectTraffic} das
     * Ergebnis auch auswertet — und ein Lauf, der `incomplete` läse und
     * trotzdem grün bliebe, wäre genau die stille Unit, gegen die es diese
     * Zahl gibt. Geprüft wird am Quelltext, weil ein Prüfstand dafür einen
     * Agenten bräuchte.
     */
    public function test_the_nightly_run_fails_on_an_incomplete_report(): void
    {
        $quelle = file_get_contents(__DIR__.'/../../app/Console/Commands/CollectTraffic.php');

        $this->assertIsString($quelle);
        $this->assertMatchesRegularExpression(
            "/incomplete'\] > 0\).*?return self::FAILURE;/s",
            $quelle,
            'Ein unvollständiger Lauf muss ein Fehlschlag sein.',
        );
        /*
         * **Und der laufende Tag kommt vom Server und nicht aus `now()`.**
         *
         * `config/app.php` steht fest auf `UTC`. Auf einem Server in `+0200`
         * wäre der gestrige Tag um 01:30 Ortszeit noch „heute" und damit jede
         * Nacht „noch offen" — gezählt würde nie etwas, und der Lauf bliebe
         * dabei grün.
         */
        $this->assertStringContainsString('ServerZone::current()', $quelle);
        $this->assertStringContainsString('setTimezone($zone)', $quelle);
    }

    /**
     * **Was diese Aufteilung nicht sagt, und zwar ausdrücklich.**
     *
     * Eine Domain ohne eine einzige Zeile hat keinen Tag — sie taucht in
     * keinem der drei Töpfe auf. „Gestern null Verkehr" und „gestern nicht
     * gezählt" sind damit hier nicht zu unterscheiden. Das ist richtig so, denn
     * der Agent sieht nur Zeilen; wer die Domains kennt, ist das Panel, und
     * die Lücke zu füllen ist B3 und nicht diese Klasse.
     */
    public function test_a_domain_without_lines_produces_no_row_at_all(): void
    {
        $topf = AccessCounts::split($this->meldung([]), self::HEUTE);

        $this->assertSame([], $topf['countable']);
        $this->assertSame([], $topf['skipped']);
        $this->assertSame([], $topf['open']);
    }
}
