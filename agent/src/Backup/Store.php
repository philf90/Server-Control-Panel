<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Backup;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Wo eine Sicherung liegt — und warum nicht dort, wo der Kunde sie sieht.
 *
 * ## Der Ablageort
 *
 * Unter {@see self::ROOT}, **nicht** unter `/var/www/vhosts/<abo>/`. Dieselbe
 * Entscheidung wie bei {@see Dump} und aus demselben Grund,
 * plus einem zweiten, den P8 dazubringt: Eine Sicherung enthält den **ganzen**
 * Baum des Kunden. Läge sie darin, sicherte die nächste Sicherung die vorige
 * mit — und das Verzeichnis verdoppelte seine Grösse mit jedem Lauf.
 *
 * **Und sie kann ein Geheimnis tragen.** Der private Schlüssel eines
 * *hochgeladenen* Zertifikats steht nirgends sonst (`certificates` führt
 * `storage_name` und kein Schlüsselmaterial, `docs/117 §4`). Eine Sicherung im
 * Kundenraum wäre damit ein privater Schlüssel im Chroot des SFTP-Zugangs.
 *
 * ## Warum `root:srvpanel` und nicht dem Kunden
 *
 * **Gemessen und nicht übernommen** (`docs/116` M2): Was eine Datei von der
 * Quota fernhält, ist ihr **Eigentümer** und nicht ihr Ablageort.
 * `SubscriptionUsage` liest `repquota` je UID über das ganze Dateisystem, und
 * `/var/lib/srvpanel` und `/var/www/vhosts` liegen im Grundfall auf demselben.
 * Eine Sicherung, die dem Kunden gehörte, zählte gegen sein Kontingent, wo
 * immer sie liegt — und ein Kunde, dessen Kontingent von seinen eigenen
 * Sicherungen vollläuft, kann sich nicht mehr sichern.
 *
 * > **Ein Ablageort ausserhalb des Kundenverzeichnisses hält eine Datei von der
 * > Quota nur fern, solange sie jemand anderem gehört.**
 *
 * ## Der Name des Verzeichnisses ist englisch
 *
 * `backups` und nicht `sicherungen`. Der Plan schrieb zuerst das zweite;
 * ausgezählt am 16. September 2026 sind **23 von 23** Pfaden unter
 * `/var/lib/srvpanel` und `/etc/srvpanel` englisch. Ein Pfad ist ein
 * Bezeichner (`docs/19 §4a`), deutsch ist, was auf dem Bildschirm steht.
 */
final class Store
{
    /** Die Wurzel aller Sicherungen. Steht hier und kommt nicht von aussen. */
    public const ROOT = '/var/lib/srvpanel/backups';

    /**
     * Die Gruppe, die lesen darf — der Systembenutzer des Panels.
     *
     * Nicht der des Abonnements. Ein Kunde lädt seine Sicherung über das Panel
     * herunter und findet sie nicht über SFTP im Vorbeigehen; sie liegt
     * ausserhalb seines Chroots, und das ist Absicht.
     */
    public const GROUP = 'srvpanel';

    /**
     * Die Datei: lesbar für die Gruppe, für sonst niemanden.
     *
     * Der Agent schreibt sie als root, das Panel liest sie über die Gruppe.
     * Ein Archiv von mehreren Gigabyte durch den Unix-Socket zurückzureichen
     * wäre der Weg, auf dem der Agent den Speicher des Servers füllt —
     * derselbe Grund wie bei den Dumps seit P5.
     */
    public const FILE_MODE = 0640;

    /**
     * Das Verzeichnis: **durchsuchbar** für die Gruppe, nicht auflistbar.
     *
     * `0710` und nicht `0750`, und die Begründung ist eine bezahlte: Am
     * 8. August 2026 antwortete das Panel auf eine fertige Sicherung mit 404,
     * weil `Db\Dump` hier `0750` mit der Gruppe `root` trug. Unter Unix öffnet
     * man eine Datei über ihren Pfad — ohne `x` auf **jedem** Verzeichnis
     * darüber nützt das `r` an der Datei nichts.
     *
     * `--x` heisst hingehen, wenn man den Namen kennt; `ls` bleibt verwehrt.
     * Wer eine Sicherung herunterlädt, kennt ihren Namen aus dem Bestand.
     */
    public const DIRECTORY_MODE = 0710;

    /**
     * Woran ein Zip zu erkennen ist.
     *
     * `PK\x03\x04` steht am Anfang jedes lokalen Dateikopfs. Mehr wird hier
     * nicht geprüft: Ob das Archiv vollständig ist, beantwortet
     * `backup.verify` durch Öffnen, und ein Agent, der ein Zip von Hand
     * zerlegt, baut einen Parser, den niemand geprüft hat.
     *
     * **Ein leeres Archiv fängt anders an** (`PK\x05\x06`, das End-of-Central-
     * Directory allein) — das ist keine Sicherung, sondern eine leere Hülle,
     * und sie fällt hier zu Recht durch.
     */
    public const MAGIC = "PK\x03\x04";

