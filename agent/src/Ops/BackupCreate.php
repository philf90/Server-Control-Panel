<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\CertificateName;
use SrvPanel\Agent\Acme\Store as AcmeStore;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Backup\Packer;
use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Op;
use Throwable;
use ZipArchive;

/**
 * Ein Abonnement sichern — Dateien, Verzeichnis und die schon erzeugten Dumps.
 *
 * ## Was diese Operation nicht tut: Datenbanken auslesen
 *
 * `docs/117 §6` Schritt 4 sagt es wörtlich — **kein neuer Weg**. `db.dump.create`
 * und `pg.dump.create` gibt es seit P5 und P5b; sie kennen ihre Systeme, ihre
 * Platzprüfung und ihre Zeitgrenzen. Diese Operation **legt ihre Ausgabe hinein**
 * und macht sie nicht noch einmal.
 *
 * Die Reihenfolge stellt das Panel her: erst je Datenbank ein Dump, dann diese
 * Operation mit den Ablagenamen. Das ist dieselbe Bauform wie überall hier —
 * `agent/src/Registry.php` hat keine Operation, die eine andere ruft.
 *
 * **Ein benannter Dump, der fehlt, bricht ab.** Ihn zu überspringen hiesse, eine
 * Sicherung ohne Datenbanken auszugeben, die von einer vollständigen nicht zu
 * unterscheiden ist — und das fiele erst dem auf, der sie zurückspielt.
 *
 * > **Ein Archiv, das stillschweigend weniger enthält, ist schlimmer als
 * > keines: Es sieht aus wie eines.**
 *
 * ## Warum das Verzeichnis zuletzt geschrieben wird
 *
 * Es zählt auf, was drin ist — also kann es erst entstehen, wenn alles drin ist.
 * `Packer::packTree()` schliesst das Archiv, diese Operation öffnet es noch
 * einmal für zwei kleine Einträge. Das kostet einen Öffnungsvorgang und erspart
 * dem Packer ein Wissen über Dumps, das er sonst tragen müsste.
 *
 * ## Und warum die Datei erst am Ende ihre Rechte bekommt
 *
 * Bis das Verzeichnis drin ist, ist die Sicherung keine. `Store::handOver()`
 * steht deshalb hinter dem letzten `close()` — davor gehört sie niemandem, und
 * ein Abbruch nimmt sie mit.
 */
final class BackupCreate implements Op
{
    /**
     * Die Datenbanksysteme, die in einer Sicherung vorkommen dürfen.
     *
     * **Sie stand als Literal in der Prüfung, und sie war falsch.** Bis zum
     * 17. September 2026 hiess der zweite Wert hier `postgresql`; das Panel
     * schickt `App\Enums\DatabaseEngine::Postgres`, und das ist `postgres`.
     * Jede Sicherung eines Abonnements mit einer PostgreSQL-Datenbank
     * scheiterte damit an dieser Zeile — gefunden im Abnahmelauf von P8, beim
     * allerersten Griff auf „Jetzt sichern".
     *
     * Der Wert konnte von dort gar nicht kommen, und zurücklesen könnte das
     * Panel ihn auch nicht: `RestoreLifecycle` nimmt `DatabaseEngine::tryFrom()`.
     * Die Liste war in **beide** Richtungen falsch.
     *
     * > **Ein Wert, den der Absender nicht senden und der Empfänger nicht lesen
     * > kann, ist keine Positivliste mit einem Tippfehler — er ist eine Wand.**
     *
     * Der Grund für die Schreibweise steht ausgeschrieben im Kopf des Enums
     * („Warum `postgres` und nicht `postgresql`") — in einer Datei, die dieser
     * Agent nicht lesen darf und nicht lesen kann. Dass die Begründung
     * dasteht, hat den Fehler nicht verhindert; sie stand auf der anderen Seite
     * der Naht.
     *
     * Die Werte bleiben hier als Konstante und werden **nicht** aus dem Panel
     * geholt — die erste Grenze (`docs/20 §4.1`) verbietet dem Agenten genau
     * das. Gehalten wird die Naht stattdessen von aussen:
     * `BackupEngineSeamTest` misst an der **Wirkung**, dass jeder Fall des
     * Enums durch {@see self::dumps()} kommt und die alte Schreibweise nicht.
     *
     * @var list<string>
     */
    public const ENGINES = ['mariadb', 'postgres'];

