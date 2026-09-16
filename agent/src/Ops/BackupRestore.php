<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

        foreach ($lauf as $info) {
            $pfad = $info->getPathname();

            $ok = $info->isLink()
                // **`lchown` und nicht `chown`** — gemessen, `docs/117 §15` M12.
                ? @lchown($pfad, $uid) && @lchgrp($pfad, $gid)
                : @chown($pfad, $uid) && @chgrp($pfad, $gid);

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
