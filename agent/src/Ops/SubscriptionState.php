<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Sperren und Entsperren eines Abonnements — die gemeinsame Mechanik.
 *
 * **Ein Schalter, nicht viele.** Ein Abonnement zu sperren heisst nach dem
 * Plan: Webseiten aus, Zugänge aus, Daten bleiben. Der naheliegende Weg wäre,
 * jede Domain einzeln abzuschalten — aber Domains gibt es erst in P3, und ein
 * Sperrmechanismus, der bei jeder neuen Ausbaustufe eine Stelle mehr anfassen
 * muss, vergisst irgendwann eine. Dann ist ein Abonnement gesperrt und liefert
 * trotzdem aus.
 *
 * Der Schalter sitzt deshalb an der einen Stelle, durch die alles muss: dem
 * Zugriffsbit der Chroot-Wurzel. `/var/www/vhosts/<abo>` gehört `root:root`
 * und steht auf `0755` — `www-data` kommt über das x-Bit für „andere" hinein.
 * Fällt es weg (`0750`), kommt kein Webserver-Prozess mehr in das Verzeichnis,
 * unabhängig davon, wie viele Domains darunter hängen und wann sie dazukamen.
 * Der Inhalt bleibt Bit für Bit, wie er war.
 *
 * **Dazu das Konto.** `usermod --lock` macht das Passwort unbrauchbar,
 * `--expiredate 1` setzt das Konto auf abgelaufen. Beides zusammen, weil das
 * eine allein nicht reicht: Ein gesperrtes Passwort hindert niemanden, der
 * sich mit einem Schlüssel anmeldet, und ein abgelaufenes Konto ist die
 * Schranke, die SSH und SFTP tatsächlich prüfen.
 *
 * **Und die laufenden Prozesse.** Ein PHP-FPM-Prozess, der schon läuft, hat
 * sein Verzeichnis offen — das Zugriffsbit prüft der Kernel beim Öffnen und
 * nicht bei jedem Lesen. Ohne das Beenden liefe ein gesperrtes Abonnement
 * weiter, bis der Pool von sich aus recycelt.
 */
abstract class SubscriptionState implements Op
{
    public static function mutating(): bool
    {
        return true;
    }

    /** Das Zugriffsbit der Wurzel in diesem Zustand. */
    abstract protected function rootMode(): int;

    /** Die Argumente für `usermod`. */
    abstract protected function accountArgs(string $user): array;

    /** Werden laufende Prozesse beendet? */
    abstract protected function stopsProcesses(): bool;

    public function execute(array $args, Context $context): array
    {
        $name = SubscriptionProvision::subscriptionName($args['name'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);

        $root = SubscriptionProvision::VHOSTS.'/'.$name;

        if (! is_dir($root)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Das Abonnement hat kein Verzeichnis.',
                ['name' => $name, 'root' => $root],
            );
        }

        if (posix_getpwnam($user) === false) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Den Systembenutzer gibt es nicht.',
                ['user' => $user],
            );
        }

        $context->progress(25, 'Zugriff auf das Verzeichnis');
        chmod($root, $this->rootMode());

        $context->progress(55, 'Konto');
        $account = $context->stream('usermod', $this->accountArgs($user));

        if (! $account->successful()) {
            throw new AgentException(
                AgentException::FAILED,
                'Das Konto konnte nicht umgestellt werden.',
                ['user' => $user, 'stderr' => $account->stderr],
            );
        }

        $stopped = 0;

        if ($this->stopsProcesses()) {
            $context->progress(80, 'laufende Prozesse beenden');
            $stopped = $this->stopProcesses($user);
        }

        $context->progress(100, 'fertig');

        return [
            'name' => $name,
            'user' => $user,
            'root' => $root,
            'root_mode' => sprintf('%04o', $this->rootMode()),
            'processes_stopped' => $stopped,
        ];
    }

    /**
     * Laufende Prozesse des Abonnements beenden.
     *
     * Dieselbe Mechanik wie beim Rückbau, aber aus einem anderen Grund: Dort
     * geht es darum, dass `userdel` durchkommt, hier darum, dass eine Sperre
     * sofort greift und nicht erst beim nächsten Pool-Recycling.
     */
    private function stopProcesses(string $user): int
    {
        $entry = posix_getpwnam($user);

        if ($entry === false) {
            return 0;
        }

        $uid = (int) $entry['uid'];
        $stopped = 0;

        foreach (glob('/proc/[0-9]*') ?: [] as $path) {
            $stat = @stat($path.'/status');

            if ($stat === false || $stat['uid'] !== $uid) {
                continue;
            }

            $pid = (int) basename($path);

            if ($pid > 1 && $pid !== getmypid()) {
                @posix_kill($pid, SIGTERM);
                $stopped++;
            }
        }

        return $stopped;
    }
}
