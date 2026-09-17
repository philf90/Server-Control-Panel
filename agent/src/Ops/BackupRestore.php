<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SrvPanel\Agent\Acme\CertificateName;
use SrvPanel\Agent\Acme\Store as AcmeStore;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Backup\Unpacker;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Op;
use ZipArchive;

/**
 * Die Dateien einer Sicherung in ein **bestehendes** Abonnement zurückholen.
 *
 * ## Was sie tut und was ausdrücklich nicht
 *
 * Sie packt den Baum aus, setzt den Eigentümer auf den Systembenutzer des
 * Ziels, stellt das Verzeichnisschema wieder her und legt die Dumps dorthin,
 * wo `db.dump.restore` sie erwartet. **Sie legt kein Abonnement an** — das tut
 * `subscription.provision`, und keine Operation dieses Agenten ruft eine
 * andere. Die Reihenfolge stellt das Panel her, wie bei `backup.create`.
 *
 * > **Kein neuer Weg** (`docs/117 §6`).
 *
 * Und sie legt **keine Datenbank an und spielt keinen Dump ein**. Beides gibt
 * es seit P5/P5b; diese Operation stellt nur die Datei bereit.
 *
 * ## Der Eigentümer wird gesetzt und nicht gelesen
 *
 * Das Archiv trägt keinen (`docs/116` M1b), und es soll auch keinen tragen: Bei
 * Form A bekommt das wiederhergestellte Abonnement eine **neue** Nummer
 * (`docs/117 §3`), und eine alte UID im Archiv wäre eine Zusage, die niemand
 * einlöst.
 *
 * > **Ein Wert, der sich beim Zurückspielen sowieso ändert, gehört nicht in die
 * > Sicherung — er gehört neu gerechnet.**
 *
 * ## Warum `lchown()` und nicht `chown()`
 *
 * **Gemessen am 16. September 2026 (`docs/117 §15` M12)**, und es ist der
 * gefährlichste Handgriff dieser Stufe:
 *
 * | Griff | der Verweis | sein Ziel |
 * |---|---|---|
 * | `chown()` | bleibt, wie er war | **bekommt den neuen Eigentümer** |
 * | `lchown()` | bekommt ihn | bleibt, wie es war |
 *
 * {@see Unpacker} legt Verweise an und prüft ihr Ziel **mit Absicht nicht** —
 * was ein Kunde in seinem eigenen Baum anlegen darf, darf eine
 * Wiederherstellung ihm zurückgeben. Ein `chown -R`, das Verweisen folgt,
 * machte daraus einen Weg nach draussen: Ein Verweis auf `/etc/shadow` im
 * Archiv, und nach der Wiederherstellung gehört die Datei dem Kunden.
 *
 * > **Ein Verweis, dessen Ziel man nicht prüft, ist harmlos, solange niemand
 * > ihm folgt — und ein rekursiver Griff folgt ihm, ohne es zu sagen.**
 *
 * Aus demselben Grund läuft der Baum **ohne** `FOLLOW_SYMLINKS`: Ein Verweis auf
 * ein Verzeichnis führte den Rundlauf sonst hinaus.
 *
 * ## Und warum das Schema danach noch einmal gesetzt wird
 *
 * `httpdocs` gehört `%u:www-data`, `logs` gehört `%u:adm`, `conf` gehört
 * `root:root` — das steht in {@see SubscriptionProvision} und nirgends sonst.
 * Ein `chown` über den ganzen Baum macht daraus dreimal `%u:%u`, und der
 * Webserver käme an das Dokumentenverzeichnis nicht mehr heran.
 *
 * > **Ein Schema, das eine Stelle kennt, wird von jedem rekursiven Griff
 * > eingeebnet — und der Schaden sieht aus wie ein Rechteproblem irgendwo
 * > anders.**
 */
final class BackupRestore implements Op
{
    /** Wie oft der Fortschritt beim Eigentümerwechsel gemeldet wird. */
    private const REPORT_EVERY = 500;

    /**
     * Wie viele misslungene Pfade die Meldung höchstens nennt.
     *
     * Eine Sicherung kann 100 000 Einträge tragen; eine Meldung mit 100 000
     * Pfaden reisst `Connection::CONTENT_MAX` und ist ausserdem unlesbar. Die
     * ersten zehn sagen, **welcher Art** das Problem ist — mehr braucht
     * niemand, um es zu suchen.
     */
    private const MAX_REPORTED = 10;

