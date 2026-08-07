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
