<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Runner;

/**
 * Welche PostgreSQL-Cluster es auf diesem Server gibt.
 *
 * **Der Anlass ist ein Fehler in {@see Server}, den erst eine Messung gefunden
 * hat.** Dort wurde „nicht installiert" von „läuft nicht" an `is_dir()` auf das
 * Socketverzeichnis unterschieden — und gemessen am 9. August 2026 fehlt das
 * Verzeichnis in **beiden** Fällen. Zwei verschiedene Handgriffe des Betreibers
 * — installieren oder starten — hätten dieselbe Meldung bekommen. Das ist
 * wörtlich Lehre 3 aus `docs/37 §6`: *Eine Prüfung, die im Fehlerfall dasselbe
 * sagt wie im Erfolgsfall, belegt nichts.*
 *
 * **Gefragt wird Debians eigenes Werkzeug und nicht `/etc/postgresql`.**
 * `pg_lsclusters` beantwortet in einem Aufruf, was sonst vier Fragen wären:
 * installiert? wie viele? läuft er? auf welchem Port? Sich das aus den
 * Konfigurationsdateien zusammenzusuchen wäre eine zweite Fassung dieses
 * Werkzeugs — und die zweite Fassung ist die, die veraltet.
 *
 * **`null` und `[]` sind hier zwei verschiedene Antworten.** `null` heisst: Das
 * Werkzeug gibt es nicht, also ist PostgreSQL nicht installiert. `[]` heisst:
 * Es ist installiert, und es gibt keinen Cluster — der Zustand nach einem
 * `pg_dropcluster`. Der erste ist ein Fall für den Knopf „installieren", der
 * zweite nicht.
 */
final class Clusters
{
    /**
     * Die Ausgabe von `pg_lsclusters`, Zeile für Zeile.
     *
     * ```
     * Ver Cluster Port Status Owner    Data directory              Log file
     * 16  main    5432 down   postgres /var/lib/postgresql/16/main /var/log/…
     *  0    1      2     3      4              5                        6
     * ```
     *
     * **Die Nummern stehen darunter, weil ich sie beim ersten Wurf um eins
     * verfehlt habe** — Feld 4 ist der Eigentümer und nicht das
     * Datenverzeichnis. Aufgefallen ist es beim Lauf gegen das echte Werkzeug,
     * nicht beim Lesen: Ein Cluster mit dem Datenverzeichnis `postgres` sieht
     * in einer Ablage nicht falsch aus.
     *
     * Am 9. August 2026 nachgelesen, mit einem und mit zwei Clustern. Getrennt
     * wird an Leerraum: Ein Clustername darf keinen enthalten, und ein
     * Datenverzeichnis, das einen hätte, käme aus einer Einrichtung, die dieses
     * Panel ohnehin nicht anfassen darf.
     *
     * @return null|list<array{version: int, name: string, port: int, running: bool, directory: string}>
     */
    public function all(Context $context): ?array
    {
        try {
            $result = $context->runner->run('pg_lsclusters', ['--no-header'], 30);
        } catch (AgentException $error) {
            /*
             * **Nur „gibt es nicht" wird hier zu einer Antwort.** Ein Werkzeug,
             * das da ist und scheitert, sagt etwas anderes als eines, das
             * fehlt — und das durchzureichen ist richtig: Wer `pg_lsclusters`
             * nicht ausführen kann, hat ein Problem, das keine Auskunft ist.
             */
            if ($error->errorCode === AgentException::NOT_FOUND) {
                return null;
            }

            throw $error;
        }

        return self::parse($result->stdout);
    }

