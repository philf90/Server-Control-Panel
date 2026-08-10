<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\PgDatabaseCreate;
use SrvPanel\Agent\Ops\PgDatabaseRemove;
use SrvPanel\Agent\Ops\PgRestore;
use SrvPanel\Agent\Ops\PgRoleCreate;
use SrvPanel\Agent\Ops\PgRoleGrant;
use SrvPanel\Agent\Pg\Ephemeral;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Owner;
use Tests\Support\ReadsMethodSource;
use Tests\Support\WithoutPhpComments;

/**
 * Wem gehört, was in der Datenbank eines Kunden steht?
 *
 * **Diese Frage hat P5 nie stellen müssen.** In MariaDB gehört eine Tabelle
 * niemandem; ein Recht auf dem Schema gilt für alles, was darin entsteht. In
 * PostgreSQL gehört jede Tabelle dem, der sie angelegt hat — und daraus sind in
 * `docs/39` Punkt 7 drei Fehler auf einmal geworden:
 *
 * 1. Ein zweiter Zugang desselben Abonnements bekam `permission denied for
 *    table` auf alles, was der erste angelegt hatte.
 * 2. Ein Zurückspielen in eine Datenbank mit Tabellen scheiterte überhaupt.
 * 3. Und was eingespielt wurde, gehörte danach `root` — der Kunde stand vor
 *    seinen eigenen Zeilen, und der Vorgang meldete Erfolg.
 *
 * Die Antwort ist {@see Owner}. Diese Datei ist ihr Wächter, und sie prüft
 * beides: dass die Anweisungen stimmen (als Text, wie `PgShieldingTest`) und
 * dass jede Stelle, die etwas anlegt, sie auch benutzt.
 */
final class PgOwnerTest extends TestCase
{
    use ReadsMethodSource;
    use WithoutPhpComments;

    /**
     * Der Rumpf einer Methode — **ohne seine Kommentare**.
     *
     * **Diese Zeile ist der Fund des Gegenbruchs.** `test_the_restore_empties_
     * before_it_fills()` suchte nach `Owner::reset(` im Quelltext und fand es
     * zuerst in einem `{@see Owner::reset()}` mitten im Kommentar darüber — vor
     * dem Zurückspielen, immer. Der Wächter war grün, auch als die Reihenfolge
     * absichtlich vertauscht wurde.
     *
     * > **Ein Wächter, der im Quelltext liest, liest auch, was jemand über den
     * > Quelltext geschrieben hat.** Und in diesem Projekt steht darüber viel.
     */
    private function code(string $class, string $method): string
    {
        return $this->withoutComments('<?php '.((string) $this->methodSource($class, $method)));
    }

    private function prefix(): string
    {
        return Names::newPrefix();
    }

    /**
     * Die Rolle meldet sich nirgends an.
     *
     * **Sie ist ein Name für Eigentum und kein Zugang.** Ein `LOGIN` hier wäre
     * ein Konto, das jeder Zugang des Abonnements gemeinsam hätte — ohne
     * Passwort, das jemand wechseln kann, und ohne Spur im Protokoll, wer
     * verbunden war.
     */
    public function test_the_owner_role_never_logs_in(): void
    {
        $statement = Owner::creation(Names::owner($this->prefix()));

        $this->assertStringContainsString('NOLOGIN', $statement);
        $this->assertStringNotContainsString('PASSWORD', $statement);

        foreach (['NOSUPERUSER', 'NOCREATEDB', 'NOCREATEROLE', 'NOREPLICATION', 'NOBYPASSRLS'] as $expected) {
            $this->assertStringContainsString($expected, $statement, sprintf(
                'Die Eigentümerrolle sagt nicht ausdrücklich, dass sie kein %s ist.',
                $expected,
            ));
        }
    }

    /**
     * Und sie wird nicht weitergereicht.
     *
     * `WITH ADMIN OPTION` wäre der Weg, auf dem ein Kunde die Mitgliedschaft
     * selbst vergibt — und damit die Grenze eines Abonnements zu etwas macht,
     * das davon abhängt, dass er es nicht tut.
     */
    public function test_the_membership_is_not_passed_on(): void
    {
        $prefix = $this->prefix();
        $statement = Owner::membership(Names::owner($prefix), Names::role($prefix, 'web'));

        foreach (['ADMIN OPTION', 'GRANT OPTION', 'SUPERUSER', 'CREATEDB', 'CREATEROLE'] as $needle) {
            $this->assertStringNotContainsString($needle, $statement, sprintf(
                'Diese Anweisung vergibt mehr als eine Mitgliedschaft: %s',
                $statement,
            ));
        }
    }

