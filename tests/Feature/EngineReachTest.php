<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;

/**
 * Zu jeder `db.*`-Operation gibt es ein `pg.*`-Gegenstück — oder einen Grund.
 *
 * ## Warum es diesen Wächter gibt
 *
 * P5b hat die Datenbankfläche verdoppelt: `Databases::create()` verzweigt an
 * genau einer Stelle auf `engine` und schickt `db.*` oder `pg.*` (`docs/38
 * §8`). Was dabei still schiefgehen kann, ist **nicht**, dass eine Operation
 * fehlt — dann bricht der Vorgang mit „Unbekannte Operation", und das sieht
 * man. Es ist, dass eine Fläche für ein System gebaut wird und für das andere
 * nicht, und niemand danach fragt: Ein Kunde mit MariaDB kann seine Sicherung
 * löschen, einer mit PostgreSQL nicht, und auffallen würde das dem, der es
 * versucht.
 *
 * Das ist dasselbe Muster, für das es `RemovalPathTest` gibt — dort „was sich
 * anlegen lässt, lässt sich auch entfernen", hier „was das eine System kann,
 * kann das andere auch". Beide Male ist die Lücke eine **Abwesenheit**, und
 * Abwesenheiten meldet nichts von selbst.
 *
 * ## Die Richtung, und warum nur diese eine
 *
 * Geprüft wird `db.*` → `pg.*` und nicht umgekehrt, so wie `docs/38 §18` es
 * festlegt. Der Rückweg wäre falsch: `pg.server.install` hat kein
 * `db.server.install` und soll auch keines bekommen — MariaDB ist eine
 * Abhängigkeit des Panels und immer da, PostgreSQL nicht (`docs/38 §7`). Eine
 * verwaiste `pg.*`-Operation fängt ausserdem schon
 * {@see AgentOperationReachTest}: Sie verlangt zu jeder Operation einen
 * Aufrufer, und ohne den ist sie „Code, der als root läuft und zu dem kein Weg
 * führt".
 *
 * ## Die beiden Systeme nennen dasselbe verschieden
 *
 * In MariaDB heisst es **Benutzer**, in PostgreSQL **Rolle** (`docs/38 §4`).
 * `pg.user.create` wäre deshalb nicht das gesuchte Gegenstück, sondern ein
 * falscher Name — die Zuordnung steht in {@see self::NOUNS} und wird
 * mitgeprüft, damit sie nicht ins Leere zeigt.
 *
 * **Der Bruch dazu** (`tests/waechter-brechen.sh`): eine `pg.*`-Operation aus
 * der Registratur nehmen.
 *
 * **Drei weitere Brüche stehen nicht im Skript, und das geht auch nicht.** Sie
 * ändern entweder diese Datei — `wiederherstellen()` fasst `tests/` nicht an —
 * oder legen eine neue Datei unter `agent/src/Ops/` an, die ein
 * `git checkout` nicht wieder wegnimmt. Sie sind am 11. August 2026 von Hand
 * gefahren, jeder rot, jeder danach wieder grün:
 *
 *     # das Gegenstück bauen, den Ausnahmeeintrag stehenlassen
 *     #   → test_the_list_of_exceptions_has_no_dead_entries
 *     #     „db.dump.remove steht in der Ausnahmeliste als „hat kein
 *     #      Gegenstück" — pg.dump.remove gibt es aber."
 *
 *     # die Begriffszuordnung entfernen
 *     sed -i "/'user' => 'role',/d" tests/Feature/EngineReachTest.php
 *     #   → vier Lücken auf einmal: db.user.create → pg.user.create fehlt, …
 *
 *     # einen Eintrag für eine Operation, die es nicht gibt
 *     #   → „db.gibtsnicht steht in der Ausnahmeliste, aber die Operation
 *     #      gibt es nicht."
 */
final class EngineReachTest extends TestCase
{
    /**
     * Wo dieselbe Sache in beiden Systemen anders heisst.
     *
     * `db.user.create` sucht damit `pg.role.create` und nicht `pg.user.create`.
     * Das ist keine Bequemlichkeit: Eine Operation `pg.user.*` wäre in
     * PostgreSQL schlicht der falsche Begriff, und `docs/38 §4` hält fest,
     * warum das Datenmodell die Unterscheidung mitmacht.
     *
     * @var array<string, string>
     */
    private const NOUNS = [
        'user' => 'role',
    ];

