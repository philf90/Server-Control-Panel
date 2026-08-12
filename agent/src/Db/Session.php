<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Ops\PanelProvision;

/**
 * Ein Lauf gegen den Datenbankserver.
 *
 * **Die einzige Stelle im Agenten, die `mysql` aufruft.** Das ist keine
 * Aufräumerei, sondern die Vorleistung für P5b: `docs/20 §15` Punkt 5 hält
 * fest, dass PostgreSQL eine Erweiterung sein soll und kein Umbau, und die
 * ganze Vorleistung dafür ist diese Bündelung. Wer hier vorbei ein zweites
 * `run('mysql', …)` schreibt, macht aus der Zusage eine Behauptung.
 *
 * **SQL geht über die Standardeingabe, nie als Argument.**
 * {@see PanelProvision} macht es vor, und der Grund steht dort: Ein Passwort in
 * der Kommandozeile stünde für jeden sichtbar in der Prozessliste. Für den
 * befristeten Benutzer aus `docs/36 §10.2` gilt dasselbe — sein Passwort geht
 * über eine Optionsdatei mit `0600`, die im `finally` wieder verschwindet, und
 * nicht über `--password=`.
 *
 * **Angemeldet wird über den Unix-Socket als root.** Der Agent läuft als root,
 * MariaDB erkennt ihn über `unix_socket`, und damit braucht dieser Lauf kein
 * Datenbankpasswort — dieselbe Anmeldung, mit der `panel.provision` seit P0
 * arbeitet.
 */
final class Session
{
    /**
     * Wie lange eine Anweisung laufen darf.
     *
     * Ein `DROP DATABASE` über vierzig Gigabyte ist kein hängender Prozess,
     * sondern ein arbeitender. Zehn Minuten sind die Grenze, ab der etwas nicht
     * mehr stimmt — und der Vorgang ist so lange abbrechbar, weil
     * {@see Context::stream()} den Abbruch in der Warteschleife prüft.
     */
    public const TIMEOUT = 600;

    /**
     * Anweisungen ausführen; die Ausgabe interessiert nicht.
     *
     * @param  list<string>  $statements
     */
    public function execute(Context $context, array $statements, ?Credentials $as = null): void
    {
        if ($statements === []) {
            return;
        }

        $this->run($context, implode("\n", array_map(
            static fn (string $s): string => rtrim($s, "; \t\n").';',
            $statements,
        ))."\n", $as);
    }

