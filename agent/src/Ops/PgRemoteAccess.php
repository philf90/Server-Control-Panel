<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Hba;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Result;

/**
 * PostgreSQL von aussen erreichbar machen — und festlegen, wer von wo hereindarf.
 *
 * Das Gegenstück zu {@see DbRemoteAccess}, und der Unterschied ist eine ganze
 * Datei: In MariaDB steht der Wirt eines Zugangs **im Benutzernamen**
 * (`'p1001_web'@'203.0.113.5'`), in PostgreSQL steht er in `pg_hba.conf` und
 * sonst nirgends. Diese Operation schreibt deshalb **zwei Hälften**, und nur
 * eine davon hat ein Vorbild in P5 (`docs/38 §14.1`).
 *
 * ## Hälfte eins: `listen_addresses`
 *
 * Eine eigene Datei im Include-Verzeichnis, wie `60-srvpanel.cnf` in P5 —
 * keine Distributionsdatei wird angefasst, Leitbild 1. **Neustart und nicht
 * Reload:** Der Wert hat Kontext `postmaster` (`docs/38 §2.2a`, M20).
 *
 * ## Hälfte zwei: der verwaltete Block in `pg_hba.conf`
 *
 * **Die Zeile nennt die Datenbank und nicht `all`** (M23) — die zweite Wand
 * hinter dem `REVOKE CONNECT` aus `docs/38 §10`. Sie kostet eine Zeile je
 * Datenbank × Rolle × Netz.
 *
 * **Der Aufruf trägt immer den vollständigen Sollzustand**, nicht eine
 * Änderung. Ein „füge diese eine Zeile hinzu" wäre eine Operation, deren
 * Ergebnis von der Reihenfolge früherer Aufrufe abhängt — und `docs/42` hält
 * für P5b gleich zwei Fehler dieser Bauart fest: *Eine Reihenfolge, die erst
 * beim Ausführen entsteht, kann beim Einreihen niemand kennen.* Was hier
 * ankommt, ist die Datei, wie sie hinterher aussehen soll.
 *
 * ## Die Landmine, und warum der Ablauf nicht verhandelbar ist
 *
 * | | |
 * |---|---|
 * | kaputte Datei + **Reload** | Der Server bedient weiter und behält die alten Regeln; `pg_hba_file_rules` nennt den Fehler mit Zeilennummer (M16) |
 * | kaputte Datei + **Neustart** | `FATAL: could not load pg_hba.conf` — der Cluster kommt nicht hoch (M17) |
 *
 * Eine falsche Zeile ist beim Schreiben unsichtbar und wochenlang folgenlos.
 * Sie zündet beim nächsten Paketupdate oder Reboot: alle Kunden ohne
 * Datenbank, und die Ursache ist eine Datei von vor einem Monat. Deshalb die
 * fünf Schritte aus `docs/38 §14.2`, und der vierte ist keine Meldung, sondern
 * ein Rückweg — **zurückrollen und nicht melden.** Eine Operation, die eine
 * kaputte Datei liegenlässt und darüber berichtet, hat den Server scharf
 * gemacht und ein Protokoll geschrieben.
 *
 * **Und der Rückweg konnte selbst fehlschlagen.** Er legt den Stand von
 * Schritt 1 zurück — und wenn zwischendurch {@see Hba::ensure()} aus einem
 * *anderen* Prozess die Zeile für das Zurückspielen ergänzt hat, wirft er sie
 * dabei weg. Nachgestellt am 11. August 2026, drei Wege und jeder verliert
 * etwas; die Tabelle steht bei {@see Hba}. Der Grund, warum die ganze Folge in
 * {@see Hba::locked()} läuft: **Ein Fehlerweg, der selbst fehlschlagen kann,
 * ist kein Fehlerweg.**
 *
 * ## Was diese Operation nicht tut
 *
 * **Sie schaltet nichts frei, was der Betreiber nicht freigeschaltet hat.**
 * `mode` kommt aus `srvpanel db --remote=on|off` und aus keinem Formular; das
 * Panel ruft diese Operation für Netze auf und schickt dabei `mode = 'keep'`.
 * Ein Kunde, der ein Netz einträgt, ändert damit die Horchadresse nicht und
 * löst keinen Neustart aus.
 */
