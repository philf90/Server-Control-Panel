<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DbUserStatus;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\DbUserNetwork;
use App\Models\Subscription;
use App\Support\Databases\RemoteAccess;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Pg\Hba;
use SrvPanel\Agent\Pg\Names as PgNames;
use Tests\TestCase;

/**
 * Jede Zeile im verwalteten Block zeigt auf eine Rolle, die es gibt — **und
 * jede Rolle mit Netzen hat ihre Zeilen.**
 *
 * ## Warum beide Richtungen
 *
 * Eine Zeile in `pg_hba.conf` für eine Rolle, die es nicht mehr gibt, ist für
 * PostgreSQL **kein Fehler** (`docs/38 §2.2a`, M22): Sie bleibt liegen,
 * `pg_hba_file_rules` schweigt, und der Cluster startet damit anstandslos.
 * `pg_hba.conf` ist damit das Vierte, was P5b auf der Platte hinterlässt —
 * neben Datenbanken, Rollen und Sicherungen — und das Einzige davon, was gar
 * nichts von sich meldet.
 *
 * Die Gegenrichtung ist die stillere: Ein Kunde trägt sein Netz ein, die
 * Tabelle bekommt ihre Zeile, und der Block wird nicht nachgezogen. Dann steht
 * im Panel „erreichbar von 203.0.113.5" und die Anwendung kommt nicht herein.
 *
 * ## Und der Wächter kennt **beide** verwalteten Bereiche
 *
 * In dieser Datei schreiben zwei Anliegen: die Zeile für das Zurückspielen
 * ({@see Hba::MARK}, `docs/38 §13.4`) und der Block für den Fernzugriff
 * ({@see Hba::BEGIN}, §14). Ein Wächter, der nur den zweiten liest, gäbe
 * Entwarnung über eine Fläche, die er nicht angesehen hat — wörtlich der Fund
 * aus `docs/39 §12a`, eine Stufe später:
 *
 * > **Ein Werkzeug, das Entwarnung gibt, muss die ganze Fläche sehen können,
 * > über die es Entwarnung gibt.**
 *
 * **Der Bruch dazu** (`tests/waechter-brechen.sh`): eine Rolle entfernen und
 * ihre Zeilen stehenlassen.
 */
final class PgHbaReachTest extends TestCase
{
    use RefreshDatabase;

    private function remote(): RemoteAccess
    {
        return app(RemoteAccess::class);
    }

    /**
     * Das Präfix, unter dem dieses Abonnement seine PostgreSQL-Namen führt.
     *
     * **Gewürfelt und nichtssagend, nicht `p1001`** (`docs/38 §4`). Es steht
     * hier fest statt aus {@see PgNames::newPrefix()}, damit ein Fehlschlag
     * zweimal dieselbe Zeile zeigt.
     */
    private const PREFIX = 'x7f3a91c2b40e15d6';

    /**
     * Ein Abonnement mit einer PostgreSQL-Datenbank, einem Zugang und einem Netz.
     *
     * **`system_user` wird gesetzt und nicht der Factory überlassen.**
     * `SubscriptionFactory` füllt die Spalte nicht, und `forSubscription()`
     * baut den Namen daraus — ohne sie kommt `Names::database('', 'shop')`
     * heraus und der Agent weist mit „user ist leer oder zu lang" ab. Die acht
     * Fehlschläge dieses Tests am 11. August 2026 hatten alle diese eine
     * Ursache.
     *
     * **Und die Namen kommen aus {@see PgNames} statt aus der Factory.** Deren
     * `forSubscription()` bildet die Form von MariaDB (`p1001_shop`); eine
     * PostgreSQL-Datenbank heisst `x7f3a…_shop`, und `Names::existing()` im
     * Agenten weist alles andere ab. Der Test prüft zwar nur, dass Zeile und
     * Bestand zueinanderpassen — aber mit Namen, die es so nie gäbe, prüfte er
     * das an einem Fall, den es nicht gibt.
     *
     * @return array{user: DbUser, database: Database, subscription: Subscription}
     */
    private function scenario(string $cidr = '203.0.113.5/32'): array
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1001']);

