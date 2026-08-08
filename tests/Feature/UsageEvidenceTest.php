<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MeasureUsage;
use App\Models\Database;
use App\Models\Subscription;
use App\Support\Databases\Usage as DatabaseUsage;
use App\Support\Subscriptions\Usage as DiskUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\Support\ReadsMethodSource;
use Tests\TestCase;

/**
 * Eine Messung belegt, was sie gelesen hat — nicht, was sie geschrieben hat.
 *
 * **Der Anlass ist ein grüner Abnahmelauf.** Am 8. August 2026 meldete
 * `srvpanel usage` auf `cloudsrv24`:
 *
 *     2 Datenbank(en) gemessen.
 *
 * Zwei ist die richtige Zahl — es gab genau zwei Datenbanken. Und dieselbe
 * Zeile wäre erschienen, wenn `db.usage` **gar nichts** geliefert hätte: Eine
 * Datenbank ohne Treffer bekommt `size_bytes = 0` als gemessene Null, und das ist
 * richtig (`information_schema` führt ein leeres Schema nicht auf). Gezählt
 * wurden aber die *geschriebenen Zeilen*, nicht die gelesenen Schemata. Ein
 * Tippfehler in der Abfrage, ein `GROUP BY` an der falschen Stelle, eine
 * Aussonderung, die zu viel aussondert — jeder dieser Fälle liest sich als
 * Erfolg.
 *
 * Das ist wortwörtlich die Lehre aus dem P4-Abnahmelauf, eine Ausbaustufe
 * weiter: **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was
 * gezählt wurde.** Dort meldete die Erneuerung „1 fällig, 1 bestellt" und
 * bestellte das falsche Zertifikat; hier meldet die Messung eine Anzahl und
 * sagt nichts darüber, ob sie etwas gemessen hat.
 *
 * **Warum dieser Test beide Messungen prüft.** {@see DatabaseUsage} ist der
 * erklärte Zwilling von {@see DiskUsage} — bis in die Methodennamen, damit wer
 * den einen liest den anderen versteht. Der Befund kam bei den Datenbanken, die
 * Lücke stand in beiden. Ein Wächter, der nur eine von zwei gleichen Stellen
 * hält, ist der Wächter, den die nächste Abschrift umgeht.
 *
 * **Und warum als Verhalten und nicht als Textprüfung.** Ob `reported` in der
 * Klasse *steht*, ist die falsche Frage; die richtige ist, ob es sich von
 * `measured` unterscheidet, wenn die Messung leer zurückkommt. `apply()` nimmt
 * die Antwort des Agenten als Feld — genau dafür ist es vom Holen getrennt
 * (docs/36 §9), und deshalb geht das hier ohne MariaDB und ohne Socket.
 */
final class UsageEvidenceTest extends TestCase
{
    use ReadsMethodSource;
    use RefreshDatabase;

    /**
     * Ein Abonnement mit Systembenutzer.
     *
     * Der Name muss stehen: Er ist der Schlüssel, unter dem beide Messungen
     * zuordnen — das Präfix der Datenbanknamen hier, der Benutzer der
     * Quota-Datei dort. Die Factory setzt ihn nicht, weil ihn im Betrieb
     * `Lifecycle::claim()` vergibt und nicht das Anlegen der Zeile.
     */
    private function subscription(string $systemUser = 'p1000'): Subscription
    {
        return Subscription::factory()->create(['system_user' => $systemUser]);
    }

    /**
     * Zwei Datenbanken an einem Abonnement — der Bestand des Abnahmelaufs.
     *
     * @return list<Database>
     */
    private function twoDatabases(): array
    {
        $subscription = $this->subscription();

        return [
            Database::factory()->forSubscription($subscription, 'eins')->create(),
            Database::factory()->forSubscription($subscription, 'zwei')->create(),
        ];
    }

    /**
     * Der Fall, der vorher wie ein Erfolg aussah: nichts gelesen.
     *
     * Die Zeilen werden geschrieben — das ist beabsichtigt und richtig, denn
     * eine leere Datenbank steht nicht in `information_schema`. Aber `reported`
     * ist null, und damit ist der Lauf als das erkennbar, was er ist.
     */
    public function test_a_database_measurement_that_read_nothing_says_so(): void
    {
        $this->twoDatabases();

        $result = app(DatabaseUsage::class)->apply(['available' => true, 'databases' => []]);

        $this->assertSame(2, $result['measured'], 'Geschrieben wird trotzdem — eine leere Datenbank ist eine Null.');
        $this->assertSame(0, $result['reported'], 'Der Server hat nichts genannt, und das muss sichtbar sein.');
        $this->assertSame(0, $result['matched']);
    }

    /**
     * Und der Fall, in dem gelesen wurde: die Zahlen gehen auseinander.
     *
     * Ein Schema des Panels, das keiner Zeile zuzuordnen ist, gibt es auf einem
     * Server, auf dem ein Rückbau steckengeblieben ist — genau der Zustand, den
     * `srvpanel db --prune` aufräumt. Die Messung ist die Stelle, an der er
     * auffällt, denn sie läuft jede Viertelstunde.
     */
    public function test_a_schema_without_a_row_shows_up_as_a_difference(): void
    {
        [$eins] = $this->twoDatabases();

        $result = app(DatabaseUsage::class)->apply([
            'available' => true,
            'databases' => [
                $eins->name => 52_428_800,
                'p1001_verwaist' => 1_048_576,
            ],
        ]);

        $this->assertSame(2, $result['measured']);
        $this->assertSame(2, $result['reported']);
        $this->assertSame(1, $result['matched'], 'Die zweite Datenbank stand nicht in der Antwort.');

        $this->assertSame(52_428_800, (int) $eins->refresh()->size_bytes);
    }

