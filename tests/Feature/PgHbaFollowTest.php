<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Databases\Databases;
use App\Support\Databases\PgLifecycle;
use App\Support\Databases\RemoteAccess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReadsMethodSource;

/**
 * Wer den Bestand ändert, zieht den verwalteten Block nach.
 *
 * ## Der Fund, aus dem dieser Wächter entstanden ist
 *
 * Der Sollzustand des Blocks in `pg_hba.conf` ist **Datenbank × Rolle × Netz**
 * (`docs/38 §14.1`). Nach Schritt 10 schrieb ihn nur, wer ein *Netz* anfasste
 * — und die Datenbanken einer Rolle ändern sich anderswo:
 *
 * | Weg | ohne Nachziehung |
 * |---|---|
 * | „Vorhandenen Zugang verbinden" | die Zeile für die neue Datenbank fehlt |
 * | Zugriff entziehen | die Zeile bleibt stehen |
 * | Datenbank entfernt, Rolle überlebt | Zeile für eine Datenbank, die es nicht mehr gibt |
 * | Abonnement gesperrt | Datei und Bestand laufen auseinander |
 *
 * **Der erste ist der ernste**, und er ist das Gegenteil eines
 * Sicherheitslochs: Die fehlende Zeile sperrt aus. Im Panel steht „erreichbar
 * von 203.0.113.5", die Anwendung kommt nicht herein, und der Betreiber sucht
 * den Fehler im Fernzugriff — wo keiner ist.
 *
 * Gefunden hat das kein Test, sondern die Frage, ob sich Schritt 10 abnehmen
 * lässt. Der Abnahmelauf in `docs/38 §19a` kam an keinem der vier Wege vorbei:
 * Er legt **eine** Datenbank an. Die zweite steht seitdem als Punkt 4b darin.
 *
 * > **Ein Abnahmelauf, der den häufigsten Weg nicht geht, misst die Fläche
 * > und nicht den Betrieb.**
 *
 * ## Warum im Quelltext gelesen wird und nicht gemessen
 *
 * Ob {@see RemoteAccess::follow()} zur Laufzeit lief, sähe man nur am Agenten,
 * und `SrvPanel\Agent\Client` ist `final` — es gibt in diesem Testbaum kein
 * Doppel dafür. Was sich prüfen lässt, ist der Bezug: **Jede Stelle, die die
 * Zuordnung von Datenbanken zu Rollen schreibt, ruft die Nachziehung.** Das ist
 * dieselbe Bauart wie `HostnameSourceTest` und `TimeDisplayTest`, und sie fängt
 * genau den Fall, für den es diesen Wächter gibt: einen *neuen* Weg, der den
 * Bestand ändert und die Datei vergisst.
 *
 * **Der Bruch dazu** (`tests/waechter-brechen.sh`): die Nachziehung aus
 * `Databases::grant()` nehmen.
 */
final class PgHbaFollowTest extends TestCase
{
    use ReadsMethodSource;

    /**
     * Woran man erkennt, dass eine Methode die Zuordnung schreibt.
     *
     * `$user->databases()->attach(…)`, `->detach(…)`, `->sync(…)`,
     * `->syncWithoutDetaching(…)` — die vier Wege, auf denen sich ändert,
     * welche Datenbanken zu einer Rolle gehören. Und damit die Zeilen, die im
     * Block stehen müssten.
     */
    private const WRITES_THE_LINK = '/databases\(\)\s*->\s*(attach|detach|sync|syncWithoutDetaching)\s*\(/';

    /** Woran man erkennt, dass sie den Block nachzieht. */
    private const FOLLOWS = '/->follow\s*\(/';

