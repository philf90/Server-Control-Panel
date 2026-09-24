<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\DomainMetric;
use App\Models\Subscription;
use App\Support\Metrics\Daily;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\AccessCounts;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use SrvPanel\Agent\Ops\WebAccessCount;
use SrvPanel\Agent\Site;
use Tests\TestCase;

/**
 * Ein Tag überlebt die Rotation — in jeder Reihenfolge zweier Nächte.
 *
 * ## Der Fund
 *
 * `docs/134 §0` Punkt 2, gefunden am 24. September 2026 beim Ausschreiben des
 * Abnahmelaufs: `logrotate` dreht irgendwann in der Stunde nach Mitternacht,
 * und der Zähllauf fällt zufällig in dieselbe Stunde. Was ein Tag bis zu
 * seiner Rotation schreibt — sein **Kopf** —, steht in der Datei des Vortags
 * und heisst am nächsten Morgen `access.log.2.gz`. Der Nachtlauf las zwei
 * Dateien und legte jeden fertigen Tag ab. Nach der Rotation gezählt, fehlte
 * dem Tag sein Anfang; davor gezählt, überschrieb ihn die nächste Nacht mit
 * der unvollständigen Sicht. Von den vier Reihenfolgen zweier Nächte blieb der
 * Tag in genau einer vollständig.
 *
 * > **Ein Lauf, der denselben Tag mehrfach sieht und überschreibt, behält die
 * > letzte Sicht — und die letzte ist nicht die vollständigste.**
 *
 * ## Was hier echt ist und was nachgebaut
 *
 * Gezählt, aufgeteilt und abgelegt wird mit den echten Teilen —
 * {@see WebAccessCount::overRoot()}, {@see AccessCounts::split()} und
 * {@see Daily::record()} in die Datenbank. Nachgebaut ist allein das
 * Umbenennen, so wie `logrotate` es mit `compress` und `delaycompress` tut:
 * `.1` wird gepackt zu `.2.gz`, `access.log` wird `.1`, und es entsteht ein
 * neues `access.log`. Die Namen stehen dabei als Wörter und nicht als
 * Konstanten aus {@see Site} — sie sind die von `logrotate`, und ein Nachbau,
 * der sie beim Leser borgte, folgte einer falsch umbenannten Konstante mit.
 *
 * Ob `logrotate` wirklich so umbenennt, misst `tests/tageswechsel-nachbauen.sh`
 * mit dem echten `logrotate` und der echten Vorlage. Hier stünde es sonst als
 * Abhängigkeit, die die CI nicht zusagt.
 *
 * ## Was er nicht kann
 *
 * Er rotiert je Nacht genau einmal. Eine zweite Rotation am selben Tag — ein
 * `logrotate -f` von Hand — schiebt den Kopf eines Tages zwei Dateien weiter,
 * und dafür ist die Behebung nicht gebaut.
 */
final class TrafficRotationTest extends TestCase
{
    use RefreshDatabase;

    private const SUBSCRIPTION = 'beispiel.de';

    private const DOMAIN = 'beispiel.de';

    /** Der gemessene Tag und seine Nachbarn. */
    private const DAY_BEFORE = '2026-09-21';

    private const DAY = '2026-09-22';

    private const DAY_AFTER = '2026-09-23';

    private const TWO_DAYS_AFTER = '2026-09-24';

    private string $rig = '';

    private int $number = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rig = sys_get_temp_dir().'/srvpanel-rotation-'.bin2hex(random_bytes(6));
        mkdir($this->logs(), 0o700, true);

        app(Tenancy::class)->withoutRestriction(function (): void {
            $subscription = Subscription::factory()->create(['name' => self::SUBSCRIPTION]);
            Domain::factory()->create(['subscription_id' => $subscription->id, 'name' => self::DOMAIN]);
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logs().'/*') ?: [] as $datei) {
            unlink($datei);
        }

        foreach ([$this->logs(), dirname($this->logs()), dirname($this->logs(), 2), $this->rig] as $verzeichnis) {
            if (is_dir($verzeichnis)) {
                rmdir($verzeichnis);
            }
        }

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function orders(): array
    {
        return [
            'vor · vor' => ['vor', 'vor'],
            'vor · nach' => ['vor', 'nach'],
            'nach · vor' => ['nach', 'vor'],
            'nach · nach' => ['nach', 'nach'],
        ];
    }