    /** Ohne Datenbankserver sind alle drei Zahlen null — und keine geraten. */
    public function test_without_a_database_server_nothing_is_counted(): void
    {
        $this->twoDatabases();

        $result = app(DatabaseUsage::class)->apply(['available' => false, 'reason' => 'kein MariaDB']);

        $this->assertSame(
            ['measured' => 0, 'reported' => 0, 'matched' => 0, 'available' => false, 'reason' => 'kein MariaDB'],
            $result,
        );
    }

    /** Derselbe Fall am Zwilling: Die Quota-Datei gab nichts her. */
    public function test_a_disk_measurement_that_read_nothing_says_so(): void
    {
        $this->subscription();

        $result = app(DiskUsage::class)->apply([
            'available' => true,
            'device' => '/dev/vda3',
            'users' => [],
        ]);

        $this->assertSame(1, $result['measured']);
        $this->assertSame(0, $result['reported'], 'Eine leere Quota-Datei darf nicht wie eine gelesene aussehen.');
        $this->assertSame(0, $result['matched']);
    }

    public function test_a_quota_entry_without_a_subscription_shows_up_as_a_difference(): void
    {
        $subscription = $this->subscription();

        $result = app(DiskUsage::class)->apply([
            'available' => true,
            'device' => '/dev/vda3',
            'users' => [
                (string) $subscription->system_user => ['used_mb' => 412, 'limit_mb' => 5_120],
                'p999999' => ['used_mb' => 7, 'limit_mb' => 0],
            ],
        ]);

        $this->assertSame(1, $result['measured']);
        $this->assertSame(2, $result['reported']);
        $this->assertSame(1, $result['matched'], 'Ein Systembenutzer ohne Abonnement gehört in die Differenz.');

        $this->assertSame(412, (int) $subscription->refresh()->disk_used_mb);
    }

    /**
     * Und beide Messungen zeigen ihre drei Zahlen auch an.
     *
     * **Diese eine Behauptung ist eine Textprüfung, und zwar aus einem Grund,
     * der benannt sein muss.** Das Kommando lässt sich hier nicht fahren: Es
     * zieht `Client` aus dem Container, in diesem Container gibt es keinen
     * Agenten (CLAUDE.md), und beide Messklassen sind `final` — es gibt nichts
     * dazwischenzuschieben. Was bleibt, ist die Frage, ob die Zahlen, die die
     * Tests oben belegen, überhaupt jemand ausgibt. Eine Zahl im Rückgabewert,
     * die niemand zeigt, ist kein Beleg, sondern eine Zahl.
     *
     * Gezählt wird zweimal, weil es zwei Messungen sind. Wer eine dritte
     * hinzufügt, hebt die Zahl — und merkt dabei, dass sie dieselbe Auskunft
     * braucht.
     */
    public function test_both_measurements_print_all_three_numbers(): void
    {
        // Je Messung eine Methode, und geprüft wird in jeder getrennt: Über die
        // ganze Datei gezählt trüge eine Messung die andere durch. Die Namen
        // stehen nicht hier — gesucht wird nach den Methoden, die etwas melden,
        // damit eine Umbenennung den Wächter nicht abschaltet.
        $bodies = [];

        foreach ((new ReflectionClass(MeasureUsage::class))->getMethods() as $method) {
            $body = $this->methodSource(MeasureUsage::class, $method->getName()) ?? '';

            if (str_contains($body, '$this->info(sprintf(')) {
                $bodies[] = $body;
            }
        }

        $this->assertCount(2, $bodies, 'Zwei Messungen, zwei Meldungen — eine andere Zahl heisst, die Aufteilung hat sich geändert.');

        foreach ($bodies as $body) {
            /*
             * **Die Meldung selbst und nicht der Rumpf.** Der erste Anlauf
             * dieses Wächters suchte im ganzen Methodenrumpf — und der
             * Gegenbeweis lief ins Leere: Nimmt man die Zahlen aus der
             * Erfolgsmeldung heraus, stehen sie weiter in der Warnung darunter,
             * und die Behauptung bleibt grün. Die Warnung kommt aber nur im
             * Ausnahmefall; die Zeile, die jeder Lauf zeigt, ist diese hier.
             */
            $this->assertSame(
                1,
                preg_match('/\$this->info\(sprintf\((?<arguments>.*?)\)\);/s', $body, $call),
                'Ohne die Meldung im Blick prüft dieser Wächter den Rumpf und nicht die Ausgabe.',
            );

            foreach (['measured', 'reported', 'matched'] as $number) {
                $this->assertStringContainsString(
                    "\$result['".$number."']",
                    $call['arguments'],
                    "Jede Messung nennt `{$number}` in ihrer Meldung, oder eine von beiden ist wieder unbelegt.",
                );
            }

            $this->assertStringContainsString(
                "\$result['reported'] !== \$result['matched']",
                $body,
                'Und jede meldet das Missverhältnis — sonst muss jemand die Zahlen im Journal vergleichen.',
            );
        }
    }
}