    /**
     * Wie viel freier Platz mindestens übrig bleiben muss.
     *
     * Dieselbe Zahl und derselbe Grund wie in {@see DbDumpCreate}: Ein
     * Dateisystem, das auf das letzte Byte vollläuft, nimmt alles mit, was
     * gerade schreibt — auch die Protokolle, in denen der Grund stünde.
     */
    private const RESERVE_BYTES = 512 * 1024 * 1024;

    public static function name(): string
    {
        return 'backup.create';
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
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $storage = Store::storageName(is_string($args['storage'] ?? null) ? $args['storage'] : '');
        $panel = Manifest::panelVersion($args['panel'] ?? null);
        $dumps = $this->dumps($args['dumps'] ?? []);
        $certificates = $this->certificates($args['certificates'] ?? []);
        $description = is_array($args['description'] ?? null) ? $args['description'] : [];

        // Die beiden Werte sind eine **Auskunft** für die Wiederherstellung und
        // keine Anweisung: Form A vergibt beide neu (`docs/117 §3`). Sie stehen
        // im Verzeichnis, damit die Seite danach sagen kann, was sich geändert
        // hat — ohne sie wäre die Zuordnung alt → neu nicht mehr herstellbar.
        // **`is_numeric` und nicht `is_int`.** Über den Socket reist JSON; eine
        // Nummer, die als `"1001"` ankommt — aus einer Ablage, aus einem
        // Kommando, aus einer künftigen Aufrufstelle —, wäre mit `is_int`
        // wortlos `null` geworden. Und `null` heisst hier „das Abonnement hatte
        // keinen Systembenutzer", also genau die Auskunft, aus der Form A die
        // Zuordnung alt → neu baut.
        //
        // > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite
        // > bekommen — die beiden Fälle sehen danach gleich aus.**
        $systemUser = is_numeric($args['system_user'] ?? null) ? (int) $args['system_user'] : null;
        $dbPrefix = is_string($args['db_prefix'] ?? null) && $args['db_prefix'] !== ''
            ? $args['db_prefix']
            : null;

        $context->progress(5, 'Platz prüfen');
        $this->requireSpace($subscription, $dumps);

        Store::prepare($subscription);
        $target = Store::path($subscription, $storage);

        if (file_exists($target)) {
            throw AgentException::badRequest('Eine Sicherung dieses Namens gibt es schon.', [
                'storage' => $storage,
            ]);
        }

        $context->progress(10, 'Dateien packen');

        $packed = Packer::pack(
            $subscription,
            $target,
            function (int $files, string $path) use ($context): void {
                // **Der Balken bleibt zwischen 10 und 80 stehen und rechnet
                // nicht.** Wie viele Dateien es am Ende sind, weiss vorher
                // niemand; ein Prozentwert aus einer geschätzten Gesamtzahl
                // springt zurück, sobald die Schätzung fällt. Was der Leser
                // wirklich braucht, ist die Zahl daneben.
                //
                // Die Einzahl wird am **Wert** entschieden und nicht am Wort:
                // `Packer` meldet alle 500 Dateien, aber das ist seine Zahl und
                // keine Zusage an diesen Rückruf.
                $context->progress(40, $files === 1
                    ? sprintf('1 Datei gepackt — %s', $path)
                    : sprintf('%d Dateien gepackt — %s', $files, $path));
            },
            fn (): bool => $context->abandoned(),
        );

        try {
            $context->progress(80, 'Datenbanken hineinlegen');
            $entries = $this->addDumps($target, $subscription, $dumps, $packed['entries']);

            $context->progress(85, 'Zertifikate hineinlegen');
            $entries = $this->addCertificates($target, $certificates, $entries);

            $context->progress(90, 'Verzeichnis schreiben');
            $this->addManifest($target, $subscription, $systemUser, $dbPrefix, $panel, $entries, $description, $dumps);
        } catch (Throwable $e) {
            // **`Throwable` und nicht `AgentException`, und das ist der
            // Unterschied zwischen einem Rückweg und einer Zeile, die wie
            // einer aussieht.** Ohne Verzeichnis ist das Archiv keine
            // Sicherung, sondern ein Haufen Dateien mit falschen Rechten — und
            // ein `TypeError` aus einer Bibliothek liesse es liegen, wo ein
            // `AgentException` es mitnimmt.
            //
            // > **Ein Archiv, das halb geschrieben liegenbleibt, ist schlimmer
            // > als keines: Es sieht aus wie eines.**
            @unlink($target);

            throw $e;
        }

        Store::handOver($target);

        $bytes = @filesize($target);

        $context->progress(100, 'fertig');

        return [
            'storage' => $storage,
            'files' => $packed['files'],
            'entries' => count($entries),
            'databases' => count($dumps),
            'skipped' => $packed['skipped'],
            'bytes' => $bytes === false ? 0 : $bytes,
        ];
    }

