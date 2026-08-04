<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Ops\SubscriptionRemove;

/**
 * Verzeichnisse anlegen und entfernen — als root, und deshalb an einer Stelle.
 *
 * **Der Baumlauf stand in {@see SubscriptionRemove} und steht jetzt hier.** Er
 * wird ab P3 an einer zweiten Stelle gebraucht: Wird eine Domain entfernt,
 * geht ihr Verzeichnis mit. Ein zweiter, abgeschriebener Baumlauf wäre der
 * denkbar schlechteste Ort für eine Abweichung — hier steht `unlink` als root,
 * und die eine Zeile, die beim Abschreiben verlorenginge, wäre nach aller
 * Erfahrung die mit `is_link`.
 *
 * Der Kommentar zum Zeitfenster ist mit umgezogen, weil er zur Sache gehört
 * und nicht zur Operation.
 */
final class Filesystem
{
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
     * Deshalb werden vorher die Prozesse des Abonnements beendet: Wenn hier
     * gelöscht wird, läuft keiner mehr, der das Fenster nutzen könnte.
     */
    public static function removeTree(string $path): void
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

            self::removeTree($child);
        }

        @rmdir($path);
    }

    /**
     * Ein Verzeichnis **innerhalb** eines Abonnements entfernen.
     *
     * Für alles, was zu einer Domain gehört: ihr DocumentRoot und ihr
     * Protokollverzeichnis. Die Schranken sind dieselben wie beim Rückbau
     * eines ganzen Abonnements, nur eine Ebene tiefer:
     *
     * 1. Der Pfad muss **echt unterhalb** der Wurzel des Abonnements liegen.
     *    Gleichheit zählt nicht — sonst nähme das Entfernen einer Domain das
     *    ganze Abonnement mit.
     * 2. Er darf nach der Auflösung nicht abweichen. Ein Symlink irgendwo im
     *    Pfad hiesse, dass gelöscht würde, was jemand untergeschoben hat.
     * 3. Die Wurzel des Abonnements muss ihrerseits unterhalb von
     *    `/var/www/vhosts` liegen — die Prüfung kostet eine Zeile und deckt
     *    jeden künftigen Fehler beim Bauen des Pfades ab.
     *
     * @return bool Wurde etwas entfernt?
     */
    public static function removeInside(string $path, string $subscriptionRoot): bool
    {
        $root = rtrim($subscriptionRoot, '/');
        $target = rtrim($path, '/');

        if (! str_starts_with($root.'/', SubscriptionProvision::VHOSTS.'/')) {
            throw AgentException::denied('Die Wurzel des Abonnements liegt nicht unterhalb der Vhost-Wurzel.');
        }

        if ($target === $root || ! str_starts_with($target, $root.'/')) {
            throw AgentException::denied('Das Ziel liegt nicht innerhalb des Abonnements.');
        }

        if (! file_exists($target) && ! is_link($target)) {
            return false;
        }

        if (is_link($target)) {
            unlink($target);

            return true;
        }

        if (! is_dir($target)) {
            @unlink($target);

            return true;
        }

        if (realpath($target) !== $target) {
            throw AgentException::denied('Der aufgelöste Pfad weicht ab — es wird nichts entfernt.');
        }

        self::removeTree($target);

        return true;
    }

    /**
     * Ein Verzeichnis anlegen, das jemandem gehört.
     *
     * Wortgleich zu dem in {@see SubscriptionProvision} — samt der Nachsicht
     * gegenüber einer fehlenden Gruppe: `adm` und `www-data` gibt es auf
     * schlanken Installationen nicht immer. Das Verzeichnis gehört dann dem
     * Benutzer allein, also enger als vorgesehen und nicht weiter.
     */
    public static function directory(string $path, string $owner, string $group, int $mode): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw AgentException::execFailed('Verzeichnis konnte nicht angelegt werden.', ['path' => $path]);
        }

        $groupExists = posix_getgrnam($group) !== false;

        chown($path, $owner);
        chgrp($path, $groupExists ? $group : $owner);
        chmod($path, $mode);
    }
}
