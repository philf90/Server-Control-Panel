<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

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

        $context->progress(50, 'Verzeichnisse entfernen');
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
            'account_removed' => $account,
            'orphans' => $orphans,
        ];
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

        $this->removeTree($root);

        return true;
    }

    /**
     * Rekursiv entfernen, ohne je einem Symlink zu folgen.
     *
     * Der Kunde besitzt `httpdocs` und kann darin `foo -> /etc` anlegen.
     * `is_link` vor dem Abstieg entscheidet: Ein Verweis wird entfernt, ein
     * Verzeichnis betreten.
     *
     * **Was das nicht abdeckt:** Zwischen der Prüfung und dem Abstieg liegt
     * ein Zeitfenster, in dem ein laufender Prozess des Abonnements ein
     * Verzeichnis durch einen Verweis ersetzen könnte. Sauber schliessen liesse
     * sich das nur mit `openat(O_NOFOLLOW)`, und das gibt PHP nicht heraus.
     * Deshalb steht `stopProcesses()` vorher: Wenn hier gelöscht wird, läuft
     * kein Prozess mehr, der das Fenster nutzen könnte.
     */
    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_link($child) || ! is_dir($child)) {
                @unlink($child);

                continue;
            }

            $this->removeTree($child);
        }

        @rmdir($path);
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
                throw new AgentException(
                    AgentException::FAILED,
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