    /** Der Ablageort einer Sicherung — gebaut, nicht entgegengenommen. */
    public static function path(string $subscription, string $storageName): string
    {
        return self::directory($subscription).'/'.self::storageName($storageName).'.zip';
    }

    /** Das Verzeichnis eines Abonnements unterhalb der Wurzel. */
    public static function directory(string $subscription): string
    {
        return self::ROOT.'/'.SubscriptionProvision::subscriptionName($subscription);
    }

    /**
     * Der Name einer Ablage — dieselbe enge Positivliste wie bei den Dumps.
     *
     * Kleinbuchstaben, Ziffern, Unterstrich, Bindestrich. Kein Punkt (er trennt
     * die Endung), kein Schrägstrich, kein `..`. Sie steht hier ein zweites Mal
     * und nicht als Aufruf von `Db\Dump::storageName()`: Die beiden Ablagen
     * haben denselben Ausdruck und nicht dieselbe Regel — änderte eine ihre
     * Form, dürfte die andere nicht mitwandern.
     */
    public static function storageName(string $value): string
    {
        $muster = sprintf('/^[a-z0-9][a-z0-9_\-]{0,%d}$/D', self::MAX_NAME - 1);

        if (! preg_match($muster, $value)) {
            throw AgentException::badRequest('Unzulässiger Name für eine Sicherung.', ['name' => $value]);
        }

        return $value;
    }

    /**
     * Wie lang der Name einer Ablage höchstens sein darf.
     *
     * **Sie steht als Zahl da, weil das Panel sie braucht.** Es baut den Namen
     * (`<abo>-<Ymd-His>-<8 hex>`) und muss wissen, wie viel Platz dem
     * Abonnementnamen bleibt; bis zum 16. September 2026 stand dort eine
     * gegriffene 60, und die hat nie etwas begrenzt — 63 Zeichen plus 25 für
     * Zeitstempel und Zufallsteil sind 88 und damit unter dieser Grenze.
     *
     * > **Eine Zahl in einer Erwartung, die man nicht gezählt hat, ist eine
     * > Vermutung mit Anspruch.**
     *
     * Gefunden hat es der volle Bruchlauf: Der Eingriff, der die Kürzung
     * entfernte, blieb grün, weil es nichts zu kürzen gab.
     *
     * > **Ein Eingriff, der einen Zustand herstellt, den der Prüfling ohnehin
     * > gleich beantwortet, misst die Regel nicht — er misst, dass sie
     * > unempfindlich ist.**
     */
    public const MAX_NAME = 96;

    /**
     * Das Verzeichnis eines Abonnements anlegen — `root:srvpanel 0710`.
     *
     * **Beide Ebenen, und bei jedem Lauf.** Ein `x` auf der einen nützt nichts,
     * wenn es auf der anderen fehlt; der Pfad wird ganz durchlaufen. Und
     * gesetzt wird nicht nur beim Anlegen, damit eine Installation mit einem
     * alten Modus sich mit der nächsten Sicherung selbst berichtigt.
     */
    public static function prepare(string $subscription): string
    {
        $directory = self::directory($subscription);

        if (! is_dir(self::ROOT) && ! @mkdir(self::ROOT, self::DIRECTORY_MODE, true) && ! is_dir(self::ROOT)) {
            throw AgentException::execFailed('Die Wurzel der Sicherungen liess sich nicht anlegen.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, self::DIRECTORY_MODE, true) && ! is_dir($directory)) {
            throw AgentException::execFailed('Das Verzeichnis der Sicherungen liess sich nicht anlegen.', [
                'path' => $directory,
            ]);
        }

        // Eine Gruppe, die es nicht gibt, ist kein Grund zum Abbruch — dieselbe
        // Vorsicht wie in `Db\Dump::prepare()`. Ohne sie bleibt das Verzeichnis
        // root allein: enger als vorgesehen, nicht weiter.
        $group = posix_getgrnam(self::GROUP) !== false;

        foreach ([self::ROOT, $directory] as $path) {
            chown($path, 'root');

            if ($group) {
                chgrp($path, self::GROUP);
            }

            // Nach `chown`/`chgrp` und nicht davor: Beide löschen unter Linux
            // die setuid- und setgid-Bits, ein `chmod` davor wäre halb
            // wirkungslos. Hier stehen sie nicht — aber die Reihenfolge ist
            // die, die immer stimmt.
            chmod($path, self::DIRECTORY_MODE);
        }

        return $directory;
    }

    /**
     * Die fertige Sicherung dem Panel übergeben — `root:srvpanel 0640`.
     *
     * Getrennt von {@see self::prepare()}, weil sie zu verschiedenen
     * Zeitpunkten gehören: Das Verzeichnis entsteht vor dem Packen, die Rechte
     * an der Datei danach. Ein `chmod` auf eine Datei, die noch wächst, wäre
     * eine Zusage über einen Zustand, den es noch nicht gibt.
     */
    public static function handOver(string $path): void
    {
        chown($path, 'root');

        if (posix_getgrnam(self::GROUP) !== false) {
            chgrp($path, self::GROUP);
        }

        chmod($path, self::FILE_MODE);
    }

    /**
     * Die ersten Bytes — sonst ist es kein Zip, wie es auch heisse.
     *
     * Dieselbe Frage wie `Db\Dump::requireGzip()`, nur für das andere Format.
     * Sie steht hier, weil eine hochgeladene Datei diesen Weg nimmt, bevor
     * irgendetwas sie öffnet: Eine Fehlermeldung über ein kaputtes Archiv ist
     * lesbarer als eine über einen fehlenden Eintrag darin.
     */
    public static function requireZip(string $path): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw AgentException::execFailed('Die Sicherung ist nicht lesbar: '.$path);
        }