    /**
     * Die Sitzungsrolle wird mit `RESET` zurückgenommen.
     *
     * **Gemessen und nicht gewählt.** `RESET` auf einen Eintrag, den es nie
     * gab, endet mit Rückgabewert 0 — die Rücknahme ist damit wiederholbar, und
     * ein abgebrochener Entzug lässt sich zu Ende bringen. Ein
     * `SET role = NONE` an derselben Stelle liesse einen Eintrag stehen, der
     * aussieht wie eine Einstellung.
     */
    public function test_the_session_role_is_taken_back_with_reset(): void
    {
        $prefix = $this->prefix();
        $owner = Names::owner($prefix);
        $role = Names::role($prefix, 'web');
        $database = Names::database($prefix, 'shop');

        $this->assertStringContainsString('SET role = ', Owner::sessionRole($owner, $role, $database, true));
        $this->assertStringEndsWith('RESET role', Owner::sessionRole($owner, $role, $database, false));
    }

    /**
     * Das Leeren gibt das Schema **danach** weiter, nicht davor.
     *
     * **Die Reihenfolge ist die ganze Anweisung.** `CREATE SCHEMA public` legt
     * ein Schema an, das der ausführenden Rolle gehört — dem Agenten. Käme das
     * `ALTER SCHEMA … OWNER TO` davor, bezöge es sich auf das Schema, das
     * einen Befehl später weggeworfen wird, und der Kunde stünde nach dem
     * Zurückspielen wieder vor Daten, die `root` gehören. Genau der Fehler aus
     * Punkt 7, nur eine Zeile weiter.
     */
    public function test_the_reset_hands_over_the_new_schema_and_not_the_old(): void
    {
        $statements = Owner::reset(Names::owner($this->prefix()));

        $drop = array_search('DROP SCHEMA public CASCADE', $statements, true);
        $create = array_search('CREATE SCHEMA public', $statements, true);
        $hand = null;

        foreach ($statements as $index => $statement) {
            if (str_starts_with($statement, 'ALTER SCHEMA public OWNER TO ')) {
                $hand = $index;
            }
        }

        $this->assertIsInt($drop, 'Das Schema wird nicht mehr geleert.');
        $this->assertIsInt($create, 'Das Schema wird nicht neu angelegt.');
        $this->assertNotNull($hand, 'Das neue Schema bekommt keinen Eigentümer.');

        $this->assertLessThan($create, $drop, 'Angelegt wird vor dem Wegwerfen — dann wirft es sich selbst weg.');
        $this->assertLessThan(
            $hand,
            $create,
            'Der Eigentümer wird gesetzt, bevor es das Schema gibt — er gilt dann für das alte, '
            .'und nach dem Zurückspielen gehört alles wieder dem Agenten.',
        );
    }

    /**
     * Der Name der Eigentümerrolle ist für Zugänge gesperrt.
     *
     * Ohne die Sperre nähme ein Kunde, der seinen Zugang `owner` nennt, seinem
     * eigenen Abonnement alles, was in seinen Datenbanken steht — der Name wäre
     * derselbe.
     */
    public function test_no_access_can_take_the_owner_name(): void
    {
        $this->expectException(AgentException::class);

        Names::suffix(Names::OWNER_SUFFIX);
    }

    /**
     * Jede Stelle, die etwas anlegt, sorgt selbst für die Rolle.
     *
     * **Das ist der eigentliche Wächter dieser Datei.** Die Anweisungen oben
     * lassen sich lesen und stimmen; was sie wertlos machte, wäre eine
     * Operation, die sie nicht aufruft — und das ist genau das Muster, das
     * dieses Projekt sechsmal teuer bezahlt hat: *eine Zeichenkette, die auf
     * etwas verweist, ohne dass ein Test den Bezug prüft.*
     *
     * `ensure()` und nicht `Names::owner()`: Ein Abonnement, das vor dieser
     * Fassung entstanden ist, hat die Rolle nicht — wer nur ihren Namen bildet,
     * schickt ihn an eine Rolle, die es nicht gibt.
     */
    public function test_every_creating_operation_ensures_the_role(): void
    {
        $sites = [
            [PgDatabaseCreate::class, 'execute', 'Eine neue Datenbank ohne Eigentümerrolle hat ein Schema, das dem Agenten gehört.'],
            [PgRoleCreate::class, 'execute', 'Ein neuer Zugang ohne Mitgliedschaft sieht die Tabellen der anderen nicht.'],
            [PgRoleGrant::class, 'execute', 'Eine Freigabe ohne Sitzungsrolle lässt den Zugang als sich selbst arbeiten.'],
            [PgRestore::class, 'retrofit', 'Ohne Nachrüstung gehört das Zurückgespielte wieder root.'],
        ];

        foreach ($sites as [$class, $method, $why]) {
            $source = $this->code($class, $method);

            $this->assertStringContainsString('owner->ensure(', $source, sprintf(
                '%s::%s() ruft Owner::ensure() nicht auf. %s',
                $class,
                $method,
                $why,
            ));
        }

        // Und die Nachrüstung wird auch gerufen — eine Methode, die niemand
        // aufruft, ist genau die Sorte Stille, für die dieser Test da ist.
        $this->assertStringContainsString(
            'retrofit(',
            $this->code(PgRestore::class, 'execute'),
            'Das Zurückspielen ruft die Nachrüstung nicht mehr auf.',
        );
    }

