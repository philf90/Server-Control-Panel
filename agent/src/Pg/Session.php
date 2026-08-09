<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Session as DbSession;

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