        $head = (string) fread($handle, 4);
        fclose($handle);

        if ($head !== self::MAGIC) {
            throw AgentException::badRequest(
                'Das ist keine Sicherung, wie dieses Panel sie schreibt (.zip) — die Endung allein genügt nicht.',
            );
        }
    }

    /**
     * Das leere Verzeichnis eines Abonnements wieder entfernen.
     *
     * **Das ist der Weg zurück, den `docs/35` verlangt.** Wer etwas anlegt,
     * das auf der Platte bleibt, baut ihn mit — sonst findet ihn Jahre später
     * eine Datenmigration, und dann liegen dort die Verzeichnisse jedes je
     * zurückgebauten Abonnements.
     *
     * ## `rmdir` und nicht `removeTree`, und das ist der Kern
     *
     * Hier stand {@see Filesystem::removeTree()} — ein Abtragen des **Baums**,
     * gedacht für einen Rückbau, den es nie gab: `backups.subscription_id`
     * steht auf `nullOnDelete`, weil die Sicherung ihr Abonnement überleben
     * soll, und `Backups.php` schreibt ausdrücklich hin, dass ein Aufrufer beim
     * Rückbau genau das Merkmal zerstörte, für das es Schritt 10 gibt.
     *
     * Seit dem 17. September 2026 hat dieser Griff einen Aufrufer: Das Panel
     * ruft ihn, nachdem die **letzte** Zeile eines zurückgebauten Abonnements
     * entfernt wurde. Und für diesen Aufrufer wäre das Abtragen eines Baums
     * falsch — was dort noch liegt, ist eine Datei **ohne Zeile**, und genau
     * die meldet die Bestandsdiagnose als `orphan`. Ein `removeTree` nähme sie
     * wortlos mit.
     *
     * > **Ein Griff, der mehr kann als sein einziger Aufrufer braucht, nimmt
     * > irgendwann das mit, wovon der Befund daneben handelt.**
     *
     * `rmdir(2)` kann das nicht: Es scheitert an einem Verzeichnis, in dem noch
     * etwas liegt. Der Griff ist damit **selbstbegrenzend** — er kann keine
     * Daten zerstören, und deshalb darf ihn das Panel ohne Rückfrage gehen.
     *
     * **Ein nicht leeres Verzeichnis ist kein Fehlschlag**, sondern
     * {@see Filesystem::NOT_EMPTY}: Es ist der Zustand, in dem etwas anderes zu
     * tun ist, und das Melden davon ist die Aufgabe der Diagnose und nicht
     * dieses Aufrufs.
     *
     * **Hier stand `bool`, und das war Befund 5 des Nachlaufs zu P8**
     * (`docs/121 §9`): `false` deckte drei Fälle, und die Meldung daneben
     * lautete für alle drei „nichts zu entfernen". Für ein Verzeichnis mit
     * einer Datei darin ist der Satz falsch — es *gab* etwas zu entfernen.
     *
     * Die Schranken bleiben wie bei `subscription.remove`: keinem Symlink
     * folgen, und der aufgelöste Pfad muss derselbe sein. **Der Symlink wirft
     * jetzt**, statt still zu verneinen: Er ist eine Weigerung und kein
     * Ergebnis, und er stand als einziger der drei Fälle in einer Zeile mit dem
     * `realpath`-Abgleich, der seit jeher wirft.
     *
     * > **Ein Griff, der sich weigert, und einer, der nichts zu tun findet,
     * > geben dieselbe Antwort — und nur der erste ist eine Auskunft, die
     * > jemand braucht.**
     *
     * **`is_link` vor `is_dir`**, und die Reihenfolge trägt: Ein Verweis auf
     * eine Datei liesse `is_dir()` falsch werden, und der Fall käme als
     * „gibt es nicht" heraus — also wieder als der harmlose.
     *
     * @return Filesystem::REMOVED|Filesystem::ABSENT|Filesystem::NOT_EMPTY
     */
    public static function removeDirectory(string $subscription): string
    {
        $directory = self::directory($subscription);

        if (is_link($directory)) {
            throw AgentException::denied('Der Ablageort ist ein Verweis — es wird nichts entfernt.');
        }

        if (! is_dir($directory)) {
            return Filesystem::ABSENT;
        }

        if (realpath($directory) !== $directory) {
            throw AgentException::denied('Der aufgelöste Pfad weicht ab — es wird nichts entfernt.');
        }

        return @rmdir($directory) ? Filesystem::REMOVED : Filesystem::NOT_EMPTY;
    }
}
