<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Die Selbstprobe der Mandantentrennung — eine Verbindung, die abgewiesen wird.
 *
 * **Das ist das Abnahmekriterium von P5**, und „nachweislich" ist das Wort, um
 * das es geht: *„ein Datenbankbenutzer sieht nachweislich keine fremde
 * Datenbank."* Man kann `SHOW GRANTS` als root lesen und feststellen, dass dort
 * genau eine Datenbank steht. Das zeigt nicht, dass MariaDB die Regel anwendet,
 * dass der Unterstrich richtig maskiert ist und dass kein zweites `GRANT` von
 * irgendwoher dazugekommen ist. Das zeigt nur eine Verbindung, die es versucht.
 *
 * Wortgleich die Begründung von {@see WebIsolationProbe} für P3 — dort ein
 * Skript, das durch nginx und den Pool hindurch versucht, was es nicht darf.
 *
 * **Sie meldet Namen und keine Zahl.** `SHOW DATABASES` gibt eine Liste zurück,
 * und genau die geht heraus. Eine Antwort „1 Datenbank sichtbar" sagt nicht,
 * *welche* — und der teuerste Fehler des P4-Abnahmelaufs war eine Meldung, die
 * die richtige Zahl nannte und die falsche Sache gezählt hatte. CLAUDE.md hält
 * ihn als Satz fest: **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht,
 * was gezählt wurde.**
 *
 * **Sie verrät keine Daten.** Der Zugriffsversuch auf die fremde Tabelle meldet,
 * *ob* er abgewiesen wurde und mit welchem Fehler — nie eine Zeile. Ein
 * Selbsttest, der bei einem Fehlschlag ausgibt, woran er nicht hätte kommen
 * dürfen, hat aus einem Beleg ein Leck gemacht; auch dieser Satz steht schon
 * bei `web.isolation.probe`.
 *
 * **Das Passwort überquert den Socket, und das ist hier richtig.** Es entsteht
 * im Agenten, das Panel hält es für die Dauer eines Aufrufs und schickt es für
 * die Probe zurück — es gibt keinen anderen Weg, eine Verbindung *als dieser
 * Benutzer* aufzubauen, und genau die ist das Kriterium. Was **nicht** passiert:
 * Der Aufruf geht unmittelbar über {@see Client} und nie über
 * die Warteschlange, denn dort läge er in `operations.payload` — dieselbe Regel
 * wie bei `tls.certificate.upload` und `dns.credential.store`.
 *
 * Nicht verändernd in der Wirkung, aber `seed` legt eine Tabelle an — deshalb
 * `mutating: true`.
 */
final class DbIsolationProbe implements Op
{
    /**
     * Die Tabelle, die `seed` anlegt.
     *
     * Fest und aus dem Quelltext, nicht als Argument: Dieselbe Bedingung, unter
     * der `web.isolation.probe` eine Datei in ein Kundenverzeichnis schreiben
     * darf. Käme der Name von aussen, wäre das eine Fernsteuerung zum Anlegen
     * beliebiger Tabellen unter fremdem Namen.
     */
    public const TABLE = 'srvpanel_selbsttest';

    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'db.isolation.probe';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $prefix = Names::prefix($args['user'] ?? null);
        $account = Names::existing($args['account'] ?? null, 'account');
        $database = Names::existing($args['database'] ?? null, 'database');
        $host = Names::host($args['host'] ?? 'localhost');
        $action = Guard::enum($args['action'] ?? 'probe', ['seed', 'probe'], 'action');

        /*
         * **Beide Namen gehören dem genannten Abonnement — ausser dem fremden.**
         * Der Zugang und die eigene Datenbank werden gegen das Präfix geprüft,
         * damit diese Operation nicht als Weg taugt, sich mit fremden
         * Zugangsdaten an einer fremden Datenbank zu versuchen. Der *fremde*
         * Name darf und soll fremd sein — er ist der Gegenstand der Probe, und
         * angefasst wird er nur mit den Rechten des eigenen Zugangs.
         */
        foreach ([$account, $database] as $value) {
            if (! Names::belongsTo($value, $prefix)) {
                throw AgentException::denied(sprintf(
                    'Der Name %s gehört nicht zum Abonnement %s.',
                    $value,
                    $prefix,
                ));
            }
        }

