<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Server as DbServer;

/**
 * Läuft hier ein PostgreSQL — und dürfen wir darauf arbeiten?
 *
 * Das Gegenstück zu {@see DbServer}, mit einem Unterschied, den P5 nicht kennt:
 * **Es gibt einen Zustand zwischen „läuft nicht" und „nutzbar".** Der Server
 * kann laufen und der Agent trotzdem nicht hineinkommen, weil die Rolle `root`
 * noch fehlt (`docs/38 §6.1`). Das ist kein Fehler, sondern eine Übergabe, die
 * noch aussteht — und es gehört unterschieden, weil die Antwort für den
 * Betreiber eine andere ist: einmal „starte den Dienst", einmal „führe diesen
 * Befehl aus".
 *
 * **Nichts davon ist ein Fehlschlag der Operation.** Wortgleich die
 * Entscheidung aus {@see DbServer}: „PostgreSQL läuft nicht" ist eine Auskunft.
 * Ein rot gemeldeter Vorgang stünde alle fünfzehn Minuten neben allem, was
 * tatsächlich kaputt ist.
 */
final class Server
{
    public function __construct(private readonly Clusters $clusters = new Clusters) {}

    /**
     * Wo der Socket liegt.
     *
     * Debian und Ubuntu legen ihn nach `/var/run/postgresql`, unabhängig von
     * der Fassung und vom Cluster. Ein Pfad und kein Rechnername: `peer` gibt
     * es nur lokal (`docs/38 §6`).
     */
    public const SOCKET_DIRECTORY = '/var/run/postgresql';

    /**
     * Die kleinste Fassung, auf der dieses Panel arbeitet.
     *
     * Was an 14 anders ist, steht in {@see Shielding}: Bis dahin darf `PUBLIC`
     * im Schema `public` jeder Datenbank anlegen. Die Absperrung nimmt das Recht
     * ausdrücklich weg, statt sich auf die Vorgabe zu verlassen — deshalb ist 14
     * benutzbar und nicht bloss geduldet.
     *
     * **Und hier stand, welche Fassung jede der vier Zielplattformen liefert.
     * Gemessen war davon eine.** Die Zahlen für Ubuntu 22.04, Debian 12 und
     * Debian 13 waren aus dem Gedächtnis geschrieben, und genau diese Sorte
     * Satz hat in P5b viermal einen Abschnitt umgeworfen.
     *
     * **Und die eine gemessene stimmte auch nicht ganz.** Hier stand „Ubuntu
     * 24.04 liefert 16.13, zweimal belegt" — belegt war es einmal, im
     * Entwicklungscontainer. Der zweite Beleg sollte der apt-Kandidat auf
     * `cloudsrv24` sein, und `16+257build1.1` ist die Nummer des *Metapakets*;
     * über die Serverfassung dahinter sagt sie nichts. Die erste Installation
     * dort brachte am 9. August 2026 **16.14**. Ein Wartungsstand Unterschied,
     * folgenlos für alles, was hier zählt — und trotzdem eine Zahl, die
     * niemand gemessen hatte.
     *
     * Sie stehen deshalb nicht mehr da. Was zählt, ist die Grenze selbst: Eine
     * Fassung darunter bekommt die Datenbankfläche nicht angeboten, und
     * {@see self::usable()} sagt im Klartext, warum. **Für die Abnahme von P5b
     * ist das folgenlos** — sie läuft auf `cloudsrv24`, und dort ist die Fassung
     * gemessen. Für die Freigabe gehört sie auf allen vier Plattformen
     * nachgesehen; sie steht als offener Punkt in `docs/38 §2.3`.
     */
    public const MIN_VERSION = 14;

    /**
     * Der Befehl, den der Betreiber einmal ausführt.
     *
     * Er steht hier als Konstante und nicht in der Oberfläche, weil die
     * Oberfläche ihn **anzeigt** und nicht kennt: Ein abgedruckter Befehl, den
     * es so nicht gibt, ist genau der Fehler, den `docs/36 §22.3v` teuer
     * bezahlt hat.
     */
    public const HANDOVER = 'sudo -u postgres psql -c "CREATE ROLE root SUPERUSER LOGIN"';