    public static function name(): string
    {
        return 'backup.restore';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{
     *     subscription: string, storage: string, user: string,
     *     files: int, links: int, directories: int,
     *     owned: int, dumps: list<string>,
     * }
     */
    public function execute(array $args, Context $context): array
    {
        // **Vier geprüfte Hälften und kein Pfad von aussen.** `from` ist das
        // Abonnement, zu dem die Sicherung gehört, `subscription` das, in das
        // sie zurückkommt — bei Form A sind die beiden Namen gleich, solange
        // der Name frei war, und verschieden, sobald er vergeben ist.
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $from = SubscriptionProvision::subscriptionName($args['from'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $storage = Store::storageName(is_string($args['storage'] ?? null) ? $args['storage'] : '');

        $archive = Store::path($from, $storage);
        Store::requireZip($archive);

        $root = SubscriptionProvision::VHOSTS.'/'.$subscription;

        /*
         * **Das Verzeichnis muss schon dastehen.** Es anzulegen wäre die halbe
         * Arbeit von `subscription.provision` in einer zweiten Fassung — ohne
         * Unix-Konto, ohne Quota, ohne das Schema. Wer hier ankommt und nichts
         * vorfindet, hat die Reihenfolge des Panels gebrochen, und das gehört
         * laut gesagt.
         */
        if (! is_dir($root)) {
            throw AgentException::badRequest(
                'Das Abonnement hat kein Verzeichnis — es muss vor der Wiederherstellung angelegt sein.',
                ['path' => $root],
            );
        }

        $context->progress(10, 'Verzeichnis der Sicherung lesen');
        $manifest = $this->manifest($archive);

        $context->progress(20, 'Dateien auspacken');
        $unpacked = Unpacker::unpack($archive, $root, $manifest['entries']);

        $context->progress(70, 'Eigentümer setzen');
        $owned = self::own($root, $user, static fn (int $n) => $context->progress(70, $n === 1
            ? '1 Eintrag übernommen'
            : sprintf('%d Einträge übernommen', $n)));

        // **Nach dem Eigentümer und nicht davor.** Der Rundlauf darüber ebnet
        // das Schema ein; stünde es vorher, wäre es danach fort.
        $context->progress(85, 'Verzeichnisschema wiederherstellen');
        SubscriptionProvision::applyTree($root, $user);

        $context->progress(92, 'Datenbanksicherungen bereitlegen');
        $dumps = $this->dumps($archive, $subscription);

        $context->progress(96, 'Zertifikate zurücklegen');
        $certificates = $this->certificates($archive);

        $context->progress(100, 'fertig');

        return [
            'subscription' => $subscription,
            'storage' => $storage,
            'user' => $user,
            'files' => $unpacked['files'],
            'links' => $unpacked['links'],
            'directories' => $unpacked['directories'],
            'owned' => $owned,
            'dumps' => $dumps,
            'certificates' => $certificates,
        ];
    }

    /**
     * Das Verzeichnis aus dem Archiv lesen.
     *
     * @return array{format: int, panel: string, created_at: string, subscription: string, system_user: int|null, db_prefix: string|null, entries: list<array{path: string, kind: string, mode: string, target?: string}>, description: array<string,mixed>}
     */
    private function manifest(string $archive): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw AgentException::badRequest('Die Sicherung liess sich nicht öffnen.', ['path' => $archive]);
        }

        try {
            $json = $zip->getFromName(Manifest::ENTRY);
        } finally {
            $zip->close();
        }

        if (! is_string($json)) {
            throw AgentException::badRequest(
                'Die Sicherung trägt kein Verzeichnis — was daraus zurückkäme, wäre ein Haufen Dateien mit falschen Rechten.',
            );
        }

        return Manifest::decode($json);
    }

    /**
     * Den Eigentümer über den ganzen Baum setzen — ohne einem Verweis zu folgen.
     *
     * Die Begründung steht im Kopf der Klasse und ist gemessen. Zwei Dinge
     * hängen daran, und beide sind still, wenn man sie falsch macht: `lchown()`
     * statt `chown()` an jedem Verweis, und der Rundlauf **ohne**
     * `FOLLOW_SYMLINKS`.
     *
     * **Öffentlich und statisch, damit ein Wächter sie messen kann.** Dieselbe
     * Teilung wie bei `Packer::pack()` gegen `Packer::packTree()`: Die Grenze
     * baut den Pfad, die Mechanik nimmt einen fertigen. Ein Wächter, der dafür
     * `/var/www/vhosts` nachstellen müsste, prüfte die Umgebung mit.
     */
    public static function own(string $root, string $user, ?callable $melden = null): int
    {
        /*
         * **Die Kennungen werden einmal aufgelöst und nicht je Eintrag.**
         *
         * Zwei Gründe, und der erste ist ein Fehler, den der eigene Wächter
         * gefangen hat: Hier stand `chgrp($pfad, $user)` — die Annahme, dass es
         * zum Benutzer eine **Gruppe gleichen Namens** gibt. Für ein Abonnement
         * stimmt sie (`subscription.provision` legt sie an), für jeden anderen
         * Namen nicht, und `@` hat den Fehlschlag verschluckt: Die Gruppe wäre
         * `root` geblieben, über den ganzen Baum, wortlos.
         *
         * > **Eine Annahme über einen Namen, die meistens stimmt, ist mit `@`
         * > davor nicht mehr von einer zu unterscheiden, die immer stimmt.**
         *
         * Gefragt wird deshalb die **primäre Gruppe des Benutzers** und nicht
         * eine gleichnamige. Der zweite Grund ist die Zahl daneben: 100 000
         * Einträge × zwei Namensauflösungen sind 200 000 Abfragen an
         * `/etc/passwd` und `/etc/group` für eine Antwort, die sich nicht
         * ändert.
         */
        $eintrag = posix_getpwnam($user);

        if ($eintrag === false) {
            throw AgentException::badRequest('Den Systembenutzer gibt es nicht.', ['user' => $user]);
        }

        $uid = (int) $eintrag['uid'];
        $gid = (int) $eintrag['gid'];

        $gezaehlt = 0;
        $misslungen = [];

        // Die Wurzel selbst gehört root (Schema), sie wird gleich noch einmal
        // gesetzt. Hier läuft nur, was darunter liegt.
        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /**
         * **Die Kennungen je Bereich des Schemas, und erst wenn einer vorkommt.**
         *
         * Hier stand nur `$uid`/`$gid` für den ganzen Baum, und das war der
         * Ausfall von Punkt 4 des Abnahmelaufs (17. September 2026): Die Dateien
         * unter `httpdocs` bekamen die primäre Gruppe des Benutzers statt
         * `www-data`, und der Webserver konnte sie nicht mehr lesen — gemessen
         * `HTTP 403` an einer echten Domain, gegen `p1139 www-data` an einem
         * unberührten Abonnement daneben.
         *
         * `httpdocs` trägt setgid, damit jede Datei des Kunden `www-data` erbt;
         * ein ausdrückliches `chgrp` über den Baum hebt genau das auf.
         * {@see SubscriptionProvision::applyTree()} holt danach das Schema
         * zurück — für die **Verzeichnisse**. Der Kopf dieser Klasse beschreibt
         * den Schaden und setzte eine Ebene zu hoch an.
         *
         * > **Eine Behebung, die eine Ebene zu hoch ansetzt, sieht aus wie die
         * > Lösung des Problems, das sie beschreibt.**
         *
         * **Aufgelöst wird träge und je Bereich einmal.** Ein Baum mit 100 000
         * Einträgen fragte sonst 100 000 Mal nach `www-data`; und ein Aufrufer,
         * dessen Baum gar keinen Bereich des Schemas enthält — der Wächter tut
         * das —, fragt nie und braucht die Gruppen nicht zu haben.
         *
         * @var array<string, array{0: int, 1: int}>
         */
        $bereiche = [];

        $kennung = static function (string $teil) use (&$bereiche, $user, $uid, $gid): array {
            if (array_key_exists($teil, $bereiche)) {
                return $bereiche[$teil];
            }

            $schema = SubscriptionProvision::area($teil, $user);

            if ($schema === null) {
                return $bereiche[$teil] = [$uid, $gid];
            }

            [$besitzer, $gruppe] = $schema;

            $konto = posix_getpwnam($besitzer);
            $sippe = posix_getgrnam($gruppe);

            if ($konto === false || $sippe === false) {
                /*
                 * **Laut und nicht ersatzweise.** Eine Wiederherstellung, die
                 * hier auf den Benutzer ausweicht, liefert genau den Zustand,
                 * der diesen Befund ausgelöst hat — und meldet Erfolg dazu.
                 */
                throw AgentException::execFailed(
                    'Das Verzeichnisschema nennt ein Konto, das es auf diesem Server nicht gibt.',
                    ['part' => $teil, 'owner' => $besitzer, 'group' => $gruppe],
                );
            }

            return $bereiche[$teil] = [(int) $konto['uid'], (int) $sippe['gid']];
        };

        $ab = strlen($root) + 1;

        foreach ($lauf as $info) {
            $pfad = $info->getPathname();

            /*
             * Der erste Pfadteil unterhalb der Wurzel entscheidet — `httpdocs`
             * für alles darunter, und für alles andere der Benutzer selbst.
             * Ein Pfad ohne Schrägstrich ist der Bereich selbst.
             */
            $rest = substr($pfad, $ab);
            $schnitt = strpos($rest, '/');
            [$eigen, $sippe] = $kennung($schnitt === false ? $rest : substr($rest, 0, $schnitt));

            $ok = $info->isLink()
                // **`lchown` und nicht `chown`** — gemessen, `docs/117 §15` M12.
                ? @lchown($pfad, $eigen) && @lchgrp($pfad, $sippe)
                : @chown($pfad, $eigen) && @chgrp($pfad, $sippe);

            if (! $ok && count($misslungen) < self::MAX_REPORTED) {
                // **Gesammelt und nicht verschluckt.** Eine Datei, die dem
                // alten Eigentümer gehören bleibt, ist für den Kunden nicht
                // lesbar — und eine Wiederherstellung, die das verschweigt,
                // sieht aus wie eine gelungene.
                $misslungen[] = $pfad;
            }

            $gezaehlt++;

            if ($melden !== null && $gezaehlt % self::REPORT_EVERY === 0) {
                $melden($gezaehlt);
            }
        }

        if ($misslungen !== []) {
            throw AgentException::execFailed(
                'Der Eigentümer liess sich nicht für jede Datei setzen — die Wiederherstellung ist unvollständig.',
                ['paths' => $misslungen],
            );
        }

        return $gezaehlt;
    }

    /**
     * Das Material hochgeladener Zertifikate zurück in den Ablageort.
     *
     * **Nicht in den Baum des Kunden**, und dafür braucht es hier keine Zeile:
     * `Manifest::CERTS` steht in {@see Manifest::RESERVED}, und
     * {@see Unpacker} überspringt reservierte Namen
     * ohnehin. Ein privater Schlüssel unter `/var/www/vhosts/<abo>/` wäre über
     * den SFTP-Zugang lesbar.
     *
     * **Geschrieben wird über `Acme\Store::write()`** und nicht mit eigenen
     * `chmod`-Zeilen: Dort steht, dass die Kette `0644` und der Schlüssel
     * `0600` trägt, und eine zweite Fassung davon wäre die, die veraltet.
     *
     * **Das Panel legt danach die Zeilen an** — ohne sie zeigte nichts auf die
     * Dateien, und der Nachtlauf meldete sie als `orphan.row / certificate`,
     * während `srvpanel tls --prune` sie entfernte.
     *
     * @return list<string> die Namen, unter denen das Material abgelegt wurde
     */
    private function certificates(string $archive): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw AgentException::badRequest('Die Sicherung liess sich nicht öffnen.', ['path' => $archive]);
        }