        $credentials = new Credentials($account, Guard::string($args['password'] ?? null, 'password'), $host);

        if ($action === 'seed') {
            return $this->seed($context, $credentials, $database);
        }

        return $this->probe(
            $context,
            $credentials,
            $database,
            Names::existing($args['foreign'] ?? null, 'foreign'),
        );
    }

    /**
     * Kriterium 2: benutzen.
     *
     * Anlegen, schreiben, lesen — unter den Zugangsdaten des Kunden und nicht
     * als root. Ein Lauf, der die Tabelle als root anlegte und danach nur das
     * Lesen prüfte, beliesse die Hälfte des Kriteriums ungeprüft.
     *
     * @return array<string, mixed>
     */
    private function seed(Context $context, Credentials $as, string $database): array
    {
        $table = Sql::identifier($database).'.'.Sql::identifier(self::TABLE);

        $this->session->execute($context, [
            'DROP TABLE IF EXISTS '.$table,
            'CREATE TABLE '.$table.' (id INT PRIMARY KEY, wert VARCHAR(32) NOT NULL)',
            'INSERT INTO '.$table.' (id, wert) VALUES (1, '.Sql::text('abnahme').')',
        ], $as);

        $rows = $this->session->query($context, 'SELECT wert FROM '.$table.' WHERE id = 1', $as);

        return [
            'action' => 'seed',
            'table' => self::TABLE,

            // Der gelesene Wert ist hier kein Leck: Er stammt aus der Zeile
            // darüber und steht in dieser Datei. Er belegt, dass Schreiben und
            // Lesen wirklich durchliefen und nicht nur nicht scheiterten.
            'value' => (string) ($rows[0][0] ?? ''),
        ];
    }

    /**
     * Kriterium 3: keine fremde Datenbank.
     *
     * **Drei Fragen und nicht eine.** `SHOW DATABASES` ist eine Anzeige, `USE`
     * ist der Wechsel, das `SELECT` ist der Zugriff. Ein Server kann die
     * Anzeige filtern und den Zugriff trotzdem zulassen; wer nur die Liste
     * prüft, hat die Anzeige geprüft. docs/36 §17 verlangt deshalb alle drei.
     *
     * @return array<string, mixed>
     */
    private function probe(Context $context, Credentials $as, string $database, string $foreign): array
    {
        $context->progress(30, 'sichtbare Datenbanken');

        $visible = [];

        foreach ($this->session->query($context, 'SHOW DATABASES', $as) as $row) {
            $visible[] = (string) ($row[0] ?? '');
        }

        sort($visible);

        $context->progress(60, 'fremde Datenbank betreten');
        $use = $this->refused($context, $as, 'USE '.Sql::identifier($foreign));

        $context->progress(85, 'aus fremder Tabelle lesen');
        $select = $this->refused($context, $as, sprintf(
            'SELECT COUNT(*) FROM %s.%s',
            Sql::identifier($foreign),
            Sql::identifier(self::TABLE),
        ));

        $context->progress(100, 'fertig');

        return [
            'action' => 'probe',
            'database' => $database,
            'foreign' => $foreign,

            // **Namen und keine Zahl.** Die Liste ist der Befund; wer sie zählt,
            // zählt etwas, das er nicht gesehen hat.
            'visible' => $visible,
            'use_refused' => $use,
            'select_refused' => $select,
        ];
    }

    /**
     * Eine Anweisung, die scheitern soll — und die Meldung, mit der sie das tut.
     *
     * **Ein Erfolg ist hier der Befund und kein Ergebnis.** Deshalb wird das
     * Ergebnis der Abfrage weggeworfen und nur `refused` gemeldet: Käme die
     * Zeile mit heraus, stünde bei einem Fehlschlag der Abschottung genau das
     * im Vorgang, wovor sie schützen soll.
     *
     * @return array{refused: bool, error: string}
     */
    private function refused(Context $context, Credentials $as, string $sql): array
    {
        try {
            $this->session->query($context, $sql, $as);
        } catch (AgentException $error) {
            return ['refused' => true, 'error' => $error->getMessage()];
        }

        return ['refused' => false, 'error' => ''];
    }
}