    /**
     * Und die befristete Rolle arbeitet als sie.
     *
     * Ohne diese Zeile gehört das Zurückgespielte der Rolle, die es in fünf
     * Minuten nicht mehr gibt — und das Aufräumen nimmt es mit.
     */
    public function test_the_ephemeral_role_works_as_the_owner(): void
    {
        $source = $this->code(Ephemeral::class, 'with');

        $this->assertStringContainsString('Names::owner(', $source);
        $this->assertStringContainsString('Owner::sessionRole(', $source);
    }

    /**
     * `REASSIGN OWNED BY` steht nirgends mehr.
     *
     * **Es war die Antwort auf die richtige Frage und hat die falsche gegeben.**
     * Es übertrug das Eingespielte an den Eigentümer der *Datenbank* — und die
     * gehört dem Panel, nicht dem Kunden. Genau daran hing Fehler 3 aus Punkt 7.
     * Seit die befristete Rolle als die Eigentümerrolle arbeitet, besitzt sie
     * am Ende nichts (gemessen: 0 Tabellen), und die Übertragung hat kein Ziel
     * mehr, das richtig wäre.
     */
    public function test_nothing_reassigns_ownership_to_the_panel(): void
    {
        $source = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Pg/Ephemeral.php'
        ));

        $this->assertStringNotContainsString('REASSIGN OWNED BY %s TO', $source,
            'Das Eigentum wird wieder übertragen — der Kunde bekommt seine Daten damit nicht zurück, '
            .'sondern verliert sie an den Eigentümer der Datenbank.');
    }

    /**
     * Geleert wird, bevor eingespielt wird.
     *
     * Andersherum wäre es ein Zurückspielen, das seine eigene Arbeit wegwirft —
     * und es meldete Erfolg. Dass beide Aufrufe dastehen, sagt für sich noch
     * nichts; die Reihenfolge ist die Aussage.
     */
    public function test_the_restore_empties_before_it_fills(): void
    {
        $source = $this->code(PgRestore::class, 'execute');

        $reset = strpos($source, 'Owner::reset(');
        $restore = strpos($source, 'ephemeral->with(');

        $this->assertIsInt($reset, 'Das Schema wird vor dem Zurückspielen nicht mehr geleert.');
        $this->assertIsInt($restore, 'Es wird nichts mehr zurückgespielt.');
        $this->assertLessThan($restore, $reset, 'Erst eingespielt, dann geleert — das wirft die Arbeit weg.');
    }

    /**
     * Die Eigentümerrolle geht mit der letzten Datenbank.
     *
     * **Der Weg zurück, den `docs/35` erzwungen hat.** Etwas, das sich anlegen,
     * aber nirgends löschen lässt, bleibt Jahre stehen und fällt erst einer
     * Datenmigration auf. Gefragt wird der Katalog — die Anwendung dürfte sich
     * verzählen, und dann liefe ein `DROP ROLE` auf eine Rolle, der noch ein
     * Schema gehört.
     */
    public function test_the_owner_role_goes_with_the_last_database(): void
    {
        $source = $this->code(PgDatabaseRemove::class, 'removeOwner');

        $this->assertStringContainsString('FROM pg_database WHERE starts_with(datname', $source,
            'Der Rückbau fragt nicht mehr nach, ob noch eine Datenbank dieses Abonnements steht.');
        $this->assertStringContainsString('return null;', $source,
            'Es gibt keinen Weg mehr, die Rolle stehenzulassen — dann geht sie mit der ersten Datenbank.');
    }
}
