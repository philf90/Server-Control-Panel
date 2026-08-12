<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Session as DbSession;
use SrvPanel\Agent\Ops\PgRestore;

/**
 * Ein Lauf gegen PostgreSQL.
 *
 * **Die einzige Stelle im Agenten, die `psql` aufruft** — dieselbe Zusage, die
 * {@see DbSession} für `mysql` gibt, und aus demselben Grund: Wer hier vorbei
 * ein zweites `run('psql', …)` schreibt, macht aus einer Trennung eine
 * Behauptung. `AgentIdentityTest` prüft es.
 *
 * ## Angemeldet wird als Rolle `root`, über den Socket
 *
 * MariaDB erkennt root über `unix_socket`; das kostete in P5 keine Zeile.
 * PostgreSQL bildet Unix-Kennungen auf **Rollen** ab, und eine Rolle mit dem
 * Namen der Kennung ist genau die Antwort: Debians Vorgabe enthält
 * `local all all peer`, und existiert die Rolle `root`, kommt der Agent als
 * Superuser durch (gemessen, `docs/38 §2.2b` M25).
 *
 * **Keine Datei wird dafür angefasst, kein Programm wechselt die Kennung, und
 * kein Passwort liegt irgendwo.** Der erste Entwurf von `docs/38 §6` sah einen
 * Kennungswechsel im `Runner` vor; die Messung hat ihn nicht getragen (M26).
 *
 * Angelegt wird die Rolle vom **Betreiber**, einmal — {@see Server} beantwortet,
 * ob das geschehen ist, und das Panel zeigt bis dahin den Befehl statt einer
 * Fehlermeldung.
 *
 * ## SQL geht über die Standardeingabe, nie als Argument
 *
 * Wortgleich die Regel aus {@see DbSession}. Der Grund ist dort ein Passwort in
 * der Prozessliste; hier kommt einer dazu, den `docs/38 §2.2` gemessen hat:
 * **`psql -f` gibt bei gescheitertem SQL 0 zurück und arbeitet weiter.**
 * Deshalb steht `ON_ERROR_STOP=1` in {@see self::ARGUMENTS} und nicht in einem
 * Aufruf — eine Anweisung, die abgewiesen wird, muss diesen Lauf beenden und
 * nicht die nächste Zeile freigeben.
 */
final class Session
{
    /**
     * Wie lange eine Anweisung laufen darf.
     *
     * Dieselbe Zahl wie in {@see DbSession}, und aus demselben Grund: Ein
     * `DROP DATABASE` über vierzig Gigabyte ist kein hängender Prozess, sondern
     * ein arbeitender.
     */
    public const TIMEOUT = 600;

    /**
     * Die Rolle, unter der der Agent arbeitet.
     *
     * Sie heisst wie die Unix-Kennung des Agenten und nicht anders — das ist
     * die ganze Mechanik von `peer`. Eine Rolle mit einem eigenen Namen bräuchte
     * eine Zeile in `pg_hba.conf` oder ein Passwort, und beides ist mehr, als
     * die Aufgabe verlangt.
     */
    public const ROLE = 'root';

    /**
     * Was jeder Aufruf mitbringt.
     *
     * - `-X` überspringt `~/.psqlrc`. Der Agent läuft als root, und `/root`
     *   gehört einem Menschen, der dort etwas hinterlegen darf — eine Datei,
     *   die vor jeder Anweisung läuft, ist kein Ort für Überraschungen.
     * - `-q` und `-A` und `-t`: keine Kopfzeile, keine Ausrichtung, ein Feld je
     *   Trennzeichen. Ein Format, das sich ohne Bibliothek lesen lässt.
     * - `-F` setzt den Trenner ausdrücklich auf den Tabulator statt sich auf
     *   die Vorgabe zu verlassen; damit liest {@see self::query()} dasselbe,
     *   was `Db\Session` liest.
     * - **`-v ON_ERROR_STOP=1`** — siehe Klassenkopf. Ohne den Schalter meldete
     *   ein gescheitertes Zurückspielen Erfolg.
     *
     * @var list<string>
     */
    private const ARGUMENTS = ['-X', '-q', '-A', '-t', '-F', "\t", '-v', 'ON_ERROR_STOP=1'];

    /**
     * Wie lange ein Zurückspielen dauern darf.
     *
     * Vier Stunden, wie in P5. Das Zeitlimit für Anweisungen darüber ist ein
     * anderes und viel kürzer: Eine Abfrage, die eine Minute braucht, ist ein
     * Problem — ein Dump von vierzig Gigabyte nicht.
     */
    private const RESTORE_TIMEOUT = 14_400;

