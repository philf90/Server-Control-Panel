<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Der Angriffsdurchgang gegen die Namensregel für Datenbanken.
 *
 * Dieselbe Gewohnheit wie in {@see DomainNameTest} und {@see GuardTest}, nur
 * für den Wert, aus dem ein Schemaname, ein Benutzername und die
 * **Mandantengrenze** in `DROP DATABASE` wird. Die Liste unten ist die
 * Sammlung dessen, was schiefgehen kann, wenn ein Name ungeprüft weiterläuft:
 * fremde Schemata, Systemtabellen, Anführung, Pfadtrennung.
 *
 * **Die zwei Behauptungen, um die es eigentlich geht**, stehen in
 * `test_the_prefix_is_the_agents_own_rule` und
 * `test_a_foreign_prefix_is_not_a_prefix`: Das Präfix wird mit der Funktion des
 * Agenten selbst geprüft und nicht mit einem zweiten Ausdruck, und `p1001` ist
 * kein Präfix von `p10012_shop`.
 */
final class DbNameTest extends TestCase
{
    public function test_a_name_is_prefix_underscore_suffix(): void
    {
        $this->assertSame('p1001_shop', Names::database('p1001', 'shop'));
        $this->assertSame('p1001_web', Names::user('p1001', 'web'));
    }

    /**
     * **Das Präfix ist die Regel des Agenten und keine zweite Fassung davon.**
     *
     * Geprüft wird das an einer Eigenschaft, die nur
     * {@see SubscriptionProvision::systemUser()} hat: Sie nimmt vier bis neun
     * Ziffern. Wer hier `^p[0-9]+$` hinschriebe, käme mit drei Ziffern durch,
     * und der Agent legte danach einen Systembenutzer an, den es so nicht geben
     * darf — oder eben nicht, und dann stünde eine Datenbank ohne Abonnement da.
     */
    public function test_the_prefix_is_the_agents_own_rule(): void
    {
        $this->assertSame('p1000', Names::prefix('p1000'));
        $this->assertSame('p123456789', Names::prefix('p123456789'));

        foreach (['p999', 'p1234567890', 'root', 'www-data', 'srvpanel', 'P1001', 'p', ''] as $wrong) {
            try {
                Names::prefix($wrong);
                $this->fail(sprintf('%s ist als Präfix durchgegangen.', var_export($wrong, true)));
            } catch (AgentException $error) {
                $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
            }
        }
    }

    /**
     * **`p1001` ist kein Präfix von `p10012_shop`** — die Verwechslung, aus der
     * die Unterstrich-Falle in `GRANT` entsteht (`docs/36 §3.1`).
     *
     * Hier wird sie an der anderen Stelle abgefangen: Die Mandantengrenze im
     * Agenten fragt {@see Names::belongsTo()}, und die vergleicht mit dem
     * Unterstrich als Teil des Präfixes. Ohne ihn dürfte das Abonnement `p1001`
     * die Datenbanken von `p10012` entfernen.
     */
    public function test_a_foreign_prefix_is_not_a_prefix(): void
    {
        $this->assertTrue(Names::belongsTo('p1001_shop', 'p1001'));
        $this->assertFalse(Names::belongsTo('p10012_shop', 'p1001'));
        $this->assertFalse(Names::belongsTo('p1002_shop', 'p1001'));
        $this->assertFalse(Names::belongsTo('mysql', 'p1001'));
    }

    /** @return list<array{0: string}> */
    public static function badSuffixes(): array
    {
        return [
            // Ein Punkt trennt in `db.tabelle` — und in einem Pfad.
            ['shop.alt'],

            // Backtick und Anführungszeichen: die beiden Zeichen, mit denen
            // sich eine SQL-Anweisung verlassen lässt.
            ['shop`x'],
            ["shop'x"],
            ['shop"x'],

            // Der Pfadausbruch, wie überall in diesem Projekt.
            ['..'],
            ['../etc'],
            ['a/b'],

            // Beginnt nicht mit einem Buchstaben.
            ['1shop'],
            ['_shop'],

            // `lower_case_table_names` entscheidet sonst je nach System, ob
            // `Shop` und `shop` dasselbe sind.
            ['Shop'],

            // Ein Bindestrich zwänge zur Anführung.
            ['shop-alt'],

            // Leerzeichen, Nullbyte, Prozent.
            ['shop x'],
            ['shop%'],

            // Zu lang: siebzehn Zeichen.
            ['aaaaaaaaaaaaaaaaa'],

            [''],

            // Reserviert: die Form eines befristeten Benutzers. Ohne diese
            // Sperre verlöre ein Kunde, der seinen Zugang so nennt, ihn nach
            // einer Stunde an `srvpanel db prune`.
            ['r3f9a20c1'],
            ['r00000000'],
        ];
    }

    #[DataProvider('badSuffixes')]
    public function test_a_bad_suffix_is_refused(string $suffix): void
    {
        $this->expectException(AgentException::class);

        Names::database('p1001', $suffix);
    }