    /**
     * Was hier steht — in einem Lauf.
     *
     * **Die Reihenfolge ist Absicht: erst die Cluster, dann die Verbindung.**
     * Bis zum 9. August 2026 stand hier ein `is_dir()` auf das
     * Socketverzeichnis, und das unterschied „nicht installiert" von „läuft
     * nicht" — gemessen tut es das nicht, denn das Verzeichnis fehlt in beiden
     * Fällen. {@see Clusters} fragt statt dessen Debians eigenes Werkzeug und
     * beantwortet damit vier Fragen, bevor irgendetwas verbunden wird.
     *
     * `state` sagt, was der Betreiber als Nächstes tun muss — und jeder Wert
     * steht für genau einen Handgriff:
     *
     * | `state` | was zu tun ist |
     * |---|---|
     * | `absent` | PostgreSQL installieren (der Knopf im Panel) |
     * | `no_cluster` | installiert, aber kein Cluster — `pg_createcluster` |
     * | `stopped` | ein Cluster, gestoppt — starten |
     * | `ambiguous` | mehrere laufen; das Panel wählt nicht (docs/38 §7) |
     * | `not_handed_over` | die Rolle `root` fehlt — {@see self::HANDOVER} |
     * | `unusable` | Fassung zu alt |
     * | `ready` | nichts |
     *
     * @return array{
     *     state: string,
     *     available: bool,
     *     handed_over: bool|null,
     *     version: string,
     *     major: int|null,
     *     usable: bool,
     *     reason: string|null,
     *     handover: string,
     *     cluster: string|null,
     *     directory: string|null,
     *     port: int|null,
     *     clusters: list<array{version: int, name: string, port: int, running: bool, directory: string}>,
     * }
     */
    public function describe(Context $context, Session $session): array
    {
        $blank = [
            'state' => 'absent',
            'available' => false,

            /*
             * **`null` und nicht `false` — „nicht nachgesehen" ist keine
             * Antwort mit `nein`.** Ob es die Rolle `root` gibt, weiss nur, wer
             * sich anmelden konnte; in `stopped`, `ambiguous`, `no_cluster` und
             * `absent` ist niemand dazu gekommen. Stand hier `false`, las das
             * Panel den Vorgabewert als Messung und zeigte bei gestopptem
             * Cluster den Hinweis „Rolle anlegen" — mit einem Befehl, der in
             * genau diesem Zustand nicht laufen *kann*, weil `psql` niemanden
             * erreicht.
             *
             * Gefunden am 10. August 2026 in Punkt 2 der Zwischenabnahme
             * (`docs/39`), auf einem Bild und nicht von einem Test. Es ist
             * derselbe Fehler wie `env('SRVPANEL_VERSION', '0.1.0-dev')` zwei
             * Tage davor: **Ein Vorgabewert, den niemand überschreibt, ist kein
             * Vorgabewert — er ist die Antwort.**
             */
            'handed_over' => null,
            'version' => '',
            'major' => null,
            'usable' => false,
            'reason' => null,
            'handover' => self::HANDOVER,
            'cluster' => null,
            'directory' => null,
            'port' => null,
            'clusters' => [],
        ];

        $clusters = $this->clusters->all($context);

        if ($clusters === null) {
            return array_merge($blank, [
                'reason' => 'PostgreSQL ist auf diesem Server nicht installiert.',
            ]);
        }

        $blank['clusters'] = $clusters;

        if ($clusters === []) {
            return array_merge($blank, [
                'state' => 'no_cluster',
                'reason' => 'PostgreSQL ist installiert, aber es gibt keinen Cluster.',
            ]);
        }

        $running = array_values(array_filter($clusters, static fn (array $c): bool => $c['running']));

        /*
         * **Bei mehreren laufenden Clustern wählt das Panel nicht.** Das ist
         * die eine Stelle, an der Raten Kundendaten kostet: Zwei Cluster
         * heissen fast immer, dass der Betreiber einen davon selbst betreibt —
         * und in den schrieben wir dann Kundendatenbanken.
         *
         * **Gezählt werden die laufenden und nicht alle.** `docs/20 §15`
         * Punkt 4 hält für nginx die feinere Fassung fest: Ein *laufender*
         * fremder Webserver verweigert den Betrieb, ein bloss installierter
         * nicht — auf manchen Systemen liegt einer als Abhängigkeit herum, ohne
         * je zu starten. Ein gestoppter zweiter Cluster ist dasselbe.
         */
        if (count($running) > 1) {
            return array_merge($blank, [
                'state' => 'ambiguous',
                'available' => true,
                'reason' => sprintf(
                    'Auf diesem Server laufen %d PostgreSQL-Cluster (%s). Welcher die Kundendatenbanken '
                    .'aufnehmen soll, entscheidet nicht das Panel.',
                    count($running),
                    implode(', ', array_map(
                        static fn (array $c): string => sprintf('%d/%s auf Port %d', $c['version'], $c['name'], $c['port']),
                        $running,
                    )),
                ),
            ]);
        }

        if ($running === []) {
            $first = $clusters[0];

            return array_merge($blank, [
                'state' => 'stopped',
                'available' => true,
                'cluster' => sprintf('%d/%s', $first['version'], $first['name']),
                'port' => $first['port'],
                'reason' => 'Der PostgreSQL-Cluster läuft nicht.',
            ]);
        }

        try {
            /*
             * **`server_version` und nicht `version()`.** Die zweite liefert
             * einen ganzen Satz — „PostgreSQL 16.13 (Ubuntu
             * 16.13-0ubuntu0.24.04.1) on x86_64-pc-linux-gnu, compiled by gcc
             * (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0, 64-bit" —, und der stand
             * bis zum 9. August 2026 als Kennung in einer Wertzelle der
             * Oberfläche. Genau diese Bauart hat `docs/20 §15` bezahlt: eine
             * Kennung im Fliesstext, die die Seite um 83px aus dem Bildschirm
             * schob, vollständig grün getestet und ausgeliefert.
             *
             * `server_version` sagt dasselbe, was jemanden angeht — Fassung und
             * Paketierung —, in einem Drittel der Zeichen. Der Compiler und die
             * Architektur beantworten keine Frage, die man vor einem Panel hat.
             */
            $rows = $session->query(
                $context,
                "SELECT current_setting('server_version_num'), current_setting('server_version'), "
                ."current_setting('data_directory'), current_setting('port')",
            );
        } catch (AgentException $error) {
            /*
             * **Ein laufender Cluster, der abweist, ist die fehlende
             * Übergabe.** Dass er läuft, steht schon fest — das hat
             * `pg_lsclusters` beantwortet. Was `psql` hier meldet, ist deshalb
             * nicht mehr „der Dienst ist tot", sondern „die Rolle root gibt es
             * nicht" (docs/38 §6.1).
             */
            return array_merge($blank, [
                'state' => 'not_handed_over',
                'available' => true,

                // Hier ist es gemessen: Der Cluster läuft und hat abgewiesen.
                'handed_over' => false,
                'cluster' => sprintf('%d/%s', $running[0]['version'], $running[0]['name']),
                'port' => $running[0]['port'],
                'reason' => $error->getMessage(),
            ]);
        }

        $row = $rows[0] ?? [];
        $major = intdiv((int) ($row[0] ?? 0), 10000);
        $directory = (string) ($row[2] ?? '');

        [$usable, $reason] = self::usable($major);

        /*
         * **Und hier wird das Gewählte gegen das Erreichte gehalten.** Der
         * Cluster oben kommt aus `pg_lsclusters`, das Datenverzeichnis unten
         * aus der Verbindung selbst. Stimmen sie nicht überein, hat `psql` mit
         * einem anderen geredet als dem, den wir gemeint haben — etwa weil in
         * einer `~/.psqlrc` oder in der Umgebung ein Port steht.
         *
         * Das ist keine ausgedachte Sorge: Es ist genau die Bauart, die
         * `docs/36 §22.3w` beim Fernzugriff gekostet hat — geschrieben wurde
         * das eine, gewirkt hat das andere, und gemerkt hat es niemand, weil
         * nichts zurückgelesen wurde. Ein Fehlschlag ist es nicht; das Panel
         * soll es sehen und sagen können.
         */
        if ($directory !== '' && $directory !== $running[0]['directory']) {
            $reason = sprintf(
                'Erwartet war der Cluster unter %s, geantwortet hat der unter %s.',
                $running[0]['directory'],
                $directory,
            );
            $usable = false;
        }

        /*
         * **Was gemeldet wird, kommt aus der Verbindung zurück und nicht aus
         * der Auswahl.** `data_directory` und `port` sagen, welcher Cluster
         * tatsächlich geantwortet hat — Lehre 1 aus `docs/37 §6`: Eine
         * geschriebene Zeile ist eine Absicht, erst der gelesene Zustand ist
         * ein Zustand. Weichen die beiden ab, steht es hier und nicht in einem
         * Rätsel drei Wochen später.
         */
        return array_merge($blank, [
            'state' => $usable ? 'ready' : 'unusable',
            'available' => true,
            'handed_over' => true,
            'version' => (string) ($row[1] ?? ''),
            'major' => $major,
            'usable' => $usable,
            'reason' => $reason,
            'cluster' => sprintf('%d/%s', $running[0]['version'], $running[0]['name']),
            'directory' => $directory,
            'port' => (int) ($row[3] ?? 0),
        ]);
    }