    /**
     * Stellen, die die Zuordnung schreiben und trotzdem nicht nachziehen — mit Grund.
     *
     * **Der Grund steht im Wert und nicht in einem Kommentar daneben**, wie in
     * {@see RemovalPathTest} und {@see EngineReachTest}: Eine Liste ohne
     * Begründung je Eintrag wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    private const WITHOUT_FOLLOW = [
        'App\Support\Databases\Databases::createUser' => 'Ein Zugang, der gerade erst entsteht, hat noch '
            .'kein Netz — seine Zeilen im Block sind vorher leer und nachher leer. Das Netz kommt später '
            .'über RemoteAccess::add(), und die zieht selbst nach.',
    ];

    /**
     * Jede Stelle, die die Zuordnung schreibt, zieht den Block nach.
     */
    public function test_every_place_that_links_a_database_follows_up(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->methodsOf(Databases::class) as $method) {
            $source = $this->methodSource(Databases::class, $method);

            if ($source === null || preg_match(self::WRITES_THE_LINK, $source) !== 1) {
                continue;
            }

            $checked++;
            $name = Databases::class.'::'.$method;

            if (isset(self::WITHOUT_FOLLOW[$name])) {
                continue;
            }

            if (preg_match(self::FOLLOWS, $source) !== 1) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, sprintf(
            'Diese Stellen ändern, welche Datenbanken zu einer Rolle gehören, und ziehen den '
            ."verwalteten Block in pg_hba.conf nicht nach:\n  %s\n\n"
            .'Eine Rolle mit einem Netz braucht je Datenbank eine eigene Zeile — die Zeile nennt die '
            .'Datenbank und nicht `all` (docs/38 §14.1). Fehlt sie, steht im Panel „erreichbar von …" '
            .'und die Anwendung kommt nicht herein. Soll die Stelle wirklich nicht nachziehen, gehört '
            .'sie mit ihrem Grund in PgHbaFollowTest::WITHOUT_FOLLOW.',
            implode("\n  ", $missing),
        ));