    /**
     * Anweisungen ausführen; die Ausgabe interessiert nicht.
     *
     * @param  list<string>  $statements
     */
    public function execute(Context $context, array $statements, ?string $database = null): void
    {
        if ($statements === []) {
            return;
        }

        $this->run($context, implode("\n", array_map(
            static fn (string $s): string => rtrim($s, "; \t\n").';',
            $statements,
        ))."\n", $database);
    }

    /**
     * Eine Abfrage, zeilenweise.
     *
     * @return list<list<string>>
     */
    public function query(Context $context, string $sql, ?string $database = null): array
    {
        $result = $this->run($context, rtrim($sql, "; \t\n").";\n", $database);

        $rows = [];

        foreach (explode("\n", trim($result)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = explode("\t", $line);
        }

        return $rows;
    }

    /**
     * Eine ganze Datei einspielen — unter einer fremden Rolle, mit Passwort.
     *
     * **Sie steht hier und nicht in {@see PgRestore}, weil
     * `AgentIdentityTest` darauf besteht**, und die Regel ist richtig: `psql`
     * wird an genau einer Stelle gerufen, damit der Socketpfad, die
     * Anmeldeweise und `ON_ERROR_STOP` nicht in zwei Fassungen auseinanderlaufen.
     * Der erste Anlauf von Schritt 6 hat den Lauf in die Operation geschrieben,
     * und der Wächter hat zugebissen.
     *
     * **Drei Unterschiede zu {@see self::run()}, und jeder hat einen Grund.**
     * Die Rolle kommt aus {@see Credentials} statt aus {@see self::ROLE} — das
     * ist der ganze Zweck (`docs/38 §13.4`). Das SQL kommt aus einer Datei statt
     * aus dem Speicher, weil ein Dump zwei Gigabyte haben kann. Und `-A -t -F`
     * fallen weg: Sie formatieren eine Ausgabe, die hier niemand liest.
     *
     * **Der Rückgabewert ist die Meldung**, nicht der Erfolg. Was `psql` bei
     * einem Abbruch schreibt, ist der Beleg für Kriterium 6 — Datei,
     * Zeilennummer, Grund —, und der gehört wörtlich in den Vorgang und nicht
     * in eine Umschreibung (Plan §2, Leitbild 2).
     */
    public function restore(Context $context, Credentials $as, string $database, string $file): void
    {
        $password = null;

        try {
            $password = $as->write();

            $result = $context->runner->run(
                'psql',
                [
                    '-X',
                    '-q',
                    '-v', 'ON_ERROR_STOP=1',
                    '-h', Server::SOCKET_DIRECTORY,
                    '-U', $as->role(),
                    '-d', $database,
                    '-f', $file,
                ],
                self::RESTORE_TIMEOUT,
                null,
                null,
                fn (): bool => $context->abandoned(),
                null,
                [Credentials::VARIABLE => $password],
            );
        } finally {
            if ($password !== null) {
                @unlink($password);
            }
        }

        if (! $result->successful()) {
            throw AgentException::execFailed('Das Zurückspielen ist gescheitert: '.$result->message());
        }
    }

    /**
     * Eine Abfrage unter einer fremden Rolle — zeilenweise, Felder durch Tabulator.
     *
     * Für die Katalogfragen der Konsole ({@see Console}). Was hier zurückkommt,
     * sind Bezeichner und Zahlen; die Textform trägt das (`docs/46 §8`).
     *
     * @return list<list<string>>
     */
    public function queryAs(Context $context, Credentials $as, string $database, string $sql, int $timeoutMs): array
    {
        $rows = [];

        foreach ($this->linesAs($context, $as, $database, $sql, $timeoutMs) as $line) {
            $rows[] = explode("\t", $line);
        }

        return $rows;
    }

    /**
     * Eine Abfrage unter einer fremden Rolle — eine JSON-Zeile je Datenzeile.
     *
     * **Für Daten und nicht für Bezeichner**, und der Unterschied ist gemessen:
     * `-A -t -F "\t"` gibt `NULL` und die leere Zeichenkette beide als leeres
     * Feld aus, macht aus einem Tabulator im Wert eine Spalte und aus einem
     * Zeilenumbruch eine Zeile (`docs/46 §2.2`, M7). `row_to_json` trägt alle
     * vier Fälle.
     *
     * **Die Zeilentrennung bleibt trotzdem gültig**, obwohl ein Wert einen
     * Zeilenumbruch enthalten darf: JSON maskiert ihn selbst zu `\n`, und damit
     * ist eine Datenzeile genau eine Ausgabezeile (M8).
     *
     * @return list<string> je Eintrag ein JSON-Dokument
     */
    public function jsonAs(Context $context, Credentials $as, string $database, string $sql, int $timeoutMs): array
    {
        return $this->linesAs($context, $as, $database, $sql, $timeoutMs);
    }

    /**
     * Anweisungen unter einer fremden Rolle ausführen; die Ausgabe interessiert nicht.
     */
    public function executeAs(Context $context, Credentials $as, string $database, string $statement, int $timeoutMs): void
    {
        $this->linesAs($context, $as, $database, $statement, $timeoutMs);
    }

    /**
     * Der Lauf unter einer fremden Rolle.
     *
     * **Er steht hier und nicht in {@see Console}, weil `AgentIdentityTest`
     * darauf besteht** — dieselbe Begründung wie bei {@see self::restore()}:
     * `psql` wird an genau einer Stelle gerufen, damit Socketpfad, Anmeldeweise
     * und `ON_ERROR_STOP` nicht in zwei Fassungen auseinanderlaufen.
     *
     * **Das Zeitlimit steht in derselben Sitzung wie die Abfrage.** Ein
     * `ALTER ROLE … SET` wäre der andere Weg und der schlechtere: Der
     * Rolleninhaber kann ihn zurücknehmen (`docs/46 §2.2`, M11). Dass er es hier
     * nicht kann, liegt allein daran, dass er kein `SET` schicken darf — die
     * Anweisung baut {@see Console}, nicht die Anwendung.
     *
     * **Und es steht *vor* der Abfrage in demselben Strom**, nicht in einem
     * eigenen Aufruf: Jeder Aufruf ist eine eigene Verbindung, und eine
     * Einstellung in einer anderen Verbindung gilt für diese nicht.
     *
     * @return list<string>
     */
    private function linesAs(Context $context, Credentials $as, string $database, string $sql, int $timeoutMs): array
    {
        $password = null;

        $prepared = sprintf("SET statement_timeout = %d;\n%s;\n", $timeoutMs, rtrim($sql, "; \t\n"));

        try {
            $password = $as->write();

            $result = $context->runner->run(
                'psql',
                array_merge(self::ARGUMENTS, [
                    '-h', Server::SOCKET_DIRECTORY,
                    '-U', $as->role(),
                    '-d', $database,
                ]),
                self::TIMEOUT,
                null,
                $prepared,
                fn (): bool => $context->abandoned(),
                null,
                [Credentials::VARIABLE => $password],
            );
        } finally {
            if ($password !== null) {
                @unlink($password);
            }
        }

        if (! $result->successful()) {
            /*
             * **Die Meldung wörtlich und nicht umschrieben.** An ihr hängen zwei
             * Abnahmekriterien: die abgebrochene Abfrage nach dem Zeitlimit
             * (`docs/46 §4`, Punkt 4) und der Schreibvorgang, der nicht genau
             * eine Zeile getroffen hat (Punkt 6) — der zweite bringt seinen
             * Text aus dem `DO`-Block mit, und eine Umschreibung nähme ihn weg.
             */
            throw AgentException::execFailed(
                'Die Datenbank hat abgewiesen: '.$result->message(),
                ['code' => $result->code],
            );
        }

        $lines = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Der eigentliche Lauf.
     *
     * **`--host` zeigt auf das Socketverzeichnis und nicht auf einen Namen.**
     * Über TCP käme die Anmeldung als `root` nicht zustande — `peer` gibt es nur
     * lokal —, und der Fehler führte an eine Stelle, an der niemand nach einer
     * Authentifizierungsmethode sucht. Dieselbe Überlegung wie
     * `--protocol=socket` in {@see DbSession}.
     */
    private function run(Context $context, string $sql, ?string $database): string
    {
        $arguments = array_merge(self::ARGUMENTS, [
            '-h', Server::SOCKET_DIRECTORY,
            '-U', self::ROLE,
            '-d', $database ?? 'postgres',
        ]);

        $result = $context->runner->run(
            'psql',
            $arguments,
            self::TIMEOUT,
            null,
            $sql,
            fn (): bool => $context->abandoned(),
        );

        if (! $result->successful()) {
            throw AgentException::execFailed(
                'Die Datenbank hat abgewiesen: '.$result->message(),
                // **Ohne das SQL in den Details.** Es enthielte bei
                // `pg.role.create` das Passwort, und die Details gehen als
                // Ergebnis des Vorgangs in die Datenbank des Panels zurück.
                ['code' => $result->code],
            );
        }

        return $result->stdout;
    }
}
