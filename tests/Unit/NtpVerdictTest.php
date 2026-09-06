<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Time\Clock;
use App\Support\Time\ServerTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Result;
use SrvPanel\Agent\TimeState;
use Tests\TestCase;

/**
 * Was der Zeitabgleich auf der Seite sagt (A11, `docs/106 §4`).
 *
 * ## Gemessen an der Wirkung und nicht am Quelltext
 *
 * **Nicht framework-frei, und das hat einen Grund.** `ServerTime::rows()` zeigt
 * die Anzeigezeit daneben, und die kommt aus `settings` — also aus der
 * Datenbank. Ein Fall, der sie umgeht, prüfte den Bereich ohne die Zeile, für
 * die es ihn gibt.
 *
 * Die Prüfkörper sind die **gemessenen** Ausgaben von `timedatectl` aus
 * `docs/81 §2.3r`, und sie gehen durch {@see TimeState::read()} — also durch
 * die Tür und nicht am Leser vorbei. Ein Fall, der `ServerTime::rows()` ein
 * selbst geschriebenes Feld füttert, prüfte zweimal denselben Prüfkörper und
 * nicht die Naht dazwischen.
 *
 * > **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst
 * > geschriebenen Wert füttern, prüfen die Naht nicht.**
 *
 * ## Vier Sätze, und keine zwei gleich
 *
 * „Kein Zeitdienst installiert" behebt man mit `apt-get install`,
 * „ausgeschaltet" mit einem Schalter, und „nicht feststellbar" heisst, dass das
 * Panel es nicht weiss. Wer die vier zusammenzieht, nimmt dem Leser die
 * Entscheidung, was er tun soll.
 */
final class NtpVerdictTest extends TestCase
{
    use RefreshDatabase;

    private const OHNE_DIENST = "Timezone=Etc/UTC\nLocalRTC=no\nCanNTP=no\nNTP=no\nNTPSynchronized=no\n";

    private const AUS = "Timezone=Etc/UTC\nLocalRTC=no\nCanNTP=yes\nNTP=no\nNTPSynchronized=no\n";

    private const AN = "Timezone=Etc/UTC\nLocalRTC=no\nCanNTP=yes\nNTP=yes\nNTPSynchronized=no\n";

    /** Der Zustand, den der Container nicht herstellen konnte — hergeleitet und benannt. */
    private const SYNCHRON = "Timezone=Europe/Berlin\nLocalRTC=yes\nCanNTP=yes\nNTP=yes\nNTPSynchronized=yes\n";

    /** Ein fester Augenblick: `now()` machte diese Klasse ein halbes Jahr grün und ein halbes rot. */
    private const JETZT = '2026-09-06T19:56:00Z';

    /**
     * @param  string  $stdout  eine gemessene Ausgabe von `timedatectl show`
     * @return array<string,string>
     */
    private function zeilen(string $stdout, int $code = 0, ?string $zone = 'Etc/UTC'): array
    {
        return ServerTime::rows(
            TimeState::read(new Result($code, $stdout, '')),
            $zone,
            CarbonImmutable::parse(self::JETZT),
        );
    }

    /**
     * Die drei Dienstzustände und „nicht feststellbar" sind vier Sätze.
     *
     * **Der vierte kommt über einen `rc != 0`** und nicht über ein gesetztes
     * Feld — das ist der Weg, den er auf dem Server nimmt.
     */
    public function test_the_four_states_are_four_sentences(): void
    {
        $saetze = [
            'ohne Dienst' => $this->zeilen(self::OHNE_DIENST)['service'],
            'ausgeschaltet' => $this->zeilen(self::AUS)['service'],
            'eingeschaltet' => $this->zeilen(self::AN)['service'],
            'nicht lesbar' => $this->zeilen('', 1)['service'],
        ];

        self::assertSame('kein Zeitdienst installiert', $saetze['ohne Dienst']);
        self::assertSame('ausgeschaltet', $saetze['ausgeschaltet']);
        self::assertSame('eingeschaltet', $saetze['eingeschaltet']);
        self::assertSame(ServerTime::UNKNOWN, $saetze['nicht lesbar']);

        self::assertCount(4, array_unique($saetze), sprintf(
            'Zwei der vier Zustände sagen dasselbe: %s',
            implode(' · ', $saetze),
        ));
    }

    /**
     * „Uhr abgeglichen" ist eine eigene Frage.
     *
     * Sie hängt nicht am Dienst: „läuft, aber die Uhr stimmt nicht" ist etwas
     * anderes als „läuft nicht". Der Fall mit `NTPSynchronized=yes` ist der,
     * den der Container nicht herstellen konnte (kein Zeitserver erreichbar) —
     * hier steht er als gemessene Form und nicht als gemessener Zustand.
     */
    public function test_synchronized_is_its_own_question(): void
    {
        self::assertSame('nein', $this->zeilen(self::AN)['synchronized']);
        self::assertSame('ja', $this->zeilen(self::SYNCHRON)['synchronized']);
        self::assertSame(ServerTime::UNKNOWN, $this->zeilen('', 1)['synchronized']);

        // Und andersherum: Der Dienst läuft in beiden Fällen.
        self::assertSame('eingeschaltet', $this->zeilen(self::AN)['service']);
        self::assertSame('eingeschaltet', $this->zeilen(self::SYNCHRON)['service']);
    }