        // **Die Untergrenze zählt dort, wo die Regel stehen darf** — die
        // Stellen, die die Zuordnung schreiben. Findet der Ausdruck keine,
        // wäre dieser Test grün, ohne etwas gemessen zu haben.
        $this->assertGreaterThanOrEqual(
            2,
            $checked,
            'Es wurde kaum eine Stelle gefunden, die die Zuordnung schreibt — vermutlich zeigt der '
            .'Ausdruck ins Leere, und dieser Test misst nichts mehr.',
        );
    }

    /**
     * Und die beiden Wege des Lebenslaufs ziehen ebenfalls nach.
     *
     * Sie schreiben die Zuordnung nicht — sie entfernen eine Datenbank
     * beziehungsweise sperren einen Zugang, und beides ändert den Sollzustand
     * genauso. Der Ausdruck oben findet sie deshalb nicht; hier stehen sie
     * namentlich.
     *
     * @return list<array{0: string}>
     */
    public static function lifecycleMethods(): array
    {
        return [['removed'], ['locked']];
    }

    /**
     * @dataProvider lifecycleMethods
     */
    public function test_the_lifecycle_follows_up(string $method): void
    {
        $source = $this->methodSource(PgLifecycle::class, $method);

        $this->assertNotNull($source, sprintf('PgLifecycle::%s() gibt es nicht mehr.', $method));

        $this->assertMatchesRegularExpression(
            self::FOLLOWS,
            (string) $source,
            sprintf(
                'PgLifecycle::%s() ändert den Sollzustand des Blocks und zieht ihn nicht nach. '
                .'Was liegenbleibt, ist eine Zeile für eine Datenbank oder einen Zugang, den es so '
                .'nicht mehr gibt — für PostgreSQL kein Fehler (docs/38 §2.2a, M22), und deshalb '
                .'meldet es sonst niemand.',
                $method,
            ),
        );
    }

    /**
     * Ein Fehlschlag der Nachziehung wirft den Rückbau nicht um.
     *
     * **Und das ist kein stilles Schlucken.** Der Rückbau *ist* gelaufen: Die
     * Datenbank ist weg, die Rollen sind weg. Ihn nachträglich als gescheitert
     * zu melden, weil eine Aufräumarbeit danach nicht ging, wäre eine
     * Behauptung über das System, die nicht stimmt.
     *
     * Die Bedingung dafür, dass das `catch` dort stehen darf, ist ebenfalls
     * Gegenstand dieses Tests: Der Fehler geht an `report()`, und was
     * liegenbleibt, meldet `srvpanel db` als verwaist. **Ein `catch` ohne
     * beides wäre der Fehler, den dieses Projekt am teuersten bezahlt hat.**
     */
    public function test_a_failed_follow_up_is_reported_and_not_swallowed(): void
    {
        $source = (string) $this->methodSource(PgLifecycle::class, 'follow');

        $this->assertStringContainsString(
            'catch (AgentException',
            $source,
            'Die Nachziehung im Lebenslauf fängt den Fehlschlag nicht ab — ein gescheiterter Abgleich '
            .'meldet damit einen gelungenen Rückbau als gescheitert.',
        );

        $this->assertStringContainsString(
            'report(',
            $source,
            'Der Fehlschlag wird verschluckt. Ein `catch` ohne Meldung ist genau die Bauart, für die '
            .'es in diesem Projekt die meisten Narben gibt.',
        );
    }

    /**
     * Die Nachziehung fasst nur PostgreSQL an.
     *
     * Ein `pg.remote.access` beim Verbinden einer MariaDB-Datenbank wäre ein
     * Gang zum Datenbankserver für nichts — auf einem Server, der PostgreSQL
     * vielleicht gar nicht hat. Die Verzweigung steht in
     * {@see RemoteAccess::follow()} und nicht bei den Aufrufern: Sonst stünde
     * dieselbe Frage an vier Stellen, und die vierte veraltet.
     */
    public function test_the_follow_up_leaves_mariadb_alone(): void
    {
        $source = (string) $this->methodSource(RemoteAccess::class, 'follow');

        $this->assertStringContainsString(
            'DatabaseEngine::Postgres',
            $source,
            'Die Nachziehung fragt nicht nach dem System und liefe damit auch für MariaDB.',
        );

        $this->assertStringContainsString(
            'DbUserNetwork::query()->exists()',
            $source,
            'Ohne diese Frage geht jede Rechteänderung zum Agenten, auch auf Servern, auf denen nie '
            .'jemand ein Netz eingetragen hat.',
        );
    }

    /**
     * Kein Eintrag in der Ausnahmeliste zeigt ins Leere.
     *
     * Zwei Wege, auf denen ein Eintrag tot wird: Die Methode verschwindet, oder
     * sie zieht inzwischen doch nach — dann deckt seine Begründung die nächste
     * Auslassung mit.
     */
    public function test_the_list_of_exceptions_has_no_dead_entries(): void
    {
        foreach (array_keys(self::WITHOUT_FOLLOW) as $entry) {
            [$class, $method] = explode('::', $entry);

            $source = $this->methodSource($class, $method);

            $this->assertNotNull($source, sprintf('%s steht in der Ausnahmeliste und gibt es nicht.', $entry));

            $this->assertSame(
                1,
                preg_match(self::WRITES_THE_LINK, (string) $source),
                sprintf('%s schreibt die Zuordnung gar nicht mehr — der Eintrag gehört heraus.', $entry),
            );

            $this->assertSame(
                0,
                preg_match(self::FOLLOWS, (string) $source),
                sprintf(
                    '%s steht in der Ausnahmeliste und zieht inzwischen nach. Der Eintrag gehört '
                    .'heraus, sonst deckt seine Begründung die nächste Auslassung mit.',
                    $entry,
                ),
            );
        }
    }

    /**
     * Die öffentlichen Methoden einer Klasse, die sie selbst erklärt.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private function methodsOf(string $class): array
    {
        $names = [];

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }
}
