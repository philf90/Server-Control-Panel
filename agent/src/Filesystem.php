<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Ops\SubscriptionRemove;

/**
 * Verzeichnisse anlegen und entfernen.
 *
 * **Der Baumlauf stand in {@see SubscriptionRemove} und steht jetzt hier.** Er
 * wird ab P3 an einer zweiten Stelle gebraucht: Wird eine Domain entfernt,
 * geht ihr Verzeichnis mit. Ein zweiter, abgeschriebener Baumlauf wäre der
 * denkbar schlechteste Ort für eine Abweichung — hier steht `unlink` als root,
 * und die eine Zeile, die beim Abschreiben verlorenginge, wäre nach aller
 * Erfahrung die mit `is_link`.
 *
 * **Und was hier bis P6 als offener Punkt stand, ist inzwischen gemessen.**
 * Der Kommentar lautete: Zwischen der Prüfung und dem Abstieg liege ein
 * Zeitfenster, in dem ein Prozess des Abonnements ein Verzeichnis durch einen
 * Verweis ersetzen könne, und sauber schliessen liesse sich das nur mit
 * `openat(O_NOFOLLOW)`, das PHP nicht herausgibt. Beide Hälften des Satzes
 * waren richtig, und die Folgerung daraus war trotzdem falsch:
 *
 * - **Das Fenster ist keine Theorie.** Gegen einen Prozess, der
 *   `renameat2(RENAME_EXCHANGE)` fährt, lasen **11 081 von 36 056** bestandenen
 *   Prüfungen ausserhalb der Grenze — einunddreissig Prozent (`docs/50 §3`).
 * - **`openat2` hätte es nicht geschlossen.** Es hält den Systemaufruf
 *   tadellos, aber PHPs Dateifunktionen nehmen Pfade und keine Deskriptoren;
 *   der Weg zurück über `/proc/self/fd/N` ist eine zweite Pfadauflösung und
 *   damit dasselbe Rennen noch einmal (`docs/50 §4`).
 *
 * Geschlossen wird es durch {@see Sandbox}: Was unterhalb eines
 * kundeneigenen Verzeichnisses liegt, wird in einem Chroot ohne Rechte
 * abgetragen, und dort kann kein Pfad etwas ausserhalb bezeichnen.
 *
 * **Die Arbeitsteilung ergibt sich aus den Rechten und nicht aus Vorsicht.**
 * Die Wurzel eines Abonnements gehört `root:root 0755` (§4.5, und das ist eine
 * Vorgabe von OpenSSH). Der Kunde kann darin nichts anlegen und nichts
 * ersetzen — also ist kein Pfadbestandteil bis dorthin vertauschbar, und root
 * darf dort arbeiten. Alles *unterhalb* eines Verzeichnisses, das dem Kunden
 * gehört, ist vertauschbar und gehört in die Sandbox.
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
     * **Das Zeitfenster zwischen `is_link` und dem Abstieg bleibt bestehen —
     * es ist nur nicht mehr gefährlich.** Innerhalb der {@see Sandbox} kann ein
     * untergeschobener Verweis nur auf etwas zeigen, das dem Kunden ohnehin
     * gehört; der schlimmste Fall ist, dass er sich beim Rückbau eigene Dateien
     * löscht, die er auch selbst hätte löschen können. Ausserhalb der Sandbox
     * darf diese Methode deshalb nur auf Bäume angewandt werden, in die kein
     * Kunde schreiben kann.
     *
     * `SandboxReachTest` hält fest, wer sie von aussen ruft.
     */
    public static function removeTree(string $path): void
    {
        // `@`: Ein Verzeichnis, das zwischen dem Abstieg und hier verschwindet,
        // ist beim Abtragen keine Ausnahme, sondern der Normalfall — und eine
        // Warnung je Fall macht aus einem Rückbau eine Protokollflut.
        foreach (@scandir($path) ?: [] as $entry) {
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
     * Protokollverzeichnis.
     *
     * **Bis P6 stand hier eine Prüfung, und die ist gemessen zu schwach.**
     * `realpath($target) !== $target` sollte den untergeschobenen Symlink
     * abfangen; gegen einen Prozess, der `renameat2(RENAME_EXCHANGE)` fährt,
     * liess sie 31 % der Zugriffe ausserhalb der Grenze laufen (`docs/50 §3`).
     * Sie steht weiter da — aber als **Vorabweisung** und nicht als Schranke:
     * Sie spart den Fork für den Fall, dass offensichtlich etwas nicht stimmt,
     * und was sie durchlässt, hält danach die {@see Sandbox}.
     *
     * > Eine Prüfung, die man behält, nachdem sie ihre Aufgabe verloren hat,
     * > muss ihre neue Aufgabe im Kommentar tragen — sonst liest der nächste
     * > sie als die alte.
     *
     * **Zwei Wege, und die Rechte entscheiden, welcher gilt.** Der Baum wird in
     * der Sandbox abgetragen, als der Kunde und im Chroot. Das Verzeichnis
     * selbst kann die Sandbox nur dann mitnehmen, wenn ihm ein kundeneigenes
     * übergeordnet ist — hängt es direkt an der Wurzel des Abonnements
     * (`root:root 0755`), fehlt dem Kunden das Schreibrecht daran. Dann nimmt
     * es root, und das ist unbedenklich: An einem Pfad, in den der Kunde nichts
     * schreiben kann, ist auch nichts zu vertauschen.
     *
     * @param  list<\Socket|resource>  $close  Was das Kind der Sandbox geerbt hat.
     * @param  Context|null  $context  Nimmt den Beleg entgegen, unter wem gelaufen wurde.
     * @return bool Wurde etwas entfernt?
     */
    public static function removeInside(string $path, string $subscriptionRoot, string $user, array $close = [], ?Context $context = null): bool
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

        // Ein Verweis, der an der Wurzel des Abonnements hängt, kann nur von
        // root stammen — der Kunde darf dort nicht schreiben. Er wird entfernt
        // und nicht verfolgt.
        if (is_link($target) && dirname($target) === $root) {
            unlink($target);

            return true;
        }

        $relative = substr($target, strlen($root));

        $removed = Sandbox::run($root, $user, static function () use ($relative): bool {
            return self::removeRelative($relative);
        }, $close, null, $ranAs);

        if ($ranAs !== null) {
            $context?->recordRanAs($ranAs);
        }

        // Was die Sandbox stehenlassen musste, weil ihr das Schreibrecht am
        // übergeordneten Verzeichnis fehlt. Nur direkt an der Wurzel, und nur
        // leer: `rmdir` weigert sich bei allem anderen, und das ist hier genau
        // die gewünschte Auskunft.
        if (dirname($target) === $root && is_dir($target) && ! is_link($target)) {
            $removed = @rmdir($target) || $removed;
        }

        return $removed === true;
    }

    /**
     * Den **Inhalt** eines Abonnements abtragen, ohne Rechte und im Chroot.
     *
     * Für den Rückbau eines ganzen Abonnements. Die Wurzel selbst bleibt
     * stehen — man kann das Verzeichnis nicht entfernen, das im Kind das `/`
     * ist, und der Kunde dürfte es ohnehin nicht: Sie gehört `root:root 0755`.
     *
     * **Was übrigbleibt, ist genau das Schema aus §4.5** — Verzeichnisse, die
     * unmittelbar an der Wurzel hängen, plus `conf/`, das root gehört. An
     * ihnen ist nichts zu vertauschen, weil der Kunde in die Wurzel nicht
     * schreiben kann; sie nimmt {@see self::removeTree()} als root.
     *
     * **Eine Voraussetzung, die P6 selbst gefährdet.** `subscription.remove`
     * beendet vorher die Prozesse des Abonnements, und darauf ruhte bis P5c
     * die Sicherheit des Baumlaufs. Ab P6 kann ein **Cronjob** dem
     * Abonnement nach dem Abschuss einen neuen Prozess verschaffen — der
     * Rückbau muss deshalb den Zeitplan entfernen, **bevor** er die Prozesse
     * beendet. Diese Methode macht die Reihenfolge weniger kritisch, sie
     * ersetzt sie nicht.
     *
     * **Gemeldet wird, was übrigbleibt, und nicht, was abgetragen wurde.** Der
     * erste Entwurf zählte die abgetragenen Einträge — und lieferte für das
     * unveränderte Schema aus §4.5 verlässlich `0`, weil kein einziges der
     * Verzeichnisse an der Wurzel dem Kunden gehört und keines von ihnen
     * verschwinden *kann*. Eine Zahl, die im Normalfall immer null ist, taugt
     * weder als Fortschritt noch als Befund.
     *
     * Der Rest ist dagegen eine Auskunft: Steht dort mehr als das Schema,
     * hat die Sandbox etwas nicht geräumt.
     *
     * @param  list<\Socket|resource>  $close
     * @param  Context|null  $context  Nimmt den Beleg entgegen, unter wem gelaufen wurde.
     * @return int Wie viele Einträge unmittelbar unterhalb der Wurzel übrig sind.
     */
    public static function purgeContents(string $subscriptionRoot, string $user, array $close = [], ?Context $context = null): int
    {
        $root = rtrim($subscriptionRoot, '/');

        if (! str_starts_with($root.'/', SubscriptionProvision::VHOSTS.'/') || $root === SubscriptionProvision::VHOSTS) {
            throw AgentException::denied('Die Wurzel des Abonnements liegt nicht unterhalb der Vhost-Wurzel.');
        }

        if (! is_dir($root) || is_link($root)) {
            return 0;
        }

        $result = Sandbox::run($root, $user, static function (): int {
            foreach (scandir('/') ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                // Der Rückgabewert interessiert hier nicht: Was dem Kunden
                // nicht gehört, bleibt stehen und wird danach von root geholt.
                // Ein Abbruch hiesse, dass ein einziges fremdes Verzeichnis den
                // ganzen Rückbau anhält.
                self::removeRelative('/'.$entry);
            }

            clearstatcache();

            return max(0, count(scandir('/') ?: []) - 2);
        }, $close, null, $ranAs);

        if ($ranAs !== null) {
            $context?->recordRanAs($ranAs);
        }

        return is_int($result) ? $result : 0;
    }

    /**
     * Der Teil, der **innerhalb** der Sandbox läuft.
     *
     * Der Pfad ist relativ zur Wurzel des Abonnements und damit im Chroot
     * absolut. Er wird hier nicht mehr geprüft — es gibt nichts mehr zu
     * prüfen: Ausserhalb liegt nichts, was er bezeichnen könnte.
     */
    private static function removeRelative(string $relative): bool
    {
        if (! file_exists($relative) && ! is_link($relative)) {
            return false;
        }

        if (is_link($relative) || ! is_dir($relative)) {
            @unlink($relative);
        } else {
            self::removeTree($relative);
        }

        // **Gemeldet wird, was der Fall ist, und nicht, was versucht wurde.**
        // Der erste Entwurf gab hier `true` zurück, sobald der Baumlauf
        // gelaufen war — und `purgeContents` meldete daraufhin vier
        // abgetragene Einträge, während alle vier noch dastanden: Das
        // abschliessende `rmdir` scheitert an der Wurzel, die dem Kunden nicht
        // gehört, und niemand sah es.
        //
        // > Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was
        // > gezählt wurde.
        clearstatcache(true, $relative);

        return ! file_exists($relative) && ! is_link($relative);
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
