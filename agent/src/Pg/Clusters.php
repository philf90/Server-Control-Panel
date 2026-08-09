<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;

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

        $clusters = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

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