    /**
     * Die Hardware-Uhr wird gezeigt und nicht nur gelesen.
     *
     * `docs/106 §5` zählt `local_rtc` in der Antwort des Agenten auf, `§4` hat
     * die Zeile vergessen. Ein Feld, das geschrieben und nie gelesen wird, ist
     * von aussen nicht von einem zu unterscheiden, das es nicht gibt.
     */
    public function test_the_hardware_clock_is_shown(): void
    {
        self::assertSame('UTC', $this->zeilen(self::AN)['clock']);
        self::assertSame('Ortszeit', $this->zeilen(self::SYNCHRON)['clock']);
        self::assertSame(ServerTime::UNKNOWN, $this->zeilen('', 1)['clock']);
    }

    /**
     * Die Zone steht mit Name **und** Beschriftung da.
     *
     * `Etc/UTC` heisst beschriftet schlicht `UTC` (gemessen, M14): Wer nur die
     * Beschriftung zeigt, nennt die Zone nicht, und wer nur den Namen zeigt,
     * verschweigt den Versatz. Sie stehen deshalb **beide** da, auch wenn die
     * Beschriftung im Namen schon vorkommt — `docs/106 §4` schreibt genau das.
     *
     * **Hier stand zuerst die Erwartung `Etc/UTC` allein**, und der Prüfling
     * hatte recht: Beim Zusammenfallen ist nicht der Name gemeint, sondern die
     * Zeichenkette. `UTC` als Zonenname beschriftet sich selbst und steht
     * einmal da; `Etc/UTC` ist ein anderer Name als `UTC` und braucht beides.
     *
     * **Die Zone kommt vom Aufrufer und nicht aus der Antwort des Agenten** —
     * genau deshalb lässt sich hier auch `null` messen, ohne einen Rechner mit
     * kaputtem Symlink zu brauchen.
     */
    public function test_the_zone_carries_name_and_label(): void
    {
        self::assertSame('Europe/Berlin — CEST (UTC+02:00)', $this->zeilen(self::AN, 0, 'Europe/Berlin')['zone']);
        self::assertSame('Etc/UTC — UTC', $this->zeilen(self::AN)['zone']);

        // Der Fall, in dem sie wirklich zusammenfallen: `UTC` steht einmal da
        // und nicht als `UTC — UTC`.
        self::assertSame('UTC', $this->zeilen(self::AN, 0, 'UTC')['zone']);

        // Und der Fall, den der Symlink nicht hergibt: keine ablesbare Zone.
        self::assertSame(ServerTime::UNKNOWN, $this->zeilen(self::AN, 0, null)['zone']);

        // Ein Name, den PHP nicht kennt, bleibt stehen — er ist die Auskunft
        // des Servers, und sie zu verschweigen wäre schlimmer als sie roh zu
        // zeigen. Die Beschriftung fehlt dann.
        self::assertSame('Erfunden/Nichts', $this->zeilen(self::AN, 0, 'Erfunden/Nichts')['zone']);
    }

    /**
     * Beide Zeitzeilen zeigen denselben Augenblick.
     *
     * **Und das ist der Grund für den ganzen Bereich** (`docs/80`): Die Zeit
     * des Servers und die Anzeigezeit werden sonst verwechselt.
     *
     * > **Zwei Angaben, die verwechselt werden können, werden nicht durch eine
     * > Erklärung unterschieden, sondern dadurch, dass man sie nebeneinander
     * > zeigt.**
     *
     * Gemessen mit einem **Versatz**: Ohne ihn sähe eine fehlende Umrechnung
     * wie eine gelungene aus.
     */
    public function test_both_time_rows_show_the_same_moment(): void
    {
        Clock::store('Asia/Kolkata');
        Clock::forget();

        try {
            $zeilen = $this->zeilen(self::AN);

            self::assertSame('2026-09-06 19:56', $zeilen['now'], 'Der Server steht in Etc/UTC.');
            self::assertStringStartsWith('2026-09-07 01:26', $zeilen['display'], 'Kolkata liegt 5:30 weiter.');
            self::assertStringContainsString('IST (UTC+05:30)', $zeilen['display']);

            self::assertNotSame($zeilen['now'], $zeilen['display'], 'Beide Zeilen zeigen dieselbe Zahl — dann rechnet eine von beiden nicht.');
        } finally {
            Clock::forget();
        }
    }

    /**
     * Die Sätze stehen an einer Stelle und nicht in der `.vue`.
     *
     * **Gemessen an der Seite und nicht an `ServerTime`.** Eine zweite Fassung
     * entstünde dort, wo jemand die Anzeige anfasst, ohne die Klasse zu kennen
     * — und sie sähe zuerst richtig aus.
     */
    public function test_the_page_does_not_build_the_sentences_itself(): void
    {
        $seite = (string) file_get_contents(__DIR__.'/../../resources/js/Pages/Settings/General.vue');

        foreach (['kein Zeitdienst installiert', 'ausgeschaltet', ServerTime::UNKNOWN] as $satz) {
            self::assertStringNotContainsString($satz, $seite, sprintf(
                'Die Seite schreibt „%s" selbst — dann gibt es die Zuordnung zweimal.',
                $satz,
            ));
        }
    }
}
