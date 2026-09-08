<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\CronState;
use SrvPanel\Agent\Result;

/**
 * Der Zeitplan eines `cron.*`-Verzeichnisses — A6, `docs/111 §5`.
 *
 * ## Die beiden Funde, gegen die es diesen Wächter gibt
 *
 * **`cron.yearly` steht in keiner Zeile** (`docs/81 §2.3t` M1). Ein Skript dort
 * läuft nie, und das Verzeichnis sieht aus wie die anderen vier.
 *
 * > **Ein Verzeichnis, das dasteht und in keinem Zeitplan vorkommt, ist von
 * > einem, das läuft, nicht zu unterscheiden — ausser man liest den
 * > Zeitplan.**
 *
 * **Drei der vier Zeilen tragen `test -x /usr/sbin/anacron ||`.** Mit anacron
 * tut cron für daily, weekly und monthly gar nichts; die Zeitpunkte stehen dann
 * in `/etc/anacrontab` und sind ganz andere.
 *
 * > **Eine Zeile, die eine Bedingung trägt, sagt ohne die Bedingung das
 * > Gegenteil.**
 *
 * ## Und ein dritter Zustand, den der Plan nicht hatte
 *
 * Ist `/etc/crontab` nicht lesbar, ist der Zeitplan **unbekannt** — und das
 * sähe ohne ein eigenes Feld genauso aus wie `cron.yearly`.
 *
 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
 * > tun".**
 */
final class CronScheduleTest extends TestCase
{
    /** Die vier gemessenen Zeilen aus `/etc/crontab`. */
    private const CRONTAB = "17 *\t* * *\troot\tcd / && run-parts --report /etc/cron.hourly\n"
        ."25 6\t* * *\troot\ttest -x /usr/sbin/anacron || { cd / && run-parts --report /etc/cron.daily; }\n"
        ."47 6\t* * 7\troot\ttest -x /usr/sbin/anacron || { cd / && run-parts --report /etc/cron.weekly; }\n"
        ."52 6\t1 * *\troot\ttest -x /usr/sbin/anacron || { cd / && run-parts --report /etc/cron.monthly; }\n";

    /** Die fünf Verzeichnisse, die auf dem gemessenen System liegen. */
    private const DIRECTORIES = [
        '/etc/cron.hourly', '/etc/cron.daily', '/etc/cron.weekly',
        '/etc/cron.monthly', '/etc/cron.yearly',
    ];

    /**
     * Jedes Verzeichnis mit einer Zeile trägt ihren Zeitpunkt.
     */
    public function test_a_directory_with_a_line_carries_its_time(): void
    {
        $plaene = $this->plaene();

        $this->assertSame('17 * * * *', $plaene['cron.hourly']['schedule']);
        $this->assertSame('25 6 * * *', $plaene['cron.daily']['schedule']);
        $this->assertSame('47 6 * * 7', $plaene['cron.weekly']['schedule']);
        $this->assertSame('52 6 1 * *', $plaene['cron.monthly']['schedule']);
    }

    /**
     * Und `cron.yearly` trägt keinen — ohne dass etwas fehlt.
     *
     * `known` steht dabei auf `true`: Die Quelle war lesbar, es gibt nur keine
     * Zeile. Genau daran hängt Punkt 4 des Abnahmekriteriums.
     */
    public function test_a_directory_without_a_line_carries_none(): void
    {
        $jaehrlich = $this->plaene()['cron.yearly'];

        $this->assertNull($jaehrlich['schedule'], 'cron.yearly hat einen Zeitpunkt bekommen, den es nicht gibt.');
        $this->assertNull($jaehrlich['conditional']);
        $this->assertTrue($jaehrlich['known'], 'Ein fehlender Zeitplan wird als „nicht nachgesehen" ausgegeben.');
    }

    /**
     * Der Vorbehalt steht am Zeitpunkt und nicht daneben — in beide Richtungen.
     */
    public function test_the_caveat_stands_where_the_line_carries_it(): void
    {
        $plaene = $this->plaene();

        $this->assertSame('anacron', $plaene['cron.daily']['conditional']);
        $this->assertSame('anacron', $plaene['cron.weekly']['conditional']);
        $this->assertSame('anacron', $plaene['cron.monthly']['conditional']);

        // Und die Gegenrichtung im selben Fall: `cron.hourly` läuft ohne
        // Vorbehalt, und ein Leser, der ihn überall setzt, wäre grün, wenn hier
        // nur die drei stünden.
        $this->assertNull($plaene['cron.hourly']['conditional'], 'cron.hourly hat einen Vorbehalt bekommen, den seine Zeile nicht trägt.');
    }

    /**
     * Ist `/etc/crontab` nicht lesbar, ist der Zeitplan unbekannt.
     *
     * **Und nicht „es gibt keinen".** Beide Zustände liefern `schedule: null`;
     * unterschieden werden sie allein an `known`.
     */
    public function test_an_unreadable_crontab_leaves_the_schedule_unknown(): void
    {
        $zustand = CronState::read(
            [CronState::CRONTAB => null],
            $this->eingaben(),
            false,
        );

        foreach ($zustand['directories'] as $verzeichnis) {
            $this->assertFalse($verzeichnis['known'], sprintf('%s behauptet, sein Zeitplan sei bekannt.', $verzeichnis['name']));
            $this->assertNull($verzeichnis['schedule']);
        }
    }

    /**
     * Ob anacron da ist, sagt die Antwort — und der Leser rät es nicht.
     *
     * Die beiden Felder sind zwei Auskünfte: Die Zeile sagt, dass sie einen
     * Vorbehalt hat, `anacron` sagt, ob er greift. Erst zusammen ergeben sie
     * eine Aussage darüber, wann `cron.daily` läuft.
     */
    public function test_the_presence_of_anacron_travels_as_its_own_field(): void
    {
        foreach ([true, false] as $da) {
            $zustand = CronState::read([CronState::CRONTAB => self::CRONTAB], $this->eingaben(), $da);

            $this->assertSame($da, $zustand['anacron']);

            // Der Vorbehalt hängt an der Zeile und nicht an der Anwesenheit —
            // sonst verschwände er auf einem Server ohne anacron, und die Seite
            // könnte nicht mehr sagen, warum sie den Zeitpunkt zeigt.
            $plaene = array_column($zustand['directories'], null, 'name');
            $this->assertSame('anacron', $plaene['cron.daily']['conditional']);
        }
    }

    /**
     * Ohne diese Zahl wären die Behauptungen oben auch bei einem Leser grün,
     * der gar keine Verzeichnisse ausgibt.
     */
    public function test_the_probes_produce_something(): void
    {
        $this->assertCount(5, $this->plaene(), 'Es kommen keine fünf Verzeichnisse zurück — dann prüft dieser Wächter nichts.');
    }

    /** @return array<string, array<string, mixed>> */
    private function plaene(): array
    {
        $zustand = CronState::read([CronState::CRONTAB => self::CRONTAB], $this->eingaben(), true);

        return array_column($zustand['directories'], null, 'name');
    }

    /** @return array<string, array{names: list<string>, test: ?Result}> */
    private function eingaben(): array
    {
        $eingaben = [];

        foreach (self::DIRECTORIES as $pfad) {
            $eingaben[$pfad] = ['names' => [], 'test' => new Result(0, '', '')];
        }

        return $eingaben;
    }
}
