<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Pg\Names;

/**
 * Der Angriffsdurchgang gegen die Namensregel für PostgreSQL.
 *
 * Dasselbe wie {@see DbNameTest} für MariaDB, mit **einer Behauptung, die es
 * dort nicht gibt und die hier das Abnahmekriterium trägt**: Der Name verrät
 * nicht, zu wem er gehört (`docs/38 §3` und §4).
 *
 * PostgreSQL zeigt jedem Kunden die Namen aller Datenbanken des Clusters —
 * gemessen, nicht angenommen (`docs/38 §2.2`). `p1002_shop` verriete damit, dass
 * es ein Abonnement 1002 mit einem Shop gibt. Die Abschottung ist deshalb keine
 * Rechtevergabe, sondern der Name selbst, und
 * {@see self::test_a_prefix_cannot_be_derived_from_anything} ist die Zeile, die
 * das festhält.
 */
final class PgNameTest extends TestCase
{
    /**
     * Ein Präfix lässt sich aus nichts ableiten — auch nicht versehentlich.
     *
     * **Das ist der Wächter über das Abnahmekriterium, und er prüft die Form
     * statt des Ergebnisses.** Ein Test, der nur nachsieht, dass in einem
     * erzeugten Präfix keine Abonnementnummer *vorkommt*, wäre grün, sobald
     * jemand die Nummer durch eine Prüfsumme schickt — die Auskunft bliebe, sie
     * wäre nur unleserlich. Eine Methode ohne Parameter kann dagegen aus keinem
     * Wert des Abonnements etwas ableiten: Es liegt keiner vor.
     *
     * Der Bruch dazu gibt {@see Names::newPrefix()} einen Parameter.
     */
    public function test_a_prefix_cannot_be_derived_from_anything(): void
    {
        $this->assertSame(
            0,
            (new ReflectionMethod(Names::class, 'newPrefix'))->getNumberOfParameters(),
            'newPrefix() nimmt einen Wert entgegen — dann kann das Präfix von ihm abhängen, '
            .'und genau das darf es nicht (docs/38 §4).',
        );
    }