    /**
     * **Der Tag trägt eine Zeile vor der Rotation und drei danach.** Jede Zeile
     * hat eine eigene Bytezahl — eine Summe verrät, welche fehlt.
     *
     * Die Zeile vor der Rotation ist die Gegenprobe, die den Fund überhaupt
     * sichtbar macht: Ohne sie verliert auch die alte Fassung nichts, und genau
     * deshalb hat es niemand gesehen.
     */
    #[DataProvider('orders')]
    public function test_a_day_is_recorded_whole_in_every_order(string $first, string $second): void
    {
        $this->write(self::DAY_BEFORE, '23:59');

        // Die Nacht, in der der Kopf des gemessenen Tages entsteht.
        $kopf = $this->night(self::DAY, 'nach');

        $rumpf = [
            $this->write(self::DAY, '00:03'),
            $this->write(self::DAY, '12:00'),
            $this->write(self::DAY, '23:59'),
        ];

        $this->night(self::DAY_AFTER, $first);
        $this->write(self::DAY_AFTER, '12:00');
        $this->night(self::TWO_DAYS_AFTER, $second);

        // -1 und nicht 0 für eine fehlende Zeile: Sonst sähe „nie abgelegt" aus
        // wie „abgelegt, und es war nichts".
        $zeile = $this->row(self::DAY);

        $this->assertSame(
            ['requests' => 4, 'traffic_sent_bytes' => $kopf + array_sum($rumpf)],
            ['requests' => $zeile['requests'] ?? -1, 'traffic_sent_bytes' => $zeile['traffic_sent_bytes'] ?? -1],
            sprintf(
                'Der %s steht nach den Nächten „%s" und „%s" nicht vollständig da: Kopf %d Bytes, Rumpf %d.',
                self::DAY,
                $first,
                $second,
                $kopf,
                array_sum($rumpf),
            ),
        );
    }

    private function logs(): string
    {
        return Site::logsRootIn($this->rig, self::SUBSCRIPTION).'/'.self::DOMAIN;
    }

    /**
     * Eine Zeile im Format `srvpanel`, angehängt an `access.log`.
     *
     * @return int die Bytes, die sie als gesendet trägt
     */
    private function write(string $day, string $time): int
    {
        $this->number++;
        $bytes = 1000 * $this->number;

        file_put_contents($this->logs().'/access.log', sprintf(
            "203.0.113.9 - - [%s:%s:00 +0200] \"GET /?n=%d HTTP/1.1\" 200 %d \"-\" \"rotation\" %d %d\n",
            (new DateTimeImmutable($day))->format('d/M/Y'),
            $time,
            $this->number,
            $bytes,
            $bytes,
            $this->number,
        ), FILE_APPEND);

        return $bytes;
    }

    /**
     * Eine Nacht: die erste Zeile des neuen Tages, dann Rotation und Zähllauf
     * in der gegebenen Reihenfolge.
     *
     * @return int die Bytes der ersten Zeile des neuen Tages
     */
    private function night(string $today, string $order): int
    {
        $kopf = $this->write($today, '00:01');

        if ($order === 'vor') {
            $this->tally($today);
            $this->rotate();
        } else {
            $this->rotate();
            $this->tally($today);
        }

        return $kopf;
    }

    /** Eine Rotation, wie `logrotate` sie mit `compress` und `delaycompress` macht. */
    private function rotate(): void
    {
        $logs = $this->logs();

        for ($n = 13; $n >= 2; $n--) {
            if (is_file("{$logs}/access.log.{$n}.gz")) {
                rename("{$logs}/access.log.{$n}.gz", "{$logs}/access.log.".($n + 1).'.gz');
            }
        }

        if (is_file("{$logs}/access.log.1")) {
            file_put_contents("{$logs}/access.log.2.gz", gzencode((string) file_get_contents("{$logs}/access.log.1")));
            unlink("{$logs}/access.log.1");
        }

        rename("{$logs}/access.log", "{$logs}/access.log.1");
        touch("{$logs}/access.log");
    }

    /** Ein Zähllauf mit den echten Teilen, bis in die Datenbank. */
    private function tally(string $today): void
    {
        $zahl = WebAccessCount::overRoot($this->rig, microtime(true) + 30);

        app(Daily::class)->record(AccessCounts::split($zahl, $today)['countable']);
    }

    /** @return array<string, int> */
    private function row(string $day): array
    {
        return DomainMetric::withoutGlobalScopes()
            ->whereDate('day', $day)
            ->get()
            ->mapWithKeys(fn (DomainMetric $metric): array => [$metric->metric->value => (int) $metric->value])
            ->all();
    }
}
