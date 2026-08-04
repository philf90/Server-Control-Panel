<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;
use SrvPanel\Agent\Site;

/**
 * Baut die Systemseite eines Abonnements vollständig zurück: Prozesse,
 * Quota, Verzeichnisbaum, Systembenutzer, Gruppe.
 *
 * **Das ist die gefährlichste Operation im Projekt.** Sie löscht als root
 * einen Verzeichnisbaum. Alles, was hier steht, dient der einen Frage: Kann
 * dieser Aufruf jemals etwas anderes löschen als das Verzeichnis eines
 * Abonnements? Die Antwort ruht auf vier Schranken, und keine davon ist für
 * sich allein ausreichend gedacht:
 *
 * 1. **Der Pfad wird gebaut, nicht entgegengenommen.** Wie bei
 *    {@see SubscriptionProvision}: übergeben wird der Name, geprüft gegen
 *    dieselbe Positivliste, und `/var/www/vhosts/<name>` entsteht hier.
 * 2. **Der gebaute Pfad muss nach der Auflösung noch derselbe sein.** Ist
 *    `/var/www/vhosts` oder das Abo-Verzeichnis selbst ein Symlink, weicht
 *    `realpath` ab — und dann wird nichts gelöscht. Das fängt den Fall, dass
 *    jemand die Wurzel untergeschoben hat.
 * 3. **Die Wurzel selbst ist ausgeschlossen.** Ein leerer Name kommt nicht
 *    durch die Prüfung, aber die Bedingung steht trotzdem da: Sie kostet eine
 *    Zeile und deckt jeden künftigen Fehler in der Namensprüfung ab.
 * 4. **Beim Absteigen wird keinem Symlink gefolgt.** Der Kunde besitzt
 *    `httpdocs` und kann darin einen Verweis auf `/etc` anlegen. Verweise
 *    werden entfernt, nicht betreten.
 *
 * **Wiederholbar.** Ein Abonnement, das es nicht mehr gibt, ist der
 * gewünschte Zustand — der Aufruf meldet das und scheitert nicht. Sonst
 * hinge ein fehlgeschlagener Löschvorgang für immer, weil sein zweiter
 * Versuch an dem scheitert, was der erste schon geschafft hat.
 *
 * **Keine Sicherung.** Der Plan verlangt „löschen mit Sicherung davor"; die
 * Sicherung gehört vor diesen Aufruf und nicht hinein. Eine Operation, die
 * sichert *und* löscht, sichert im Fehlerfall vielleicht nicht und löscht
 * trotzdem. Die Reihenfolge gehört in den Vorgang, der beides auslöst.
 */
final class SubscriptionRemove implements Op
{
    /** Wie lange ein Prozess des Abonnements nach SIGTERM Zeit bekommt. */
    private const GRACE_MS = 500;

    /**
     * Die drei Orte ausserhalb des Abo-Verzeichnisses — als Vorgaben.
     *
     * Sie stehen im Konstruktor und nicht als Konstanten im Rumpf, damit der
     * Test aus §8.7 sie auf einen Sandkasten zeigen kann: „Abo löschen
     * entfernt alles restlos, geprüft durch einen Test, der hinterher das
     * Dateisystem absucht". Ein Test, der `/etc/nginx` anfasst, ist keiner,
     * der zweimal läuft. Dasselbe Muster wie bei {@see PanelVhost}, wo das
     * Ziel aus demselben Grund im Konstruktor steht.
     */
    public function __construct(
        private readonly string $confDir = Site::CONF_DIR,
        private readonly string $logrotateDir = WebLogrotate::DIRECTORY,
        private readonly string $phpRoot = PhpVersions::PHP_ROOT,
    ) {}