    /**
     * Eine Abfrage, zeilenweise und ohne Kopfzeile.
     *
     * `--batch` liefert Spalten durch Tabulatoren getrennt, `--skip-column-names`
     * lässt die Kopfzeile weg. Beides zusammen ist ein Format, das sich ohne
     * Bibliothek lesen lässt — und `LC_ALL=C` aus dem Runner hält es stabil,
     * auch auf einem deutsch eingestellten Server.
     *
     * @return list<list<string>>
     */
    public function query(Context $context, string $sql, ?Credentials $as = null): array
    {
        $result = $this->run($context, rtrim($sql, "; \t\n").";\n", $as);

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
     * Eine Abfrage unter einem befristeten Benutzer — zeilenweise, Felder durch Tabulator.
     *
     * Für die Katalogfragen der Konsole ({@see Console}) und für das
     * `SELECT ROW_COUNT()` hinter einem Schreibvorgang. Was hier zurückkommt,
     * sind Bezeichner und Zahlen; die Textform trägt das.
     *
     * @return list<list<string>>
     */
    public function queryAs(Context $context, Credentials $as, string $sql): array
    {
        $rows = [];

        foreach ($this->linesAs($context, $as, $sql, false) as $line) {
            $rows[] = explode("\t", $line);
        }

        return $rows;
    }

    /**
     * Eine Abfrage unter einem befristeten Benutzer — eine JSON-Zeile je Datenzeile.
     *
     * **`--raw`, und das ist der ganze Unterschied** (`docs/46 §8.1`). `--batch`
     * maskiert in der Ausgabe Tabulator, Zeilenumbruch und **Rückstrich**; eine
     * JSON-Zeichenkette besteht aus maskierten Rückstrichen, und aus `"a\tb"`
     * wird damit `"a\\tb"` — gültiges JSON mit einem falschen Wert, das
     * fehlerfrei gelesen wird. Gemessen am 12. August 2026 (N1/N2).
     *
     * **Dass `--raw` sonst gefährlich wäre, gilt hier nicht:** Ein roher
     * Zeilenumbruch im Wert bräche die Zeilentrennung — `JSON_OBJECT()`
     * maskiert Steuerzeichen aber selbst, und damit ist eine Datenzeile genau
     * eine Ausgabezeile. **Die Sicherheit kommt vom Format und nicht vom
     * Klienten**, und deshalb darf der Klient sie loslassen.
     *
     * @return list<string> je Eintrag ein JSON-Dokument
     */
    public function jsonAs(Context $context, Credentials $as, string $sql): array
    {
        return $this->linesAs($context, $as, $sql, true);
    }

    /**
     * Der Lauf unter einem befristeten Benutzer.
     *
     * **Das Zeitlimit steht in derselben Sitzung wie die Abfrage** und nicht in
     * einer Einstellung am Benutzer: Ein zweiter Aufruf wäre eine zweite
     * Verbindung, und was dort gilt, gilt hier nicht. Dass der Benutzer es nicht
     * zurücknehmen kann, liegt allein daran, dass er kein `SET` schicken darf —
     * die Anweisung baut {@see Console} und nicht die Anwendung
     * (`docs/46 §9`).
     *
     * @return list<string>
     */
    private function linesAs(Context $context, Credentials $as, string $sql, bool $raw): array
    {
        $prepared = sprintf(
            "SET max_statement_time = %s;\n%s;\n",
            (string) Console::TIMEOUT_SECONDS,
            rtrim($sql, "; \t\n"),
        );

        $arguments = ['--protocol=socket', '--batch', '--skip-column-names'];

        if ($raw) {
            $arguments[] = '--raw';
        }

        $file = null;

        try {
            $file = $as->write();
            array_unshift($arguments, '--defaults-extra-file='.$file);

            $result = $context->runner->run(
                'mysql',
                $arguments,
                self::TIMEOUT,
                null,
                $prepared,
                fn (): bool => $context->abandoned(),
            );
        } finally {
            if ($file !== null) {
                @unlink($file);
            }
        }

        if (! $result->successful()) {
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
     * `--protocol=socket`, damit auch dann der Socket benutzt wird, wenn in
     * einer `my.cnf` ein Host steht: Über TCP käme die Anmeldung als root nicht
     * zustande, und der Fehler führte an eine Stelle, an der niemand nach einer
     * Konfigurationsdatei sucht.
     */
    private function run(Context $context, string $sql, ?Credentials $as): string
    {
        $arguments = ['--protocol=socket', '--batch', '--skip-column-names'];
        $file = null;

        try {
            if ($as !== null) {
                $file = $as->write();

                // **Vor allen anderen Argumenten.** `--defaults-extra-file`
                // wird von mysql nur an erster Stelle gelesen; steht es hinten,
                // meldet das Programm einen Syntaxfehler — und die Meldung
                // nennt die Datei, nicht die Position.
                array_unshift($arguments, '--defaults-extra-file='.$file);
            }

            $result = $context->runner->run('mysql', $arguments, self::TIMEOUT, null, $sql, fn (): bool => $context->abandoned());
        } finally {
            if ($file !== null) {
                @unlink($file);
            }
        }

        if (! $result->successful()) {
            throw AgentException::execFailed(
                'Die Datenbank hat abgewiesen: '.$result->message(),
                // **Ohne das SQL in den Details.** Es enthielte bei
                // `db.user.create` das Passwort, und die Details gehen als
                // Ergebnis des Vorgangs in die Datenbank des Panels zurück.
                ['code' => $result->code],
            );
        }

        return $result->stdout;
    }
}