final class PgRemoteAccess implements Op
{
    /** Der Dateiname im Include-Verzeichnis — dieselbe `60` wie in P5. */
    public const FILE = '60-srvpanel.conf';

    /**
     * Worauf gehorcht werden darf.
     *
     * `*` ist die Vorgabe und deckt jede Adresse, die der Rechner hat —
     * PostgreSQL geht die Familien einzeln durch und stört sich nicht daran,
     * wenn IPv6 fehlt. Das ist der Unterschied zu MariaDB, wo `::` auf einem
     * Rechner ohne IPv6 beim Start scheitert und deshalb die ausdrückliche Wahl
     * sein musste ({@see DbRemoteAccess}). `0.0.0.0` bleibt für den Betreiber,
     * der ausdrücklich nur IPv4 will.
     *
     * **Die Adresse kommt aus einer Positivliste und nicht aus dem Aufruf** —
     * in eine Konfigurationsdatei geschrieben zu werden ist genau das, wovor
     * die Positivliste des Agenten schützt.
     */
    private const ADDRESSES = ['*', '0.0.0.0'];

    /**
     * Wie viele Zeilen der Block tragen darf.
     *
     * `docs/38 §14.1` rechnet mit rund zweihundert bei hundert Abonnements. Die
     * Grenze liegt weit darüber und ist keine Kapazitätsaussage, sondern ein
     * Riegel: Was hier ankommt, geht Zeile für Zeile in eine Datei, die der
     * Server beim Start liest.
     */
    private const MAX_RULES = 4096;

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'pg.remote.access';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        /*
         * **`keep` ist der Normalfall und nicht der Sonderfall.** Ein Kunde,
         * der ein Netz einträgt, darf den Server nicht neu starten — und der
         * Unterschied zwischen „ich meine die Horchadresse nicht" und „ich
         * meine, sie soll aus sein" ist genau der zwischen einem Formular und
         * dem Schalter des Betreibers. Ein Vorgabewert `off` hätte den ersten
         * Klick eines Kunden zum Abschalten des Fernzugriffs gemacht.
         */
        $mode = Guard::enum($args['mode'] ?? 'keep', ['on', 'off', 'keep'], 'mode');
        $address = Guard::enum($args['address'] ?? '*', self::ADDRESSES, 'address');
        $rules = $this->rules($args['rules'] ?? []);

        $context->progress(5, 'PostgreSQL befragen');
        $before = $this->server->describe($context, $this->session);

        if (! $before['usable']) {
            throw AgentException::denied(
                'Auf diesem PostgreSQL arbeitet srvpanel nicht: '.($before['reason'] ?? 'unbekannter Grund')
                .' — solange das so ist, wird an seiner Erreichbarkeit nichts geändert.',
            );
        }

        $cluster = $this->server->primaryCluster($context);

        if ($cluster === null) {
            throw AgentException::execFailed('Es gibt keinen laufenden PostgreSQL-Cluster.');
        }

        $path = $this->server->hbaFile($context, $this->session);

        $context->progress(20, 'Zugangsregeln schreiben');
        $written = $this->applyHba($context, $path, $rules, $cluster);

        $listen = $mode === 'keep'
            ? ['changed' => false, 'path' => null, 'wanted' => null]
            : $this->applyListen($context, $mode === 'on' ? $address : null, $cluster);

        $context->progress(90, 'nachsehen, worauf er jetzt horcht');
        $after = $this->server->describe($context, $this->session);

        $context->progress(100, 'fertig');