    /**
     * Die Dumps, die hineingelegt werden sollen — geprüft, bevor etwas läuft.
     *
     * Jeder trägt seinen Ablagenamen und die Angaben, aus denen die
     * Wiederherstellung die Zuordnung bauen kann. Der **Pfad** kommt aus
     * {@see Dump::path()} und niemals aus dem Aufruf: Die erste Grenze
     * (`docs/20 §4.1`) gilt hier wörtlich — eine Operation nimmt keinen Pfad
     * entgegen, sie baut ihn.
     *
     * @param  mixed  $value
     * @return list<array{storage: string, engine: string, database: string}>
     */
    private function dumps($value): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('Die Liste der Dumps ist keine Liste.');
        }

        $dumps = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Ein Eintrag der Dumpliste ist kein Objekt.');
            }

            $engine = is_string($entry['engine'] ?? null) ? $entry['engine'] : '';

            if (! in_array($engine, self::ENGINES, true)) {
                throw AgentException::badRequest('Unbekanntes Datenbanksystem in der Dumpliste.', [
                    'engine' => $engine,
                ]);
            }

            $database = is_string($entry['database'] ?? null) ? $entry['database'] : '';

            if ($database === '') {
                throw AgentException::badRequest('Ein Dump ohne Datenbanknamen.');
            }

            $dumps[] = [
                'storage' => Dump::storageName(is_string($entry['storage'] ?? null) ? $entry['storage'] : ''),
                'engine' => $engine,
                'database' => $database,
            ];
        }

        return $dumps;
    }

    /**
     * Die Namen der Zertifikate, deren Material mit hineingeht.
     *
     * **Nur Namen und keine Pfade.** Wo ein Zertifikat liegt, weiss
     * `Acme\Store`; käme der Pfad von aussen, wäre das genau die Anweisung, die
     * dieser Agent nicht entgegennimmt (`docs/20 §4.1`). Geprüft wird der Name
     * mit demselben Ausdruck, mit dem er angelegt wurde.
     *
     * **Welche Zertifikate, entscheidet das Panel** — es kennt `source` und
     * gibt nur hochgeladene her. Der Agent prüft die Form und nicht die
     * Herkunft: Er hat keine Tabelle.
     *
     * @return list<string>
     */
    private function certificates(mixed $value): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('Die Liste der Zertifikate ist keine Liste.');
        }

        $namen = [];

        foreach ($value as $eintrag) {
            if (! is_string($eintrag) || $eintrag === '') {
                throw AgentException::badRequest('Ein Eintrag der Zertifikatsliste ist kein Name.');
            }

            $namen[] = CertificateName::normalize($eintrag, 'name');
        }

        return array_values(array_unique($namen));
    }

    /**
     * Das Material hochgeladener Zertifikate in die Sicherung legen.
     *
     * Unter {@see Manifest::CERTS}, je Zertifikat ein Verzeichnis mit
     * `fullchain.pem` und `privkey.pem` — dieselbe Form wie im Ablageort, damit
     * das Zurückschreiben keine zweite Zuordnung braucht.
     *
     * **Beide Dateien oder Abbruch.** Ein `ssl_certificate` ohne
     * `ssl_certificate_key` lässt nginx nicht starten; eine Sicherung, die nur
     * die Hälfte trägt, ist von einer vollständigen nicht zu unterscheiden und
     * fällt erst beim Zurückspielen auf — also dann, wenn jemand darauf wartet.
     * Dieselbe Entscheidung wie bei einem fehlenden Dump.
     *
     * > **Ein Archiv, das eine Datei still weglässt, ist das Problem.**
     *
     * **Der Modus kommt aus der Datei und nicht aus einer Zahl hier.** Ein
     * privater Schlüssel liegt `0600`; stünde die Zahl in dieser Methode, wäre
     * sie eine zweite Fassung dessen, was `Acme\Store` beim Schreiben setzt.
     *
     * @param  list<string>  $certificates
     * @param  list<array{path: string, kind: string, mode: string, target?: string}>  $entries
     * @return list<array{path: string, kind: string, mode: string, target?: string}>
     */
    private function addCertificates(string $target, array $certificates, array $entries): array
    {
        if ($certificates === []) {
            return $entries;
        }

        $store = new AcmeStore;
        $zip = $this->reopen($target);

        try {
            $zip->addEmptyDir(Manifest::CERTS);
            $entries[] = Manifest::entry(Manifest::CERTS, Manifest::KIND_DIRECTORY, 0700);

            foreach ($certificates as $name) {
                $zip->addEmptyDir(Manifest::CERTS.'/'.$name);
                $entries[] = Manifest::entry(Manifest::CERTS.'/'.$name, Manifest::KIND_DIRECTORY, 0700);

                foreach (['fullchain.pem' => $store->certificate($name), 'privkey.pem' => $store->key($name)] as $datei => $quelle) {
                    if (! is_file($quelle)) {
                        throw AgentException::badRequest(sprintf(
                            'Die Datei %s des Zertifikats %s liegt nicht — die Sicherung wäre ohne ihr '
                            .'Schlüsselmaterial und von einer vollständigen nicht zu unterscheiden.',
                            $datei,
                            $name,
                        ));
                    }

                    $inside = Manifest::CERTS.'/'.$name.'/'.$datei;

                    if (! $zip->addFile($quelle, $inside)) {
                        throw AgentException::execFailed('Ein Zertifikat liess sich nicht in die Sicherung legen.', [
                            'certificate' => $name,
                            'file' => $datei,
                        ]);
                    }

                    $mode = @fileperms($quelle);
                    $entries[] = Manifest::entry($inside, Manifest::KIND_FILE, $mode === false ? 0600 : $mode);
                }
            }

            if ($zip->close() !== true) {
                throw AgentException::execFailed('Die Sicherung liess sich nicht abschliessen.', ['path' => $target]);
            }
        } catch (AgentException $e) {
            @$zip->close();

            throw $e;
        }

        return $entries;
    }

    /**
     * Die Dumps in das fertige Archiv legen und ihre Einträge zurückgeben.
     *
     * Sie kommen unter {@see Manifest::DUMPS} — einem Namen, den der Packer für
     * den Kundenbaum sperrt, damit keine Kundendatei ihn überschreiben kann.
     *
     * **Ihr Modus ist der der Quelldatei und keine feste Zahl.** Ein Dump liegt
     * `root:srvpanel 0640`; stünde hier `0644`, käme er aus einer
     * Wiederherstellung lesbarer zurück, als er gesichert wurde.
     *
     * @param  list<array{storage: string, engine: string, database: string}>  $dumps
     * @param  list<array{path: string, kind: string, mode: string, target?: string}>  $entries
     * @return list<array{path: string, kind: string, mode: string, target?: string}>
     */
    private function addDumps(string $target, string $subscription, array $dumps, array $entries): array
    {
        if ($dumps === []) {
            return $entries;
        }

        $zip = $this->reopen($target);

        try {
            $zip->addEmptyDir(Manifest::DUMPS);
            $entries[] = Manifest::entry(Manifest::DUMPS, Manifest::KIND_DIRECTORY, 0700);

            foreach ($dumps as $dump) {
                $source = Dump::path($subscription, $dump['storage']);

                if (! is_file($source)) {
                    throw AgentException::badRequest(sprintf(
                        'Der Dump %s der Datenbank %s liegt nicht — die Sicherung wäre ohne ihre Datenbanken '
                        .'und von einer vollständigen nicht zu unterscheiden.',
                        $dump['storage'],
                        $dump['database'],
                    ));
                }

                $inside = Manifest::DUMPS.'/'.$dump['storage'].'.sql.gz';

                if (! $zip->addFile($source, $inside)) {
                    throw AgentException::execFailed('Ein Dump liess sich nicht in die Sicherung legen.', [
                        'storage' => $dump['storage'],
                    ]);
                }

                $mode = @fileperms($source);
                $entries[] = Manifest::entry(
                    $inside,
                    Manifest::KIND_FILE,
                    $mode === false ? Dump::FILE_MODE : $mode,
                );
            }

            if ($zip->close() !== true) {
                throw AgentException::execFailed('Die Sicherung liess sich nicht abschliessen.', ['path' => $target]);
            }
        } catch (AgentException $e) {
            @$zip->close();

            throw $e;
        }

        return $entries;
    }

    /**
     * Das Verzeichnis als letzten Eintrag schreiben.
     *
     * Es steht **nicht** in seiner eigenen Einträgeliste: Es gehört der
     * Sicherung und nicht dem Baum des Kunden, und `Backup\Unpacker`
     * nimmt es beim Auspacken ausdrücklich aus.
     *
     * @param  list<array{path: string, kind: string, mode: string, target?: string}>  $entries
     * @param  array<string,mixed>  $description
     * @param  list<array{storage: string, engine: string, database: string}>  $dumps
     */
    private function addManifest(
        string $target,
        string $subscription,
        ?int $systemUser,
        ?string $dbPrefix,
        string $panel,
        array $entries,
        array $description,
        array $dumps,
    ): void {
        /*
         * Die Dumpliste gehört in die Beschreibung und nicht in die Einträge:
         * Dort steht, *was* eine Datei ist; hier steht, *wozu* sie gehört —
         * welche Datenbank welches Systems in welcher Datei liegt. Ohne das
         * müsste die Wiederherstellung es aus Dateinamen raten.
         *
         * **Der Schlüssel heisst `dumps` und nicht `databases`.** Hier stand
         * bis zum 16. September 2026 `databases`, und das ist der Schlüssel,
         * unter dem `App\Support\Backups\Description` die **Struktur** der
         * Datenbanken ablegt: Name, Beschriftung, Zeichensatz, Sortierung. Die
         * Zuweisung hat sie wortlos überschrieben — jede Sicherung bis dahin
         * trägt statt ihrer Struktur nur die Dumpliste, und eine
         * Wiederherstellung daraus legte jede Datenbank mit der Vorgabe des
         * Servers an statt mit ihrer Sortierung.
         *
         * > **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf
         * > genau dieser Seite fort — und der Ausfall liest sich wie ein
         * > Rechteproblem.** (`docs/82` Schritt 5, dort an `can` gegen
         * > `abilities`; hier zum dritten Mal in diesem Repo.)
         */
        $description['dumps'] = $dumps;

        $json = Manifest::encode($subscription, $systemUser, $dbPrefix, $panel, $entries, $description);

        $zip = $this->reopen($target);

        if (! $zip->addFromString(Manifest::ENTRY, $json)) {
            @$zip->close();

            throw AgentException::execFailed('Das Verzeichnis liess sich nicht in die Sicherung legen.');
        }

        if ($zip->close() !== true) {
            throw AgentException::execFailed('Die Sicherung liess sich nicht abschliessen.', ['path' => $target]);
        }
    }

    /** Ein bestehendes Archiv zum Ergänzen öffnen. */
    private function reopen(string $target): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($target) !== true) {
            throw AgentException::execFailed('Die Sicherung liess sich nicht noch einmal öffnen.', [
                'path' => $target,
            ]);
        }

        return $zip;
    }

    /**
     * Genug Platz für das Archiv?
     *
     * **Geschätzt wird aus dem Baum und nicht aus einer Zahl im Kopf.**
     * `Filesystem::size()` gibt es nicht; gerechnet wird über denselben Lauf,
     * den der Packer nimmt — ohne die Verzeichnisse, die draussen bleiben, denn
     * `logs` ist der Teil, der am schnellsten wächst und gar nicht mitkommt.
     *
     * Komprimiert wird es kleiner; die Schätzung fällt deshalb in die sichere
     * Richtung. Sie ist eine Schranke gegen das Offensichtliche und keine
     * Buchhaltung — dieselbe Vorsicht wie in {@see DbDumpCreate}.
     *
     * @param  list<array{storage: string, engine: string, database: string}>  $dumps
     */
    private function requireSpace(string $subscription, array $dumps): void
    {
        $needed = Packer::estimate($subscription) + self::RESERVE_BYTES;

        foreach ($dumps as $dump) {
            $size = @filesize(Dump::path($subscription, $dump['storage']));

            if ($size !== false) {
                $needed += $size;
            }
        }

        $free = @disk_free_space(Store::ROOT) ?: @disk_free_space('/var/lib');

        if ($free === false || $free >= $needed) {
            return;
        }

        throw AgentException::execFailed(sprintf(
            'Zu wenig Platz für die Sicherung: %d MB frei, geschätzt %d MB nötig.',
            (int) ($free / 1048576),
            (int) ($needed / 1048576),
        ));
    }
}