    /**
     * Die Vorbedingung, hart.
     *
     * Für jede Operation, die etwas anlegt.
     */
    public function require(Context $context, Session $session): void
    {
        $info = $this->describe($context, $session);

        if (! $info['usable']) {
            throw AgentException::denied(
                'Auf diesem PostgreSQL arbeitet srvpanel nicht: '.($info['reason'] ?? 'unbekannter Grund'),
            );
        }
    }

    /**
     * Der eine laufende Cluster — oder `null`, wenn es nicht genau einer ist.
     *
     * **Dieselbe Regel wie in {@see self::describe()}, nur als Antwort statt als
     * Zustand:** Bei zwei laufenden Clustern wählt das Panel nicht, und bei
     * keinem gibt es nichts zu wählen. Wer hier `null` bekommt, hat keine
     * Auskunft bekommen, sondern eine Absage — und die gehört an den Betreiber
     * weitergereicht und nicht mit einer Annahme überschrieben.
     *
     * @return null|array{version: int, name: string, port: int, running: bool, directory: string}
     */
    public function primaryCluster(Context $context): ?array
    {
        $clusters = $this->clusters->all($context) ?? [];
        $running = array_values(array_filter($clusters, static fn (array $c): bool => $c['running']));

        return count($running) === 1 ? $running[0] : null;
    }

