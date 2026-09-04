<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use App\Support\Web\WebLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;
use Tests\TestCase;

/**
 * Die Naht zwischen Panel und Agent für die voraussichtliche Endzeit (A12).
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * Am 4. September 2026 gab es zwei Fehler an dieser einen Zeile, und **keiner
 * von beiden war von der Wartungsseite aus zu sehen**:
 *
 * `Clock::minuteToUtc()` legt `Y-m-d H:i:s` ab — mit Sekunden, denn ein
 * abgelegter Zeitpunkt ist ein Zeitpunkt. {@see Maintenance::UNTIL}
 * verlangt `Y-m-d H:i` — ohne, denn es ist ein Satz auf einer Seite. Hinaus
 * ging der abgelegte Wert. Sobald eine Endzeit gesetzt war, wies der Agent
 * damit **jedes** `web.site.apply` ab, für jede Domain.
 *
 * Und wäre er durchgekommen, stünde auf der Wartungsseite die UTC-Zeit unter
 * dem Wort „Uhr" — zwei Stunden vor der eingetippten.
 *
 * ## Warum nichts das gemeldet hat
 *
 * `MaintenanceGuardTest` füttert `Maintenance::until()` mit einem selbst
 * geschriebenen `'2026-09-04 16:00'`, und die Prüfungen um `MaintenanceMode`
 * sprechen mit einem Doppel. Beide Seiten waren geprüft; geprüft war nie, dass
 * die eine der anderen etwas gibt, das sie annimmt.
 *
 * > **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst
 * > geschriebenen Wert füttern, prüfen die Naht nicht — sie prüfen zweimal
 * > denselben Prüfkörper.**
 *
 * ## Wie hier gemessen wird
 *
 * An der **Wirkung** und nicht am Quelltext: Der Wert geht durch
 * {@see Site::fromArgs()}, also durch die Tür, an der er auf dem Server
 * ankommt. Jede Behauptung hat ihre Gegenrichtung — sonst bliebe offen, ob die
 * Messung überhaupt etwas anderes als Grün liefern kann.
 */
final class MaintenanceSeamTest extends TestCase
{
    use RefreshDatabase;

    /** Eine Domain, deren Block der Agent schreiben würde. */
    private function domain(): Domain
    {
        $subscription = Subscription::factory()->create(['name' => 'beispiel.de', 'system_user' => 'p1000']);

        return Domain::factory()->for($subscription)->main()->create(['name' => 'beispiel.de']);
    }

    /**
     * Die Endzeit so ablegen, wie die Steuerung es tut.
     *
     * Über {@see Clock::minuteToUtc()} und nicht mit einer hingeschriebenen
     * Zeichenkette: Der Fehler steckte genau in dem, was diese Methode liefert.
     */
    private function ablegen(string $ortszeit): string
    {
        Clock::store('Europe/Berlin');

        $utc = Clock::minuteToUtc($ortszeit);
        self::assertIsString($utc);

        app(Settings::class)->saveMaintenance(true, $utc);

        return $utc;
    }

    public function test_the_agent_accepts_what_the_panel_sends(): void
    {
        $this->ablegen('2026-09-04 16:00');
        $domain = $this->domain();

        $args = app(WebLifecycle::class)->payload($domain);

        // Die Tür selbst: `fromArgs` ruft `Maintenance::until()`.
        $site = Site::fromArgs($args);

        self::assertSame('2026-09-04 16:00', $site->maintenanceUntil);
    }

    /**
     * Die Gegenrichtung — und sie ist der Beleg, dass oben etwas gemessen wird.
     *
     * Der **abgelegte** Wert kommt an derselben Tür nicht durch. Wäre er
     * genauso gültig, sagte die Prüfung darüber nichts.
     */
    public function test_the_stored_value_would_be_refused_at_that_door(): void
    {
        $utc = $this->ablegen('2026-09-04 16:00');
        $domain = $this->domain();

        $args = app(WebLifecycle::class)->payload($domain);
        $args['maintenance_until'] = $utc;

        /*
         * **An der gemeinten Hürde und an keiner anderen.** Eine Gegenprobe,
         * die an einer anderen scheitert als der gemeinten, hat die gemeinte
         * nicht geprüft — genau das ist hier beim ersten Lauf passiert: Ohne
         * `system_user` flog `fromArgs` schon zwei Zeilen früher, und die
         * Prüfung war grün.
         */
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Die voraussichtliche Endzeit muss die Form JJJJ-MM-TT HH:MM haben.');

        Site::fromArgs($args);
    }

    /**
     * Hinaus geht die Ortszeit und nicht die Ablage.
     *
     * Gemessen am Unterschied und nicht an einer erwarteten Zeichenkette: Läge
     * die Zone anders, wäre die Zahl eine andere — dass die beiden Werte
     * **auseinandergehen**, ist die Aussage.
     */
    public function test_the_local_time_goes_out_and_not_the_stored_one(): void
    {
        $utc = $this->ablegen('2026-09-04 16:00');
        $domain = $this->domain();

        $args = app(WebLifecycle::class)->payload($domain);

        self::assertSame('2026-09-04 14:00:00', $utc);
        self::assertSame('2026-09-04 16:00', $args['maintenance_until']);
    }

    /** Der Satz auf der Seite trägt dieselbe Zeit — bis in die Vhost-Datei. */
    public function test_the_sentence_carries_the_local_time(): void
    {
        $this->ablegen('2026-09-04 16:00');
        $domain = $this->domain();

        $konfiguration = SiteTemplate::render(Site::fromArgs(app(WebLifecycle::class)->payload($domain)));

        self::assertStringContainsString('Voraussichtlich ab 2026-09-04 16:00 Uhr', $konfiguration);
        self::assertStringNotContainsString('14:00', $konfiguration);
    }

    /** Ohne Endzeit geht nichts hinaus — und der Block sagt dann nur den Grund. */
    public function test_without_an_end_time_the_block_says_only_the_reason(): void
    {
        Clock::store('Europe/Berlin');
        app(Settings::class)->saveMaintenance(true, null);

        $domain = $this->domain();
        $args = app(WebLifecycle::class)->payload($domain);

        self::assertNull($args['maintenance_until']);

        $konfiguration = SiteTemplate::render(Site::fromArgs($args));

        self::assertStringContainsString('wegen Wartungsarbeiten', $konfiguration);
        self::assertStringNotContainsString('Voraussichtlich ab', $konfiguration);
    }
}