    public function test_a_new_prefix_has_the_shape_and_is_not_reused(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $prefix = Names::newPrefix();

            $this->assertMatchesRegularExpression('/^x[0-9a-f]{16}$/D', $prefix);
            $this->assertArrayNotHasKey($prefix, $seen, 'Zwei Läufe haben dasselbe Präfix erzeugt.');

            $seen[$prefix] = true;
        }
    }

    /**
     * Ein zusammengesetzter Name bleibt unter der Grenze von PostgreSQL.
     *
     * **Die Grenze weist nicht ab, sie schneidet ab** — auf PostgreSQL 16.13
     * gemessen. Zwei Namen, die sich erst nach Zeichen 63 unterscheiden, wären
     * danach derselbe, und der zweite `CREATE DATABASE` träfe die Datenbank des
     * ersten.
     */
    public function test_a_composed_name_stays_under_the_limit(): void
    {
        $name = Names::database(Names::newPrefix(), str_repeat('a', 16));

        $this->assertLessThanOrEqual(Names::MAX_IDENTIFIER, strlen($name));
    }

    /**
     * Das Präfix eines Abonnements ist nicht das eines anderen.
     */
    public function test_a_foreign_prefix_is_not_a_prefix(): void
    {
        $mine = Names::newPrefix();
        $theirs = Names::newPrefix();

        $this->assertTrue(Names::belongsTo($mine.'_shop', $mine));
        $this->assertFalse(Names::belongsTo($theirs.'_shop', $mine));
    }

    /**
     * Was dieses Panel nicht vergeben hat, trägt seine Form auch nicht.
     *
     * `p1001_shop` steht bewusst in der Liste: Es ist ein gültiger Name **in
     * P5** und darf in P5b nicht als eigener durchgehen. Zwei Systeme, zwei
     * Formen — und die Operation, die einen Namen entgegennimmt, muss die
     * falsche Herkunft erkennen.
     *
     * @return list<array{string}>
     */
    public static function foreignNames(): array
    {
        return [
            ['postgres'],
            ['template0'],
            ['template1'],
            ['p1001_shop'],
            ['x0add22c3e9af1c32'],            // Präfix ohne Zusatz
            ['x0add22c3e9af1c3_shop'],        // eine Ziffer zu kurz
            ['X0ADD22C3E9AF1C32_shop'],       // gross geschrieben
            ['x0add22c3e9af1c32_Shop'],
            ['x0add22c3e9af1c32_shop; DROP DATABASE postgres'],
            ['x0add22c3e9af1c32_shop"'],
            ['x0add22c3e9af1c32_../etc/passwd'],
            [''],
        ];
    }

    #[DataProvider('foreignNames')]
    public function test_a_name_this_panel_did_not_issue_is_refused(string $name): void
    {
        $this->assertFalse(Names::isPanelName($name), sprintf('%s gilt als eigener Name.', $name));

        $this->expectException(AgentException::class);
        Names::existing($name);
    }

    /**
     * Die Namen, die ein Kunde nicht wählen darf.
     *
     * @return list<array{string}>
     */
    public static function refusedSuffixes(): array
    {
        return [
            ['Shop'],                 // Grossbuchstabe — unangeführt schriebe PostgreSQL ihn klein
            ['mein-shop'],            // Bindestrich zwänge zur Anführung
            ['1shop'],                // beginnt mit einer Ziffer
            ['shop.tabelle'],         // der Punkt trennt
            ['shop"'],
            ['shop; SELECT 1'],
            [str_repeat('a', 17)],
            [''],
            ['r3f9a20c1'],            // die Form der befristeten Rolle
        ];
    }

    #[DataProvider('refusedSuffixes')]
    public function test_a_refused_suffix_never_becomes_a_name(string $suffix): void
    {
        $this->expectException(AgentException::class);
        Names::suffix($suffix);
    }

    /**
     * Die befristete Rolle ist erkennbar — und ein Kundenzugang ist es nicht.
     *
     * **Das ist ein Fund aus P5, hier übernommen statt neu bezahlt.** Ohne die
     * Sperre in {@see Names::suffix()} wäre {@see Names::isEphemeral()} eine
     * Vermutung: Ein Kunde, der seinen Zugang `r3f9a20c1` nennt, verlöre ihn
     * nach einer Stunde, weil der Aufräumlauf ihn für den Rest eines
     * abgebrochenen Zurückspielens hält — ohne dass irgendetwas falsch
     * programmiert wäre.
     */
    public function test_an_ephemeral_role_is_told_apart_from_a_customers(): void
    {
        $prefix = Names::newPrefix();

        $this->assertTrue(Names::isEphemeral(Names::ephemeral($prefix)));
        $this->assertFalse(Names::isEphemeral(Names::role($prefix, 'web')));

        // Und sie trägt das Präfix ihres Abonnements — sonst fände der Rückbau
        // sie nicht, wenn ein abgebrochener Vorgang sie stehenlässt.
        $this->assertTrue(Names::belongsTo(Names::ephemeral($prefix), $prefix));
    }

    /**
     * Es gibt keinen Wirt — und das ist keine Auslassung.
     *
     * In MariaDB sind `'p1001_web'@'localhost'` und `'p1001_web'@'203.0.113.5'`
     * zwei Benutzer mit zwei Passwörtern; `Db\Names::host()` prüft ihn deshalb.
     * In PostgreSQL ist es **eine** Rolle mit einem Passwort, und von wo sie
     * kommen darf, steht in `pg_hba.conf` (`docs/38 §14.3`).
     *
     * Ein `host()` hier wäre eine Zusage, die das System nicht einlöst: Es sähe
     * aus, als hinge die Erreichbarkeit an der Rolle, und sie hängt an einer
     * Datei.
     *
     * **Gefragt wird über Reflection und nicht mit `method_exists()`.** Das
     * hätte PHPStan zu einer Konstante gefaltet (`function.impossibleType`) und
     * die CI rot gemacht — derselbe Fund wie bei der leeren Ausnahmeliste in
     * `DocLinkTest` einen Commit zuvor. Eine Behauptung über den Bestand einer
     * Klasse ist zur Übersetzungszeit entscheidbar; sie muss zur Laufzeit
     * gestellt werden, sonst ist sie keine Prüfung, sondern ein Ausdruck.
     */
    public function test_a_role_carries_no_host(): void
    {
        $this->assertFalse(
            (new ReflectionClass(Names::class))->hasMethod('host'),
            'Pg\Names hat ein host() bekommen — in PostgreSQL steht der Wirt in pg_hba.conf '
            .'und nicht an der Rolle (docs/38 §14.3).',
        );
    }
}