    /**
     * `db.*`-Operationen ohne Gegenstück — mit Grund.
     *
     * **Der Grund steht im Wert und nicht in einem Kommentar daneben**, wie in
     * {@see RemovalPathTest} und {@see AgentOperationReachTest}: Eine Liste
     * ohne Begründung je Eintrag wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    private const WITHOUT_COUNTERPART = [
        'db.dump.remove' => 'Entfernt eine Datei aus der Ablage, und eine Datei hat kein Datenbanksystem. '
            .'Sie gilt für beide Systeme; ein pg.dump.remove wäre Zeile für Zeile dieselbe Operation '
            .'(docs/38 §13). Der Plan führt es in seiner Tabelle in §10 — dort steht die Absicht, hier '
            .'steht, was daraus wurde.',

        'db.user.password' => 'pg.role.create setzt das Passwort mit, und das ist kein Behelf: CREATE ROLE '
            .'kennt kein IF NOT EXISTS, die Operation muss an einer vorhandenen Rolle ohnehin ALTER ROLE '
            .'schreiben — der gewünschte Zustand ist beide Male derselbe. Eine zweite Operation dafür wäre '
            .'eine zweite Fassung derselben Regel, und die zweite ist die, die veraltet '
            .'(App\Support\Databases\Engines\PostgresDriver::setPassword).',

        'db.isolation.probe' => 'Die Selbstprobe misst das Abnahmekriterium von P5 — „ein Benutzer sieht '
            .'keine fremde Datenbank". Für PostgreSQL ist dieser Satz nicht erfüllbar und das Kriterium '
            .'deshalb neu gefasst (docs/38 §3): Der Verbindungsaufbau verrät die Existenz, und der Entzug '
            .'von pg_database nähme dem Kunden pg_dump. Was an seine Stelle tritt, sind elf REVOKE in '
            .'Pg\Shielding und die Punkte 3 und 3b des Abnahmelaufs. Eine Operation, die eine Frage '
            .'beantwortet, die sich so nicht stellt, wäre Angriffsfläche ohne Nutzen.',
    ];

    /**
     * Wie viele `db.*`-Operationen es mindestens gibt, damit dieser Lauf zählt.
     *
     * **Die Zahl steht dort, wo die Regel stehen *darf*, und nicht, wo sie
     * stehen soll** — genau die Falle, in die dieses Vorgehen dreimal gelaufen
     * ist (`docs/38 §18`, `CLAUDE.md`). Gezählt werden die `db.*`-Operationen,
     * also die Fläche, über die dieser Test überhaupt etwas sagt; nicht die
     * gefundenen Paare und schon gar nicht die Ausnahmen. Sonst meldete er Rot,
     * sobald jemand aufräumt.
     *
     * Am 11. August 2026 sind es fünfzehn. Die Untergrenze lässt Platz zum
     * Aufräumen und fängt trotzdem den Fall, für den sie da ist: eine
     * Registratur, die nichts mehr hergibt, und einen Wächter, der darüber
     * schweigend grün wird.
     */
    private const AT_LEAST = 10;

    /**
     * Und so viele Paare müssen tatsächlich aufgehen.
     *
     * **Ohne diese Zahl ist der Test auch dann grün, wenn er nichts geprüft
     * hat.** Trüge {@see self::WITHOUT_COUNTERPART} irgendwann jede Operation,
     * wäre jede Schleife unten erfüllt und keine einzige Zuordnung gemessen —
     * eine Ausnahmeliste, die alles enthält, ist keine Ausnahmeliste mehr.
     */
    private const PAIRS_AT_LEAST = 8;

    private function registry(): Registry
    {
        return new Registry(new Config);
    }

