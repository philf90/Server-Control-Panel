<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\PgDumpImport;
use SrvPanel\Agent\Pg\Hba;
use SrvPanel\Agent\Runner;

/**
 * Was das Zurückspielen einer PostgreSQL-Sicherung zusammenhält.
 *
 * Vier Regeln, und jede von ihnen bricht **still**: Der Vorgang meldet Erfolg,
 * und was fehlt, merkt jemand später.
 */
final class PgRestoreTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }

    /**
     * `ON_ERROR_STOP=1` steht **im Aufruf** und nicht in einer Konstante.
     *
     * **Der Plan verlangt genau diese Fassung des Tests** (`docs/38 §13.1`):
     * *Der Aufruf, nicht die Absicht — ein Wächter, der eine Konstante
     * `ON_ERROR_STOP` findet, hat eine Konstante gefunden.*
     *
     * Ohne den Schalter gibt `psql -f` bei gescheitertem SQL **0** zurück und
     * arbeitet weiter (gemessen am 9. August 2026: vier Anweisungen, die dritte
     * abgewiesen, Rückgabewert 0, die vierte lief). Ein Zurückspielen, das
     * vollständig scheitert, meldete dann „erledigt" — und Kriterium 6 hätte
     * keine Fehlermeldung, die es belegen könnte.
     */
    public function test_the_restore_stops_at_the_first_error(): void
    {
        // **Der Lauf steht in `Pg\Session::restore()` und nicht in der
        // Operation** — `AgentIdentityTest` besteht darauf, dass `psql` an
        // genau einer Stelle gerufen wird, und hat beim ersten Anlauf von
        // Schritt 6 zugebissen. Geprüft wird deshalb dort.
        $source = $this->source('agent/src/Pg/Session.php');

        $this->assertMatchesRegularExpression(
            "/public function restore\(.*?'-v', 'ON_ERROR_STOP=1'/s",
            $source,
            'PgRestore ruft `psql` ohne `-v ON_ERROR_STOP=1` auf. Ohne den Schalter läuft psql über '.
            'Fehler hinweg und endet mit 0 — eine halb eingespielte Datenbank, gemeldet als Erfolg.',
        );
    }

    /**
     * Die Sicherung entsteht ohne Eigentümer und ohne Rechte.
     *
     * Sonst stehen `ALTER … OWNER TO x7f3a…_web`-Zeilen im Dump, und beim Umzug
     * auf einen anderen Server — der Normalfall für eine Sicherung — zeigen sie
     * auf Rollen, die es dort nicht gibt. Gemessen: mit den Schaltern null
     * `OWNER TO`-Zeilen, ohne sie eine.
     */
    public function test_the_dump_carries_no_owners(): void
    {
        $source = $this->source('agent/src/Ops/PgDumpCreate.php');

        foreach (['--no-owner', '--no-privileges'] as $flag) {
            $this->assertStringContainsString(
                "'".$flag."'",
                $source,
                "pg_dump läuft ohne {$flag}. Der Dump trägt dann Rollennamen, die es auf einem anderen ".
                'Server nicht gibt.',
            );
        }
    }

    /**
     * Und **kein** DEFINER-Filter über PostgreSQL-Daten.
     *
     * Die Gegenrichtung, und sie ist die wichtigere: `pg_dump` schreibt keine
     * `DEFINER`-Angaben (gemessen: null Treffer). Ein Filter, der trotzdem über
     * jede Zeile läuft, käme an alles, was ein Kunde gespeichert hat — und
     * `docs/36 §10.1` hält fest, was ein zu breites Suchen-und-Ersetzen in
     * einem Dump anrichtet: stille Datenkorrektur durch ein Sicherungswerkzeug.
     */
    public function test_the_postgres_dump_is_written_through_unchanged(): void
    {
        $this->assertStringNotContainsString(
            'withoutDefiner',
            $this->source('agent/src/Ops/PgDumpCreate.php'),
            'PgDumpCreate filtert die Zeilen. pg_dump schreibt keine DEFINER-Angaben — was der Filter '
            .'hier trifft, sind Kundendaten.',
        );

        $this->assertStringContainsString(
            'withoutDefiner',
            $this->source('agent/src/Ops/DbDumpCreate.php'),
            'DbDumpCreate filtert nicht mehr. Seit der Filter im Aufruf steht statt in der Schleife, '
            .'ist sein Fehlen genau so still wie sein Zuviel.',
        );
    }

    /**
     * Die Zeile in `pg_hba.conf` steht **vor** dem Bestand.
     *
     * `pg_hba.conf` wird von oben nach unten gelesen, und die erste passende
     * Zeile entscheidet — auch wenn sie abweist. Unter `local all all peer`
     * käme die neue nie zum Zug, und die befristete Rolle bliebe draussen.
     */
    public function test_the_rule_goes_above_the_existing_ones(): void
    {
        $ergebnis = Hba::prepend("local   all   all   peer\n");

        $this->assertLessThan(
            strpos($ergebnis, 'peer'),
            strpos($ergebnis, Hba::RULE),
            'Die Zeile für das Zurückspielen steht unter der peer-Zeile und kommt damit nie zum Zug.',
        );

        $this->assertStringContainsString(Hba::MARK, $ergebnis, 'Ohne Marke findet der zweite Lauf sie nicht.');
    }

    /**
     * Die Fassung im Kopf eines Dumps — Hauptfassung, nicht Zeichenkette.
     *
     * Der häufigste Fall überhaupt ist ein Sicherheitsupdate Abstand zwischen
     * zwei Servern derselben Distribution. Wer die volle Fassung vergliche,
     * wiese genau den ab.
     */
    public function test_the_dump_header_names_its_version(): void
    {
        $kopf = "--\n-- PostgreSQL database dump\n--\n\n"
            ."-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)\n"
            ."-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)\n";

        $this->assertSame([16, 13], PgDumpImport::versionOf($kopf));
        $this->assertSame([17, 0], PgDumpImport::versionOf("-- Dumped from database version 17.0\n"));

        // Kein Kopf ist kein Fehler: Eine von Hand geschriebene .sql.gz hat
        // keinen und ist trotzdem eine gültige Sicherung.
        $this->assertNull(PgDumpImport::versionOf("CREATE TABLE kunden (id int);\n"));

        // **Und die Zeile von `pg_dump`, nicht die vom Server.** Beide stehen im
        // Kopf und tragen fast immer dieselbe Zahl; die des Servers ist die, die
        // zählt, wenn sie auseinandergehen.
        $this->assertSame(
            [15, 4],
            PgDumpImport::versionOf(
                "-- Dumped from database version 15.4\n-- Dumped by pg_dump version 16.13\n"
            ),
        );
    }

    /**
     * Die Umgebung des Runners bleibt eine Positivliste.
     *
     * **Der Anlass ist P5b:** `psql` kennt keinen Schalter für die
     * Passwortdatei, nur `PGPASSFILE`. Damit hat der Runner zum ersten Mal
     * überhaupt eine Ergänzung seiner festen Umgebung bekommen — und eine
     * Umgebung ist dieselbe Angriffsfläche wie eine Kommandozeile: `LD_PRELOAD`
     * lädt fremden Code in einen Prozess, der als root läuft.
     *
     * Geprüft werden beide Richtungen: dass die Liste kurz bleibt, und dass
     * jeder Name darauf auch benutzt wird. Eine Erlaubnis ohne Aufrufer ist
     * Angriffsfläche ohne Nutzen — dieselbe Regel wie in
     * {@see AgentOperationReachTest}.
     */
    public function test_the_environment_stays_an_allowlist(): void
    {
        $this->assertSame(['PGPASSFILE'], Runner::ENVIRONMENT_ALLOWED);

        // Über den ganzen Agenten und nicht nur über `Ops/`: Der Aufruf ist
        // mit Schritt 6 nach `Pg\Session` gezogen, und ein Wächter, der dem
        // nicht folgt, meldet Rot für eine Ordnung, die er durchsetzen soll.
        $dateien = glob(dirname(__DIR__, 2).'/agent/src/{Ops,Pg,Db}/*.php', GLOB_BRACE) ?: [];
        $benutzt = [];

        foreach ($dateien as $datei) {
            $inhalt = (string) file_get_contents($datei);

            foreach (Runner::ENVIRONMENT_ALLOWED as $name) {
                if (str_contains($inhalt, $name) || str_contains($inhalt, 'Credentials::VARIABLE')) {
                    $benutzt[$name] = true;
                }
            }
        }

        $this->assertSame(
            Runner::ENVIRONMENT_ALLOWED,
            array_keys($benutzt),
            'Ein Name auf der Positivliste, den keine Operation setzt, ist eine Erlaubnis ohne Aufrufer.',
        );
    }
}