    /**
     * Die Ausgabe lesen — ohne den Server, der sie erzeugt hat.
     *
     * **Getrennt, weil genau hier der Fehler sass und ein Test ihn nicht
     * erreichen konnte.** Solange Aufruf und Auswertung in einer Methode
     * standen, war die Feldnummer nur gegen ein laufendes `pg_lsclusters` zu
     * prüfen — und das gibt es in der CI nicht. Eine Zeile als Zeichenkette
     * hineinzugeben ist derselbe Zuschnitt, mit dem P3 seine Vorlagen prüft:
     * Was gemessen werden soll, ist eine Eigenschaft der Zeichenkette.
     *
     * @return list<array{version: int, name: string, port: int, running: bool, directory: string}>
     */
    public static function parse(string $output): array
    {
        $clusters = [];

        foreach (explode("\n", trim($output)) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            // Die Kopfzeile fängt sich hier mit: `Ver` ist keine Zahl. Sie
            // sollte bei `--no-header` gar nicht kommen — aber die Prüfung
            // kostet nichts, und eine Fassung, die sie doch schreibt, ergäbe
            // sonst einen Cluster mit der Fassung 0.
            if (count($fields) < 6 || ! ctype_digit($fields[0])) {
                continue;
            }

            $clusters[] = [
                'version' => (int) $fields[0],
                'name' => $fields[1],
                'port' => (int) $fields[2],

                // `online` ist der einzige Zustand, in dem ein Cluster
                // antwortet. `down` ist der zweite, den Debian schreibt; alles
                // andere — etwa ein Cluster mitten im Start — ist für uns
                // ebenfalls „antwortet nicht", und das ist die sichere
                // Richtung.
                'running' => $fields[3] === 'online',
                'directory' => $fields[5],
            ];
        }

        return $clusters;
    }

    /**
     * Die systemd-Unit, die diesen einen Cluster trägt.
     *
     * **Und die Unit, die es *nicht* ist: `postgresql.service`.** Die ist
     * Debians Sammelunit; sie startet die Instanzen und bleibt danach mit
     * `RemainAfterExit` auf `active` stehen — auch wenn jeder Cluster darunter
     * längst steht. Am 9. August 2026 auf `cloudsrv24` genau so gemessen:
     * `systemctl stop postgresql@16-main.service`, und die Übersicht meldete
     * PostgreSQL weiter als `active`, während die Datenbankseite „läuft nicht"
     * sagte.
     *
     * Das ist zum dritten Mal in P5b dasselbe Muster — `is_dir()` auf das
     * Socketverzeichnis, `is_executable()` auf den PHP-Handler, und jetzt eine
     * Sammelunit: **ein Stellvertreter, der im Erfolgsfall dasselbe sagt wie im
     * Fehlerfall.**
     *
     * Der Name wird deshalb hier gebaut und nirgends sonst. Aus zwei Werten
     * einen Unitnamen zusammenzusetzen ist genau der Vorgang, den die
     * Positivliste des {@see Runner} verhindert — und dass es
     * hier trotzdem passiert, ist kein Widerspruch: `service.status` liest nur,
     * und die beiden Werte kommen aus {@see self::all()} und nicht aus einer
     * Anfrage. Für das *Starten* wird weiterhin `pg_ctlcluster` genommen.
     */
    public static function unit(int $version, string $name): string
    {
        return sprintf('postgresql@%d-%s.service', $version, $name);
    }

    /**
     * Einen vorhandenen Cluster starten.
     *
     * **Das ist kein Eingriff in die Einrichtung des Betreibers**, sondern
     * derselbe Handgriff, den `apt` beim Installieren macht — und das Panel
     * steuert `mariadb.service` seit P5 auf demselben Weg. Was es *nicht* tut:
     * einen Cluster anlegen, umkonfigurieren oder seinen Port ändern.
     *
     * `pg_ctlcluster` und nicht `systemctl`: Der Unitname hängt an Fassung und
     * Clustername (`postgresql@16-main.service`), und ihn zusammenzusetzen wäre
     * genau das, was eine Positivliste verhindern soll — aus zwei Werten einen
     * Namen bauen, den dann jemand anders auflöst.
     */
    public function start(Context $context, int $version, string $name): void
    {
        $result = $context->runner->run('pg_ctlcluster', [(string) $version, $name, 'start'], 120);

        if (! $result->successful()) {
            throw AgentException::execFailed(
                sprintf('Der Cluster %d/%s liess sich nicht starten: %s', $version, $name, $result->message()),
            );
        }
    }
}