        $store = new AcmeStore;
        $material = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $eintrag = $zip->getNameIndex($i);

                if ($eintrag === false || ! str_starts_with($eintrag, Manifest::CERTS.'/')) {
                    continue;
                }

                $rest = substr($eintrag, strlen(Manifest::CERTS) + 1);
                $teile = explode('/', $rest);

                if (count($teile) !== 2 || ! in_array($teile[1], ['fullchain.pem', 'privkey.pem'], true)) {
                    continue;
                }

                // Über dieselbe Prüfung, die auch beim Anlegen gilt: Ein Name
                // aus einem mitgebrachten Archiv ist ein Name von aussen.
                $name = CertificateName::normalize($teile[0], 'name');

                /*
                 * **Am Stück und nicht strömend, und das ist der Unterschied
                 * zu den Dumps.** Ein Schlüssel ist ein paar Kilobyte; ein Dump
                 * ist ein paar hundert Megabyte. Wichtiger ist, dass
                 * `Acme\Store::write()` beide Dateien zusammen ablegt — mit den
                 * Rechten, die nur dort stehen (`0644` für die Kette, `0600`
                 * für den Schlüssel). Sie hier noch einmal zu setzen wäre eine
                 * zweite Fassung derselben Regel.
                 */
                $inhalt = $zip->getFromIndex($i);