    /**
     * Die Hauptfassung des laufenden Clusters — oder `null`.
     *
     * **Aus `pg_lsclusters` und nicht aus einer Abfrage**, obwohl beides ginge.
     * Der Aufrufer ist `pg.dump.import`, und der läuft, bevor jemand
     * zurückspielt: Er soll auch dann eine Antwort bekommen, wenn der Cluster
     * gerade steht. Ein `SELECT` bräuchte eine Verbindung.
     */
    public function majorOf(Context $context): ?int
    {
        $clusters = $this->clusters->all($context) ?? [];

        if ($clusters === []) {
            return null;
        }

        $running = array_values(array_filter($clusters, static fn (array $c): bool => $c['running']));

        // Läuft genau einer, gilt seine Fassung. Läuft keiner, gilt die des
        // einzigen vorhandenen — und bei mehreren gibt es keine Antwort, aus
        // demselben Grund wie in {@see self::primaryCluster()}.
        return match (true) {
            count($running) === 1 => $running[0]['version'],
            $running === [] && count($clusters) === 1 => $clusters[0]['version'],
            default => null,
        };
    }

    /**
     * Wo `pg_hba.conf` liegt — **gefragt und nicht gebaut**.
     *
     * `/etc/postgresql/<fassung>/<cluster>/pg_hba.conf` wäre der Pfad, den
     * Debian anlegt, und er stimmte hier fast immer. `SHOW hba_file` liefert
     * den, den der laufende Server tatsächlich liest — auch dann, wenn jemand
     * `hba_file` in `postgresql.conf` umgestellt hat oder der Cluster von Hand
     * mit `initdb` entstanden ist.
     *
     * Das ist dieselbe Entscheidung wie bei `Names::fqdn()` und bei
     * `Clusters::unit()`: **Ein Pfad, den man zusammensetzt, ist eine Vermutung
     * über fremde Einrichtung.** In P5b war genau das dreimal der Fehler.
     */
    public function hbaFile(Context $context, Session $session): string
    {
        $rows = $session->query($context, 'SHOW hba_file');
        $path = (string) ($rows[0][0] ?? '');

        if ($path === '' || ! str_starts_with($path, '/')) {
            throw AgentException::execFailed('Der Ort von pg_hba.conf liess sich nicht feststellen.');
        }

        return $path;
    }

    /** @return array{0: bool, 1: string|null} */
    private static function usable(int $major): array
    {
        if ($major < self::MIN_VERSION) {
            return [false, sprintf(
                'PostgreSQL %d ist älter als %d. Bis PostgreSQL 14 darf PUBLIC im Schema public jeder '
                .'Datenbank Objekte anlegen; darunter liegen Fassungen, gegen die dieses Panel die '
                .'Abschottung nie gemessen hat.',
                $major,
                self::MIN_VERSION,
            )];
        }

        return [true, null];
    }
}