        /** @var Database $database */
        $database = Database::factory()->postgres()->forSubscription($subscription, 'shop')->create([
            'name' => PgNames::database(self::PREFIX, 'shop'),
        ]);

        /** @var DbUser $user */
        $user = DbUser::factory()->postgres()->forSubscription($subscription, 'web')->create([
            'name' => PgNames::role(self::PREFIX, 'web'),
        ]);

        $user->databases()->attach($database);
        DbUserNetwork::factory()->create(['db_user_id' => $user->id, 'cidr' => $cidr]);

        return ['user' => $user, 'database' => $database, 'subscription' => $subscription];
    }

    /**
     * Aus einem Netz wird eine Zeile — mit der Datenbank darin und nicht `all`.
     *
     * **`all` wäre die bequeme Fassung und die falsche** (`docs/38 §14.1`,
     * M23). Die Zeile ist die zweite Wand hinter dem `REVOKE CONNECT` aus §10:
     * Selbst wenn jemand das Recht wieder aufmachte, käme die Rolle über
     * `pg_hba.conf` in keine andere Datenbank. Gemessen — dieselbe Rolle in die
     * Datenbank `postgres`: `no pg_hba.conf entry for host "127.0.0.1", user
     * "…", database "postgres"`.
     */
    public function test_a_network_becomes_a_line_naming_its_database(): void
    {
        ['user' => $user, 'database' => $database] = $this->scenario();

        $rules = $this->remote()->rules();

        $this->assertCount(1, $rules, 'Ein Netz an einer Datenbank ist genau eine Zeile.');
        $this->assertSame($database->name, $rules[0]['database']);
        $this->assertSame($user->name, $rules[0]['role']);

        $this->assertStringNotContainsString(
            ' all ',
            Hba::rule($rules[0]['database'], $rules[0]['role'], $rules[0]['cidr']),
            'Die Zeile nennt `all` statt der Datenbank — damit erreicht die Rolle jede Datenbank, '
            .'für die sie CONNECT hat oder wieder bekommt.',
        );
    }

    /**
     * Zwei Datenbanken × ein Netz sind zwei Zeilen.
     *
     * Der Preis, den `docs/38 §14.1` ausdrücklich nennt: eine Zeile je
     * Datenbank × Rolle × Netz. Wer hier eine erwartet, hat die Zeile mit `all`
     * gebaut.
     */
    public function test_every_database_of_a_role_gets_its_own_line(): void
    {
        ['user' => $user, 'subscription' => $subscription] = $this->scenario();

        /** @var Database $second */
        $second = Database::factory()->postgres()
            ->forSubscription($subscription, 'blog')->create([
                'name' => PgNames::database(self::PREFIX, 'blog'),
            ]);

        $user->databases()->attach($second);

        $this->assertCount(2, $this->remote()->rules());
    }

    /**
     * **Die Gegenrichtung: Was im Block steht und im Bestand fehlt, wird gemeldet.**
     *
     * Das ist der Rückweg, den PostgreSQL selbst nicht geht.
     */
    public function test_a_line_without_a_role_in_the_inventory_is_reported(): void
    {
        $this->scenario();

        $verwaist = Hba::rule('x0000000000000000_alt', 'x0000000000000000_web', '198.51.100.0/24');
        $vorhanden = $this->remote()->rules()[0];
        $echt = Hba::rule($vorhanden['database'], $vorhanden['role'], $vorhanden['cidr']);

        $orphans = $this->remote()->orphans([$echt, $verwaist]);

        $this->assertSame([$verwaist], $orphans, 'Die verwaiste Zeile wird nicht gemeldet.');
    }

    /**
     * Ein entfernter Zugang nimmt seine Zeilen mit.
     *
     * **Über die Fremdschlüsselbeziehung und nicht über eine Aufräumroutine.**
     * `db_user_networks` hängt mit `cascadeOnDelete` am Zugang; eine zweite
     * Stelle, die dasselbe von Hand täte, wäre die, die beim nächsten Rückbau
     * vergessen wird.
     */
    public function test_removing_a_user_removes_its_lines(): void
    {
        ['user' => $user] = $this->scenario();

        app(Tenancy::class)->withoutRestriction(static fn (): mixed => $user->delete());

        $this->assertSame([], $this->remote()->rules(), 'Der entfernte Zugang hat noch Zeilen.');
    }

    /**
     * Ein gesperrter Zugang bekommt keine Zeile.
     *
     * `pg.role.lock` setzt `NOLOGIN` und hält ihn schon draussen; die Zeile
     * stehenzulassen wäre eine zweite Fassung derselben Sperre — und die zweite
     * ist die, die veraltet. Beim Entsperren kommt sie mit dem nächsten
     * Abgleich zurück.
     */
    public function test_a_locked_user_gets_no_line(): void
    {
        ['user' => $user] = $this->scenario();

        $this->assertCount(1, $this->remote()->rules());

        app(Tenancy::class)->withoutRestriction(static fn (): mixed => $user->update([
            'status' => DbUserStatus::Locked,
        ]));

        $this->assertSame([], $this->remote()->rules(), 'Ein gesperrter Zugang steht noch im Block.');
    }

    /**
     * Ein MariaDB-Zugang taucht im Block nicht auf.
     *
     * Seine Herkunft steht in `db_users.host` und geht in einen Benutzernamen,
     * nicht in `pg_hba.conf`. Eine Zeile dafür wäre eine Erlaubnis für eine
     * Rolle, die es in PostgreSQL gar nicht gibt — und die fiele niemandem auf,
     * weil PostgreSQL sie klaglos annimmt (M22).
     */
    public function test_a_mariadb_user_never_reaches_the_block(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1002']);

        /** @var DbUser $user */
        $user = DbUser::factory()->forSubscription($subscription, 'web')->create(['host' => '203.0.113.9']);

        /** @var Database $database */
        $database = Database::factory()->forSubscription($subscription, 'shop')->create();

        $user->databases()->attach($database);

        $this->assertSame([], $this->remote()->rules());
    }

    /**
     * **Und der Wächter kennt den anderen verwalteten Bereich.**
     *
     * Die Zeile für das Zurückspielen ist der zweite Schreiber in derselben
     * Datei. Sie steht ganz oben und muss dort bleiben — darunter kommt sie nie
     * zum Zug —, und der Block darf sie nicht verlieren. Ohne diese Prüfung
     * läse dieser Wächter genau die Hälfte der Datei und gäbe Entwarnung über
     * die andere.
     */
    public function test_the_guard_knows_the_line_for_restoring_a_dump(): void
    {
        $bestand = "local   all             all                                     peer\n";
        $mitZeile = Hba::prepend($bestand);
        $mitBlock = Hba::render($mitZeile, [Hba::rule('x1_a', 'x1_b', '203.0.113.5/32')]);

        $this->assertStringContainsString(
            Hba::RULE,
            $mitBlock,
            'Der Block hat die Zeile für das Zurückspielen verloren.',
        );

        $this->assertLessThan(
            strpos($mitBlock, 'peer'),
            strpos($mitBlock, Hba::RULE),
            'Die Zeile steht nicht mehr über der peer-Zeile.',
        );

        $this->assertNotContains(
            Hba::RULE,
            Hba::managed($mitBlock),
            'Die Zeile für das Zurückspielen wird als Regel des Blocks gelesen — dann nähme sie der '
            .'nächste Abgleich als verwaist mit.',
        );
    }
}