        return [
            'hba_path' => $path,
            'rules' => $written['rules'],
            'rule_count' => count($written['rules']),
            'hba_changed' => $written['changed'],

            'path' => $listen['path'],
            'mode' => $mode,
            'address' => $mode === 'on' ? $address : null,
            'restarted' => $listen['changed'],

            /*
             * **Die Antwort des laufenden Servers und nicht die Absicht des
             * Aufrufers.** Ob der Include-Punkt überhaupt greift, lässt sich
             * nicht erfragen ({@see Server::includeDirectory()}) — es lässt
             * sich nur hier ablesen. Weichen `address` und `listen_addresses`
             * ab, steht es in dieser Antwort und nicht in einem
             * Vorfallsbericht drei Wochen später.
             */
            'listen_addresses' => $after['listen_addresses'],
            'remote' => $after['remote'],
            'available' => $after['available'],
        ];
    }

    /**
     * @param  list<string>  $rules
     * @param  array{version: int, name: string, port: int, running: bool, directory: string}  $cluster
     * @return array{rules: list<string>, changed: bool}
     */
    private function applyHba(Context $context, string $path, array $rules, array $cluster): array
    {
        try {
            $result = self::apply(
                $path,
                $rules,
                function () use ($context): void {
                    $this->session->execute($context, ['SELECT pg_reload_conf()']);
                },
                fn (): array => $this->errors($context),
            );
        } catch (AgentException $error) {
            $context->journal->write('pg_hba.conf zurückgenommen: der neue Block war fehlerhaft', [
                'cluster' => $cluster['version'].'/'.$cluster['name'],
                'errors' => $error->details['errors'] ?? [],
            ]);

            throw $error;
        }

        if ($result['changed']) {
            $context->journal->write('pg_hba.conf: verwalteter Block geschrieben', [
                'cluster' => $cluster['version'].'/'.$cluster['name'],
                'rules' => count($rules),
            ]);
        }

        return $result;
    }

    /**
     * Die fünf Schritte aus `docs/38 §14.2` — unter der Sperre und ohne Server.
     *
     * **Nachladen und Nachsehen kommen als Aufruf herein**, und das ist der
     * Grund, warum diese Methode `public static` ist: `PgHbaRollbackTest` fährt
     * *diesen* Ablauf gegen eine echte Datei und lässt das Nachsehen einen
     * Fehler melden. Ohne den Einstieg bliebe nur eine zweite Fassung des
     * Rückwegs im Test — und die zweite ist die, die veraltet. Die Vorlage
     * dafür sind die `Scripted…`-Doppel unter `tests/Support`.
     *
     * **Was hier *nicht* hereinkommt, ist das Schreiben.** Der Rückweg ist nur
     * dann einer, wenn er dieselbe Datei anfasst wie der Hinweg; ein Test gegen
     * einen Speicherpuffer prüfte eine Datei, die es nicht gibt.
     *
     * @param  list<string>  $rules
     * @param  callable(): void  $reload
     * @param  callable(): list<string>  $errors
     * @return array{rules: list<string>, changed: bool}
     */
    public static function apply(string $path, array $rules, callable $reload, callable $errors): array
    {
        return Hba::locked($path, static function () use ($path, $rules, $reload, $errors): array {
            // 1. Die vorhandene Datei beiseitelegen.
            $before = Hba::read($path);

            // 2. Den Block schreiben — additiv, alles ausserhalb bleibt stehen.
            $after = Hba::render($before, $rules);

            if ($after === $before) {
                /*
                 * **Kein Reload für nichts.** Ein Signal an den Server ist
                 * billig, aber nicht umsonst — und der Vergleich steht *hinter*
                 * {@see Hba::render()} und nicht davor: Ob sich etwas ändert,
                 * beantwortet die fertige Datei und nicht die Liste der Regeln.
                 * Ein Aufruf mit denselben Netzen kann trotzdem etwas ändern,
                 * wenn jemand im Block von Hand editiert hat.
                 */
                return ['rules' => Hba::managed($after), 'changed' => false];
            }

            Hba::put($path, $after);

            // 3. Neu laden. Bis hierher ist eine kaputte Datei folgenlos (M16):
            //    Der Server bedient weiter und behält die alten Regeln.
            $reload();

            // 4. Nachsehen — und bei einem Fehler zurück, nicht berichten.
            $broken = $errors();

            if ($broken !== []) {
                Hba::put($path, $before);
                $reload();

                throw AgentException::execFailed(
                    'Die Zugangsregeln wurden abgewiesen und der vorherige Stand ist wiederhergestellt: '
                    .implode('; ', $broken),
                    ['errors' => $broken],
                );
            }

            return ['rules' => Hba::managed($after), 'changed' => true];
        });
    }

    /**
     * Hälfte eins — und der Neustart nur, wenn sich etwas ändert.
     *
     * **Der Neustart ist die gefährliche Stelle**, wortgleich wie in
     * {@see DbRemoteAccess}: Kommt der Cluster mit der neuen Datei nicht hoch,
     * ist nicht nur der Fernzugriff aus, sondern jede Kundendatenbank. Der
     * vorherige Stand der Datei wird deshalb gemerkt und bei einem
     * gescheiterten Neustart wiederhergestellt — mit einem zweiten Versuch
     * hinterher, damit der Server nicht mit einer Datei steht, die niemand
     * mehr will.
     *
     * @param  array{version: int, name: string, port: int, running: bool, directory: string}  $cluster
     * @return array{changed: bool, path: string, wanted: string|null}
     */
    private function applyListen(Context $context, ?string $address, array $cluster): array
    {
        $directory = $this->server->includeDirectory($context, $this->session);
        $file = $directory.'/'.self::FILE;
        $before = is_file($file) ? (string) file_get_contents($file) : null;
        $wanted = $address === null ? null : self::content($address);

        if ($wanted === $before) {
            return ['changed' => false, 'path' => $file, 'wanted' => $address];
        }

        $context->progress(60, $address === null ? 'Horchadresse zurücknehmen' : 'Horchadresse setzen');
        $this->write($file, $wanted);

        $context->progress(70, 'Cluster neu starten');
        $restart = $this->restart($context, $cluster);

        if (! $restart->successful()) {
            $this->write($file, $before);
            $this->restart($context, $cluster);

            throw AgentException::execFailed(
                'Der Neustart ist gescheitert, die vorherige Einstellung ist wiederhergestellt: '
                .$restart->message(),
            );
        }

        return ['changed' => true, 'path' => $file, 'wanted' => $address];
    }

    /** Die Datei, wie sie aussieht — als reine Funktion, damit ein Test sie ohne Server liest. */
    public static function content(string $address): string
    {
        return "# Von srvpanel verwaltet (srvpanel db --remote). Änderungen hier werden\n"
            ."# beim nächsten Lauf überschrieben; die Datei verschwindet mit --remote=off.\n"
            ."listen_addresses = '".$address."'\n";
    }

    /**
     * Die Zeilen des Blocks, aus dem Aufruf.
     *
     * **Gebaut und nicht durchgereicht.** Was ankommt, sind Datenbank, Rolle
     * und Netz; die Zeile daraus schreibt {@see Hba::rule()}. Käme sie fertig
     * aus dem Panel, wäre der Agent die Stelle, die beliebigen Text in eine
     * Datei schreibt, die der Server beim Start liest — genau das, was die
     * erste Grenze aus `CLAUDE.md` ausschliesst.
     *
     * **Die Namen gehen durch {@see Names::existing()}**, also durch dieselbe
     * Prüfung wie überall sonst: Ein Leerzeichen darin machte aus einer Zeile
     * zwei Felder, und aus `host db rolle netz methode` würde etwas, das
     * PostgreSQL anders liest, als es aussieht.
     *
     * @return list<string>
     */
    private function rules(mixed $value): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('rules muss eine Liste sein.');
        }

        if (count($value) > self::MAX_RULES) {
            throw AgentException::badRequest(sprintf(
                'Zu viele Zugangsregeln in einem Aufruf: %d, erlaubt sind %d.',
                count($value),
                self::MAX_RULES,
            ));
        }

        $lines = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Jede Regel ist ein Objekt aus database, role und cidr.');
            }

            $lines[] = Hba::rule(
                Names::existing($entry['database'] ?? null, 'database'),
                Names::existing($entry['role'] ?? null, 'role'),
                Hba::cidr($entry['cidr'] ?? null),
            );
        }

        /*
         * **Sortiert und einmalig.** Zwei gleiche Zeilen sind für PostgreSQL
         * kein Fehler — die zweite kommt nie zum Zug —, aber sie machen aus
         * jedem Vergleich „hat sich etwas geändert" eine Zufallsfrage. Und die
         * Reihenfolge ist hier ohne Wirkung: Alle Zeilen tragen dieselbe
         * Methode, es gibt keine, die eine andere verdeckt.
         */
        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    /**
     * Die fehlerhaften Zeilen, mit ihrer Zeilennummer.
     *
     * **`pg_hba_file_rules` liest die Datei und nicht den Server** — gemessen
     * am 11. August 2026: Der Fehler stand dort, *bevor* irgendetwas neu
     * geladen war. Das ist für Schritt 4 genau richtig, und es gehört gesagt,
     * damit niemand die Abfrage später als Beleg dafür liest, dass der Server
     * die Regeln übernommen hat. Sie belegt, dass die **Datei** trägt — und
     * das ist die Frage, an der der nächste Neustart hängt.
     *
     * @return list<string>
     */
    private function errors(Context $context): array
    {
        $rows = $this->session->query(
            $context,
            'SELECT line_number, error FROM pg_hba_file_rules WHERE error IS NOT NULL ORDER BY line_number',
        );

        return array_map(
            static fn (array $row): string => sprintf('Zeile %s: %s', $row[0] ?? '?', $row[1] ?? 'ohne Grund'),
            $rows,
        );
    }

    /**
     * @param  array{version: int, name: string, port: int, running: bool, directory: string}  $cluster
     */
    private function restart(Context $context, array $cluster): Result
    {
        // `pg_ctlcluster` und nicht `systemctl`, aus demselben Grund wie in
        // {@see \SrvPanel\Agent\Pg\Clusters::start()}: Der Unitname hängt an
        // Fassung und Clusternamen, und ihn zusammenzusetzen wäre genau das,
        // was die Positivliste verhindern soll.
        return $context->runner->run(
            'pg_ctlcluster',
            [(string) $cluster['version'], $cluster['name'], 'restart'],
            180,
        );
    }

    /**
     * Den Inhalt setzen — oder die Datei entfernen, wenn er `null` ist.
     *
     * Beides an einer Stelle, weil der Rückweg oben denselben Aufruf braucht:
     * Der vorherige Zustand ist entweder ein Text oder „es gab sie nicht", und
     * eine zweite Fassung dieser Fallunterscheidung wäre die, die beim
     * Zurückrollen falsch ist. Wörtlich {@see DbRemoteAccess::apply()}.
     */
    private function write(string $file, ?string $content): void
    {
        if ($content === null) {
            if (is_file($file) && ! @unlink($file)) {
                throw AgentException::execFailed('Die Include-Datei liess sich nicht entfernen: '.$file);
            }

            return;
        }

        if (@file_put_contents($file, $content) === false) {
            throw AgentException::execFailed('Die Include-Datei liess sich nicht schreiben: '.$file);
        }

        // Lesbar für alle, wie die Nachbardateien: Sie enthält keine
        // Geheimnisse, und PostgreSQL liest sie nicht als root.
        @chmod($file, 0o644);
    }
}