    public static function name(): string
    {
        return 'subscription.remove';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $name = SubscriptionProvision::subscriptionName($args['name'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);

        $root = SubscriptionProvision::VHOSTS.'/'.$name;
        $entry = posix_getpwnam($user);
        $uid = $entry === false ? null : (int) $entry['uid'];

        $context->progress(10, 'Prozesse beenden');
        $killed = $uid === null ? 0 : $this->stopProcesses($uid);

        $context->progress(30, 'Quota zurücknehmen');
        $quota = $entry === false ? false : $this->clearQuota($context, $user);

        /*
         * **Was ausserhalb des Abo-Verzeichnisses liegt, geht zuerst.**
         *
         * Bis P3 war der Rückbau vollständig, weil alles zu einem Abonnement
         * unter `/var/www/vhosts/<abo>` lag. Mit den Websites ist das nicht
         * mehr so: Der Server-Block steht in `/etc/nginx/srvpanel.d`, der
         * FPM-Pool in `/etc/php/<version>/fpm/pool.d`, die Rotation in
         * `/etc/logrotate.d`. Der Baumlauf über das Abo-Verzeichnis sieht
         * nichts davon — und §8.7 verlangt, dass „Abo löschen alles restlos
         * entfernt, geprüft durch einen Test, der hinterher das Dateisystem
         * absucht".
         *
         * Vor dem Verzeichnis, weil der Server-Block darauf zeigt: Ein nginx,
         * das zwischen beiden Schritten neu lädt, fände sonst ein `root`, das
         * es nicht mehr gibt.
         */
        $context->progress(45, 'Konfiguration entfernen');
        $configuration = $this->removeConfiguration($context, $name, $user, $root);

        $context->progress(60, 'Verzeichnisse entfernen');
        $removed = $this->removeRoot($root);

        $context->progress(80, 'Systembenutzer und Gruppe entfernen');
        $account = $this->removeAccount($context, $user, $entry !== false);

        // Die Gegenprobe zum Abnahmekriterium von P2. Sie kostet einen Durchlauf
        // über /var/www/vhosts und beantwortet die Frage, die das Kriterium
        // stellt: Bleibt etwas zurück? Wer sie erst beim hundertsten Abonnement
        // stellt, sucht danach in hundert Verzeichnissen.
        $context->progress(95, 'nachsehen, ob etwas zurückblieb');
        $orphans = $uid === null ? [] : $this->orphansOf($uid);

        $context->progress(100, 'fertig');

        return [
            'name' => $name,
            'user' => $user,
            'processes_stopped' => $killed,
            'quota_cleared' => $quota,
            'directory_removed' => $removed,
            'configuration_removed' => $configuration,
            'account_removed' => $account,
            'orphans' => $orphans,
        ];
    }

    /**
     * Server-Blöcke, FPM-Pools und die Rotation dieses Abonnements.
     *
     * **Die Server-Blöcke werden gesucht und nicht übergeben.** Das Panel
     * wüsste, welche Domains es gab; nur ist genau das die Liste, die nach
     * einem abgebrochenen Lauf unvollständig ist — und dann bliebe eine Datei
     * liegen, die auf ein Verzeichnis zeigt, das es nicht mehr gibt. Gesucht
     * wird in einem Verzeichnis, das ausschliesslich srvpanel gehört, nach dem
     * Pfad des Abonnements: Jeder erzeugte Block trägt ihn in `access_log`.
     * Das findet auch die Reste, die niemand mehr auf der Rechnung hat.
     *
     * **Die Pools stehen dagegen fest.** Ihr Dateiname enthält den
     * Systembenutzer, und der ist geprüft — dort gibt es nichts zu suchen.
     *
     * @return array<string, list<string>>
     */
    private function removeConfiguration(Context $context, string $name, string $user, string $root): array
    {
        $entfernt = ['sites' => [], 'pools' => [], 'logrotate' => []];

        foreach (glob($this->confDir.'/*.conf') ?: [] as $file) {
            $inhalt = (string) @file_get_contents($file);

            // `$root.'/'` und nicht `$root`: Ohne den Schrägstrich träfe
            // `beispiel.de` auch die Blöcke von `beispiel.de.alt`.
            if (! str_contains($inhalt, $root.'/')) {
                continue;
            }

            if (@unlink($file)) {
                $entfernt['sites'][] = $file;
            }
        }

        $versionen = [];

        foreach (PhpVersions::CATALOG as $version) {
            $pool = PhpVersions::poolFile($version, $user, $this->phpRoot);

            if (is_file($pool) && @unlink($pool)) {
                $entfernt['pools'][] = $pool;
                $versionen[] = $version;
            }
        }

        $rotation = $this->logrotateDir.'/srvpanel-'.$name;

        if (is_file($rotation) && @unlink($rotation)) {
            $entfernt['logrotate'][] = $rotation;
        }

        $this->reload($context, $versionen, $entfernt['sites'] !== []);

        return $entfernt;
    }

    /**
     * Die Dienste über den Wegfall in Kenntnis setzen.
     *
     * **Ohne Abbruch bei einem Fehlschlag.** Ein nginx, das sich nicht neu
     * laden lässt, ist ein Problem — aber kein Grund, den Rückbau mitten im
     * Lauf abzubrechen und ein halb entferntes Abonnement zu hinterlassen. Die
     * Dateien sind fort; was bleibt, ist ein Dienst mit einer veralteten
     * Konfiguration, und das sieht der Betreiber an seinem Zustand.
     *
     * @param  list<string>  $versionen
     */
    private function reload(Context $context, array $versionen, bool $nginx): void
    {
        if ($nginx) {
            $context->runner->run('systemctl', ['reload-or-restart', 'nginx.service'], 60);
        }

        foreach ($versionen as $version) {
            // Bleibt kein Pool übrig, startet PHP-FPM nicht mehr — dann wird
            // die Unit gestoppt statt neu geladen. Dieselbe Regel wie in
            // php.pool.remove, und aus demselben Grund.
            $verbleibend = glob(PhpVersions::poolDir($version, $this->phpRoot).'/srvpanel-*.conf') ?: [];

            $context->runner->run(
                'systemctl',
                $verbleibend === []
                    ? ['disable', '--now', PhpVersions::unit($version)]
                    : ['reload-or-restart', PhpVersions::unit($version)],
                60,
            );
        }
    }

    /**
     * Prozesse des Abonnements beenden.
     *
     * Ohne das schlägt `userdel` mit „user is currently used by process" fehl
     * — und zurück bliebe genau der Systembenutzer, den das Abnahmekriterium
     * ausschliesst.
     *
     * Gelesen wird /proc statt `pkill` aufzurufen: Das spart ein Programm auf
     * der Positivliste des Runners, und die Positivliste ist die Angriffsfläche
     * des Agenten. Erst SIGTERM, dann SIGKILL — ein PHP-FPM-Pool, der mitten
     * in einer Anfrage abgeschossen wird, hinterlässt eine halbe Antwort.
     */
    private function stopProcesses(int $uid): int
    {
        $pids = $this->processesOf($uid);

        if ($pids === []) {
            return 0;
        }

        foreach ($pids as $pid) {
            @posix_kill($pid, SIGTERM);
        }

        usleep(self::GRACE_MS * 1000);

        foreach ($this->processesOf($uid) as $pid) {
            @posix_kill($pid, SIGKILL);
        }

        return count($pids);
    }

    /** @return list<int> */
    private function processesOf(int $uid): array
    {
        $pids = [];

        foreach (glob('/proc/[0-9]*') ?: [] as $path) {
            $stat = @stat($path.'/status');

            if ($stat === false || $stat['uid'] !== $uid) {
                continue;
            }

            $pid = (int) basename($path);

            // Nicht sich selbst und nicht PID 1. Der Agent läuft als root,
            // das trifft die Bedingung oben nie — aber ein Tippfehler in der
            // UID-Prüfung soll nicht den Init-Prozess erwischen.
            if ($pid > 1 && $pid !== getmypid()) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    /**
     * Die Quota auf null setzen, bevor der Benutzer verschwindet.
     *
     * **Reihenfolge.** Nach `userdel` gibt es den Namen nicht mehr, und
     * `setquota -u` findet ihn nicht — der Eintrag in der Quota-Datei bliebe
     * unter der nackten UID stehen. Bekäme später ein anderes Abonnement
     * dieselbe UID, erbte es das Kontingent des gelöschten.
     */
    private function clearQuota(Context $context, string $user): bool
    {
        $device = $this->deviceFor(SubscriptionProvision::VHOSTS);

        if ($device === null) {
            return false;
        }

        return $context->stream('setquota', ['-u', $user, '0', '0', '0', '0', $device])->successful();
    }

    private function deviceFor(string $path): ?string
    {
        $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($mounts === false) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', $line) ?: [];

            if (count($parts) < 3 || ! str_starts_with($parts[0], '/')) {
                continue;
            }

            $point = stripcslashes($parts[1]);

            if ($point !== '/' && ! str_starts_with($path.'/', rtrim($point, '/').'/')) {
                continue;
            }

            if (strlen($point) > $bestLength) {
                $best = $parts[0];
                $bestLength = strlen($point);
            }
        }

        return $best;
    }

    /**
     * Die vier Schranken vor dem `rm -rf`, und dann der Baum.
     *
     * @return bool Wurde etwas entfernt?
     */
    private function removeRoot(string $root): bool
    {
        // Schranke 3: niemals die Wurzel aller Abonnements.
        if (rtrim($root, '/') === SubscriptionProvision::VHOSTS) {
            throw AgentException::denied('Die Wurzel aller Abonnements wird nicht entfernt.');
        }

        if (! file_exists($root) && ! is_link($root)) {
            return false;
        }

        // Schranke 4 für die Wurzel selbst: Ist das Abo-Verzeichnis ein
        // Symlink, wird der Verweis entfernt und nicht sein Ziel.
        if (is_link($root)) {
            unlink($root);

            return true;
        }

        if (! is_dir($root)) {
            throw AgentException::denied('Das Ziel ist kein Verzeichnis.');
        }

        // Schranke 2: Nach der Auflösung muss derselbe Pfad herauskommen. Ist
        // /var/www/vhosts selbst ein Verweis, steht hier etwas anderes — und
        // dann wird nicht gelöscht, sondern abgebrochen.
        $real = realpath($root);

        if ($real !== $root) {
            throw AgentException::denied('Der aufgelöste Pfad weicht ab — es wird nichts entfernt.');
        }

        Filesystem::removeTree($root);

        return true;
    }

    /**
     * Systembenutzer und Gruppe entfernen.
     *
     * `userdel` ohne `--remove`: Das Home ist die Chroot-Wurzel, und die ist
     * oben schon weg. `--remove` liesse userdel ein Verzeichnis suchen, das es
     * nicht mehr gibt, und mit einer Warnung enden.
     *
     * Die Gruppe muss einzeln weg. `userdel` nimmt sie nur mit, wenn sie als
     * „user private group" angelegt wurde — hier ist sie das nicht, weil
     * `useradd` mit `--no-user-group` läuft (aus gutem Grund, siehe
     * SubscriptionProvision). Ohne `groupdel` bliebe je gelöschtem Abonnement
     * eine Zeile in /etc/group stehen.
     */
    private function removeAccount(Context $context, string $user, bool $existed): bool
    {
        if ($existed) {
            $result = $context->stream('userdel', [$user]);

            if (! $result->successful()) {
                throw AgentException::execFailed(
                    'Systembenutzer konnte nicht entfernt werden.',
                    ['user' => $user, 'stderr' => $result->stderr],
                );
            }
        }

        if (posix_getgrnam($user) !== false) {
            $context->stream('groupdel', [$user]);
        }

        return $existed;
    }

    /**
     * Was unterhalb von /var/www/vhosts noch der gelöschten UID gehört.
     *
     * Nach `userdel` trägt so etwas nur noch eine nackte Zahl — und bekäme ein
     * späteres Abonnement dieselbe UID, gehörten ihm diese Dateien. Der
     * Aufrufer bekommt die Liste, damit sie im Vorgang steht statt in
     * niemandes Blickfeld.
     *
     * Gesucht wird nur unter /var/www/vhosts. Ein vollständiger Durchlauf über
     * `/` wäre bei jedem Löschen eine Minute Plattenlast für einen Fall, den
     * es nach dem Zuschnitt der Abonnements nicht geben sollte.
     *
     * @return list<string>
     */
    private function orphansOf(int $uid): array
    {
        $found = [];
        $root = SubscriptionProvision::VHOSTS;

        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                continue;
            }

            if ($file->getOwner() === $uid) {
                $found[] = $file->getPathname();
            }

            // Zwanzig genügen, um zu wissen, dass etwas nicht stimmt. Wer eine
            // vollständige Liste braucht, hat ein anderes Problem als diese
            // Meldung.
            if (count($found) >= 20) {
                break;
            }
        }

        return $found;
    }
}