                if ($inhalt === false) {
                    throw AgentException::execFailed('Ein Zertifikat liess sich nicht aus der Sicherung lesen.', [
                        'certificate' => $name,
                        'file' => $teile[1],
                    ]);
                }

                $material[$name][$teile[1]] = $inhalt;
            }
        } finally {
            $zip->close();
        }

        $namen = [];

        foreach ($material as $name => $dateien) {
            /*
             * **Beide oder keins.** Ein `ssl_certificate` ohne
             * `ssl_certificate_key` lässt nginx nicht starten; die halbe
             * Wiederherstellung wäre schlimmer als gar keine, und sie fiele
             * erst beim nächsten Neustart auf.
             */
            if (! isset($dateien['fullchain.pem'], $dateien['privkey.pem'])) {
                throw AgentException::badRequest(sprintf(
                    'Das Zertifikat %s liegt nur zur Hälfte in der Sicherung — nginx startet mit '
                    .'einer Kette ohne Schlüssel nicht.',
                    $name,
                ));
            }

            $store->write($name, $dateien['fullchain.pem'], $dateien['privkey.pem']);
            $namen[] = $name;
        }

        return $namen;
    }

    /**
     * Die Dumps aus dem Archiv in die Ablage des **Ziels** legen.
     *
     * Sie kommen dorthin, wo `db.dump.restore` sie erwartet — unter ihrem
     * ursprünglichen Ablagenamen, der durch `<name>-<Ymd-His>-<8 hex>` eindeutig
     * ist. Rechte und Eigentümer sind die von {@see Dump}: `root:srvpanel 0640`.
     * Ein Dump, der dem Kunden gehörte, wäre über SFTP lesbar.
     *
     * @return list<string> die Ablagenamen, in der Reihenfolge des Archivs
     */
    private function dumps(string $archive, string $subscription): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw AgentException::badRequest('Die Sicherung liess sich nicht öffnen.', ['path' => $archive]);
        }

        $namen = [];

        try {
            $ziel = Dump::prepare($subscription);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $eintrag = $zip->getNameIndex($i);

                if ($eintrag === false || ! str_starts_with($eintrag, Manifest::DUMPS.'/')) {
                    continue;
                }

                $datei = substr($eintrag, strlen(Manifest::DUMPS) + 1);

                if (! str_ends_with($datei, '.sql.gz')) {
                    continue;
                }

                // Über dieselbe Prüfung, die auch beim Anlegen gilt: Ein Name
                // aus einem mitgebrachten Archiv ist ein Name von aussen.
                $storage = Dump::storageName(substr($datei, 0, -strlen('.sql.gz')));

                $strom = $zip->getStreamIndex($i);

                if ($strom === false) {
                    throw AgentException::execFailed('Ein Dump liess sich nicht aus der Sicherung lesen.', [
                        'storage' => $storage,
                    ]);
                }

                $this->write($strom, $ziel.'/'.$storage.'.sql.gz');
                $namen[] = $storage;
            }
        } finally {
            $zip->close();
        }

        return $namen;
    }

    /**
     * Einen Strom in eine Datei schreiben — strömend und mit den Rechten der
     * Ablage.
     *
     * @param  resource  $strom
     */
    private function write($strom, string $ziel): void
    {
        $aus = @fopen($ziel, 'wb');

        if ($aus === false) {
            fclose($strom);

            throw AgentException::execFailed('Ein Dump liess sich nicht ablegen.', ['path' => $ziel]);
        }

        // **Strömend und nicht am Stück.** Ein Dump von mehreren hundert MB
        // ganz in den Speicher zu laden risse `MemoryMax=512M` — dieselbe
        // Messung, aus der `backup.verify` seine Form hat (`docs/117 §13` M9).
        stream_copy_to_stream($strom, $aus);

        fclose($strom);
        fclose($aus);

        @chown($ziel, 'root');
        @chgrp($ziel, Dump::GROUP);
        @chmod($ziel, Dump::FILE_MODE);
    }
}
