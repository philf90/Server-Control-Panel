<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Pg\Session;
use Tests\Support\WithoutPhpComments;

/**
 * Ein gescheitertes SQL beendet den Lauf — der Unterschied zu MariaDB, der
 * beinahe unbemerkt geblieben wäre.
 *
 * **`psql -f` gibt bei gescheitertem SQL 0 zurück und arbeitet weiter.** Am
 * 9. August 2026 nebeneinander gemessen, dreimal dieselbe Anweisungsfolge:
 *
 *     mit ON_ERROR_STOP=1   → Ausnahme, und die dritte Anweisung lief nicht
 *     ohne                  → Rückgabewert 0, und die dritte Anweisung lief
 *
 * `mysql` bricht von selbst ab; genau darauf ruht der Beleg von Kriterium 6 in
 * P5 („ERROR 1045 at line 6520"). Ohne den Schalter wäre in P5b ein
 * **vollständig gescheitertes Zurückspielen als „erledigt" gemeldet** worden,
 * und für einen bösartigen Dump gäbe es keine Fehlermeldung, die man zitieren
 * könnte (`docs/38 §13.1`).
 *
 * Das ist Lehre 3 aus `docs/37 §6` an einer Stelle, an der das andere System sie
 * von selbst einhält — und deshalb die gefährlichste Sorte: **eine Regel, die
 * man nur dort lernt, wo sie gebrochen wird.**
 *
 * **Was dieser Wächter kann und was nicht.** Er hält die Zeichenkette gegen den
 * Aufruf; dass sie im Betrieb greift, hat der Lauf gegen einen echten Server
 * gezeigt und wird im Abnahmelauf belegt (`docs/38 §19`, Schritt 8). Der
 * Wächter, der die **Wirkung** am erzeugten Aufruf misst, kommt mit dem
 * Zurückspielen in Schritt 6 — hier gibt es noch keinen Aufruf, den man
 * abfangen könnte.
 */
final class PgSessionTest extends TestCase
{
    use WithoutPhpComments;

    private function source(): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Pg/Session.php'),
        );
    }

    /**
     * Der Schalter steht in den Argumenten jedes Aufrufs.
     *
     * **Und er steht dort als Argument und nicht als Konstante daneben.**
     * `docs/36 §10.3` hält den Unterschied fest: *Ein Wächter, der drei Werte
     * gegeneinander hält, prüft nicht, dass sie gelten.* Geprüft wird deshalb
     * beides — dass der Wert in `ARGUMENTS` steht **und** dass `ARGUMENTS` in
     * den Aufruf geht.
     */
    public function test_every_call_carries_on_error_stop(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'ON_ERROR_STOP=1'", $source, 'Der Schalter steht nirgends mehr.');

        $this->assertMatchesRegularExpression(
            '/array_merge\(\s*self::ARGUMENTS/',
            $source,
            'ARGUMENTS wird nicht mehr in die Argumente des Aufrufs gelegt — dann steht der Schalter '
            .'zwar da und gilt nicht.',
        );
    }

    /**
     * Angemeldet wird über den Socket und als die Rolle, die die Kennung ist.
     *
     * Über TCP gibt es kein `peer`, und dann käme die Anmeldung als `root`
     * nicht zustande — der Fehler führte an eine Stelle, an der niemand nach
     * einer Authentifizierungsmethode sucht.
     */
    public function test_the_agent_connects_over_the_socket_as_its_own_identity(): void
    {
        $this->assertSame('root', Session::ROLE);
        $this->assertStringContainsString("'-h', Server::SOCKET_DIRECTORY", $this->source());
        $this->assertStringContainsString("'-U', self::ROLE", $this->source());
    }

    /**
     * Das SQL geht über die Standardeingabe und nie als Argument.
     *
     * Wortgleich die Regel aus `Db\Session`, und sie hat hier denselben Grund:
     * Ein Passwort in der Kommandozeile stünde für jeden in der Prozessliste.
     *
     * **`-f` stand hier bis Schritt 6 daneben, und das war zu weit gegriffen.**
     * Die Regel heisst *kein SQL in der Kommandozeile* — `-c` trägt SQL, `-f`
     * trägt einen Dateinamen. Verboten war es mitgemeint, weil es in Schritt 1
     * keinen Fall dafür gab und beide gleich aussahen; mit dem Zurückspielen
     * gibt es einen, und dann ist der Unterschied der ganze Punkt. Ein Pfad
     * unter `/var/lib/srvpanel/dumps` steht ohnehin in der Prozessliste, egal
     * wie er übergeben wird, und er ist kein Geheimnis.
     *
     * Eine Regel, die zu weit greift, wird geschärft und nicht abgeschaltet —
     * deshalb steht unten die Prüfung, die den eigentlichen Zweck festhält:
     * **kein Passwort unter den Argumenten.**
     */
    public function test_sql_goes_over_standard_input(): void
    {
        $this->assertStringNotContainsString(
            "'-c'",
            $this->source(),
            'Session ruft psql mit -c auf — dann steht das SQL in der Kommandozeile.',
        );
    }

    /**
     * Und `-f` gibt es an genau einer Stelle, mit einem Pfad statt SQL.
     *
     * Die Gegenprobe zur Lockerung darüber. Fiele sie weg, liesse sich der
     * Schalter überall hinschreiben, und die geschärfte Regel wäre eine
     * abgeschaffte.
     */
    public function test_the_file_switch_carries_a_path_and_only_in_the_restore(): void
    {
        $source = $this->source();

        $this->assertSame(
            1,
            substr_count($source, "'-f',"),
            'psql wird an mehr als einer Stelle mit -f gerufen. Der Schalter gehört zum Zurückspielen '.
            'und zu nichts sonst.',
        );

        $this->assertMatchesRegularExpression(
            "/'-f',\s*\\\$file,/",
            $source,
            '-f bekommt etwas anderes als den Pfad der ausgepackten Sicherung.',
        );
    }

    /**
     * Das Passwort der befristeten Rolle erreicht die Argumente nicht.
     *
     * **Das ist der Zweck, um den es die ganze Zeit ging.** Es geht über eine
     * Datei mit `0600` und `PGPASSFILE`; stünde es in den Argumenten, sähe es
     * jeder, der `ps` aufrufen kann — und zwar für die Dauer eines
     * Zurückspielens, also unter Umständen stundenlang.
     */
    public function test_the_password_never_reaches_the_arguments(): void
    {
        $source = $this->source();

        foreach (["'--password", "'-W'", 'password='] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, sprintf(
                'Session gibt %s an psql weiter — das Passwort stünde in der Prozessliste.',
                trim($forbidden, "'"),
            ));
        }

        $this->assertStringContainsString(
            'Credentials::VARIABLE => $password',
            $source,
            'Das Passwort geht nicht mehr über die Passwortdatei — dann steht es woanders.',
        );
    }
}