    /**
     * Die Operationen eines Systems, ohne ihr Präfix.
     *
     * @return list<string>
     */
    private function operations(string $prefix): array
    {
        $found = [];

        foreach ($this->registry()->names() as $name) {
            if (str_starts_with($name, $prefix.'.')) {
                $found[] = substr($name, strlen($prefix) + 1);
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Der Name, den das Gegenstück tragen müsste.
     *
     * `user.create` → `role.create`, alles andere unverändert. Übersetzt wird
     * **nur das erste Wort**: Es benennt die Sache, an der gearbeitet wird; das
     * zweite ist das Verb und heisst in beiden Systemen gleich.
     */
    private function counterpart(string $operation): string
    {
        $parts = explode('.', $operation);
        $parts[0] = self::NOUNS[$parts[0]] ?? $parts[0];

        return implode('.', $parts);
    }

    /**
     * Zu jeder `db.*`-Operation gibt es `pg.*`, oder ein begründeter Eintrag
     * sagt warum nicht.
     */
    public function test_every_mariadb_operation_has_a_postgresql_counterpart(): void
    {
        $postgres = $this->operations('pg');
        $missing = [];
        $pairs = 0;

        foreach ($this->operations('db') as $operation) {
            if (isset(self::WITHOUT_COUNTERPART['db.'.$operation])) {
                continue;
            }

            if (in_array($this->counterpart($operation), $postgres, true)) {
                $pairs++;

                continue;
            }

            $missing[] = sprintf('db.%s → pg.%s fehlt', $operation, $this->counterpart($operation));
        }

        $this->assertSame(
            [],
            $missing,
            'Eine Fläche gibt es für MariaDB und für PostgreSQL nicht. Entweder fehlt die Operation, '
            ."oder sie soll fehlen — dann gehört sie mit ihrem Grund in EngineReachTest::WITHOUT_COUNTERPART:\n  "
            .implode("\n  ", $missing),
        );

        $this->assertGreaterThanOrEqual(
            self::PAIRS_AT_LEAST,
            $pairs,
            'Es sind kaum noch Paare aufgegangen. Entweder ist die Zuordnung kaputt, oder die '
            .'Ausnahmeliste hat die Fläche aufgefressen — in beiden Fällen prüft dieser Test nichts mehr.',
        );
    }

    /**
     * Die Fläche, über die dieser Test etwas sagt, ist noch da.
     *
     * **Der Wächter über dem Wächter.** Bricht die Registratur, ändert sich das
     * Namensschema oder fasst der Ausdruck oben ins Leere, dann läuft die
     * Schleife über nichts und meldet Grün — für eine Fläche, die sie nie
     * angesehen hat. Dasselbe Muster, für das `docs/39 §12a` steht:
     *
     * > Ein Werkzeug, das Entwarnung gibt, muss die ganze Fläche sehen können,
     * > über die es Entwarnung gibt.
     */
    public function test_there_are_enough_operations_to_measure(): void
    {
        $this->assertGreaterThanOrEqual(
            self::AT_LEAST,
            count($this->operations('db')),
            'Es sind kaum noch db.*-Operationen zu finden. Vermutlich zeigt der Ausdruck ins Leere — '
            .'dann ist dieser Test grün, ohne etwas gemessen zu haben.',
        );
    }

    /**
     * Kein Eintrag in der Ausnahmeliste zeigt ins Leere.
     *
     * **Zwei Wege, auf denen ein Eintrag tot wird, und beide sind schon
     * vorgekommen.** Die Operation wird umbenannt oder entfernt — dann steht
     * eine Begründung für nichts da. Oder das Gegenstück wird *gebaut* — dann
     * behauptet der Eintrag weiterhin, es gebe keines, und die nächste
     * Auslassung derselben Art fiele unter ihn.
     *
     * Der zweite ist der gefährlichere und der wahrscheinlichere: Genau so
     * verschwinden Ausnahmen aus dem Blick, nachdem sie erledigt sind.
     */
    public function test_the_list_of_exceptions_has_no_dead_entries(): void
    {
        $mariadb = $this->operations('db');
        $postgres = $this->operations('pg');

        foreach (array_keys(self::WITHOUT_COUNTERPART) as $name) {
            $operation = substr($name, 3);

            $this->assertContains(
                $operation,
                $mariadb,
                sprintf('%s steht in der Ausnahmeliste, aber die Operation gibt es nicht.', $name),
            );

            $this->assertNotContains(
                $this->counterpart($operation),
                $postgres,
                sprintf(
                    '%s steht in der Ausnahmeliste als „hat kein Gegenstück" — pg.%s gibt es aber. '
                    .'Der Eintrag gehört heraus, sonst deckt seine Begründung die nächste Auslassung mit.',
                    $name,
                    $this->counterpart($operation),
                ),
            );
        }
    }

    /**
     * Jede Begründung ist eine, und keine Notiz.
     *
     * Wortgleich die Regel aus {@see RemovalPathTest}: Der Grund steht im Wert.
     * Ein leerer oder einsilbiger Eintrag ist ein Haken, kein Argument — und
     * eine Liste aus Haken wächst, bis sie alles enthält.
     */
    public function test_every_exception_carries_its_reason(): void
    {
        foreach (self::WITHOUT_COUNTERPART as $name => $reason) {
            $this->assertGreaterThan(
                80,
                strlen($reason),
                sprintf('Die Begründung zu %s ist zu kurz, um eine zu sein.', $name),
            );
        }
    }

    /**
     * Und die Zuordnung der Begriffe zeigt auf etwas.
     *
     * `user` → `role` nützt nichts, wenn es keine `db.user.*`-Operation mehr
     * gibt oder keine `pg.role.*`. Dann ist die Zuordnung eine Zeile, die
     * niemand mehr liest — und die nächste, die dazukommt, wird nach ihrem
     * Vorbild geschrieben.
     */
    public function test_the_noun_mapping_points_at_something(): void
    {
        $mariadb = $this->operations('db');
        $postgres = $this->operations('pg');

        foreach (self::NOUNS as $here => $there) {
            $this->assertNotEmpty(
                array_filter($mariadb, static fn (string $o): bool => str_starts_with($o, $here.'.')),
                sprintf('Es gibt keine db.%s.*-Operation mehr — die Zuordnung %s → %s ist tot.', $here, $here, $there),
            );

            $this->assertNotEmpty(
                array_filter($postgres, static fn (string $o): bool => str_starts_with($o, $there.'.')),
                sprintf('Es gibt keine pg.%s.*-Operation mehr — die Zuordnung %s → %s ist tot.', $there, $here, $there),
            );
        }
    }
}