    /**
     * Der vollständige Name aus der abgelegten Zeile geht durch dieselbe Form.
     *
     * **Das ist die Zeile, an der ein `DROP DATABASE mysql` scheitert.** Die
     * Anwendung schickt beim Entfernen den ganzen Namen und nicht die zwei
     * Hälften — er steht so in der Datenbank. Geprüft wird er trotzdem.
     */
    public function test_an_existing_name_carries_the_same_shape(): void
    {
        $this->assertSame('p1001_shop', Names::existing('p1001_shop'));

        foreach (['mysql', 'information_schema', 'performance_schema', 'sys', 'shop', 'p1001', 'p1001_', '`x`'] as $wrong) {
            try {
                Names::existing($wrong);
                $this->fail(sprintf('%s ist als bestehender Name durchgegangen.', var_export($wrong, true)));
            } catch (AgentException $error) {
                $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
            }
        }
    }

    /**
     * Der längste mögliche Name bleibt unter der engsten Grenze.
     *
     * `p` + neun Ziffern + `_` + sechzehn Zeichen = 27, und MySQL nimmt 32 für
     * einen Benutzernamen. Die Rechnung steht hier, weil sie sonst niemand
     * nachrechnet — und weil ein Name, der auf einem der beiden Systeme nicht
     * anlegbar ist, auf diesem Server irgendwann nicht anlegbar ist.
     */
    public function test_the_longest_name_fits_under_the_tightest_limit(): void
    {
        $longest = Names::user('p123456789', str_repeat('a', 16));

        $this->assertSame('p123456789_aaaaaaaaaaaaaaaa', $longest);
        $this->assertLessThanOrEqual(Names::MAX_USER, strlen($longest));
        $this->assertLessThanOrEqual(Names::MAX_DATABASE, strlen($longest));
        $this->assertSame(32, Names::MAX_USER, 'Die engere Zahl ist die von MySQL und nicht die von MariaDB.');
    }

    /**
     * Ein Wirt mit Platzhalter wird abgewiesen (`docs/36 §12`).
     *
     * Ein Datenbankbenutzer, der von überall erreichbar ist, ist die Vorlage
     * für den nächsten Vorfallsbericht. Und `10.0.0.%` ist es genauso: Es sieht
     * aus wie ein Netz und ist ein Muster, das MariaDB anders auflöst als ein
     * Mensch es liest.
     */
    public function test_a_host_with_a_wildcard_is_refused(): void
    {
        $this->assertSame('localhost', Names::host('localhost'));
        $this->assertSame('203.0.113.5', Names::host('203.0.113.5'));
        $this->assertSame('2001:db8::1', Names::host('2001:db8::1'));
        $this->assertSame('203.0.113.0/255.255.255.0', Names::host('203.0.113.0/255.255.255.0'));

        foreach (['%', '10.0.0.%', '%.example.de', 'kunde.example.de', '203.0.113.0/24', ''] as $wrong) {
            try {
                Names::host($wrong);
                $this->fail(sprintf('%s ist als Wirt durchgegangen.', var_export($wrong, true)));
            } catch (AgentException $error) {
                $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
            }
        }
    }

    /**
     * Ein befristeter Benutzer trägt das Präfix und ist als solcher erkennbar.
     *
     * Beides zusammen ist die Voraussetzung dafür, dass `db.server.info` einen
     * meldet, der nach einem abgebrochenen Zurückspielen stehengeblieben ist —
     * und dass er dabei sichtbar zu jemandem gehört.
     */
    public function test_an_ephemeral_user_is_recognisable_and_belongs_to_someone(): void
    {
        $ephemeral = Names::ephemeral('p1001');

        $this->assertTrue(Names::isEphemeral($ephemeral));
        $this->assertTrue(Names::belongsTo($ephemeral, 'p1001'));
        $this->assertMatchesRegularExpression('/^p1001_r[0-9a-f]{8}$/', $ephemeral);

        // Zwei Läufe geben nicht denselben Namen — sonst kollidierten zwei
        // gleichzeitige Wiederherstellungen desselben Abonnements.
        $this->assertNotSame($ephemeral, Names::ephemeral('p1001'));

        $this->assertFalse(Names::isEphemeral('p1001_web'));
        $this->assertFalse(Names::isEphemeral('p1001_rabcdefgh'));
    }

    /**
     * **Die Form eines befristeten Benutzers ist für Kunden gesperrt.**
     *
     * Der Fund, der beim Schreiben dieses Tests entstanden ist und nicht im
     * Betrieb: `r3f9a20c1` erfüllt die Zusatzregel — Kleinbuchstaben und
     * Ziffern, beginnend mit einem Buchstaben. Ein Kunde hätte seinen Zugang so
     * nennen dürfen, `db.server.info` hätte ihn eine Stunde später als Rest
     * eines abgebrochenen Zurückspielens gemeldet, und `srvpanel db prune`
     * hätte ihn weggeworfen. Ohne dass irgendetwas falsch programmiert wäre.
     *
     * Der Test steht in **beide** Richtungen: Der Name lässt sich nicht wählen,
     * und er wird als befristet erkannt. Fiele eine der beiden Hälften weg,
     * wäre die andere eine Vermutung.
     */
    public function test_the_ephemeral_shape_is_reserved(): void
    {
        $this->assertTrue(Names::isEphemeral('p1001_r12345678'));

        $this->expectException(AgentException::class);

        Names::user('p1001', 'r12345678');
    }
}
