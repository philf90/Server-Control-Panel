<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Legt die Systemseite eines Abonnements an: Gruppe, Systembenutzer,
 * Verzeichnisschema nach §4.5 und die Dateisystem-Quota.
 *
 * **Die Operation nimmt keinen Pfad entgegen — sie baut ihn.** Das ist die
 * wichtigste Entscheidung in dieser Datei. Übergeben wird der *Name* des
 * Abonnements, geprüft gegen eine Positivliste; der Pfad entsteht hier als
 * `/var/www/vhosts/<name>`. Damit gibt es kein `..`, keinen Symlink und keinen
 * absoluten Pfad, den ein Aufrufer unterschieben könnte. Eine Operation, die
 * einen Pfad annimmt und ihn danach prüft, ist eine Operation, deren Prüfung
 * irgendwann eine Lücke hat.
 *
 * **Der Benutzername wird nicht frei gewählt.** Er muss `p` gefolgt von
 * Ziffern sein. Ein Aufrufer, der sich `root`, `www-data` oder `srvpanel`
 * wünschen könnte, hätte über `useradd` einen Weg, ein bestehendes Konto zu
 * berühren — und über `setquota` einen Weg, ihm eine Quota zu setzen.
 *
 * **Warum die Wurzel root gehört und der Inhalt dem Kunden.** OpenSSH
 * verweigert ein Chroot, dessen Wurzel dem eingesperrten Benutzer gehört oder
 * für andere schreibbar ist — und zwar wortlos beim Verbindungsaufbau. Die
 * Zweiteilung aus §4.5 ist deshalb keine Vorsicht, sondern eine Vorgabe:
 * Wurzel `root:root 0755`, Inhalt `<benutzer>:<gruppe>`.
 *
 * **Der Lauf ist wiederholbar.** Ein zweiter Aufruf mit denselben Werten legt
 * nichts doppelt an und wirft nichts weg. Das ist die Voraussetzung dafür,
 * dass ein abgebrochener Vorgang wiederholt werden kann, ohne dass jemand
 * vorher von Hand aufräumt.
 */
final class SubscriptionProvision implements Op
{
    /** Die Wurzel aller Abonnements. Steht hier und kommt nicht von aussen. */
    public const VHOSTS = '/var/www/vhosts';

    /**
     * Das Verzeichnisschema aus §4.5.
     *
     * Eigentümer `null` heisst root. `%u` ist der Systembenutzer des
     * Abonnements, `%g` seine Gruppe.
     *
     * @var array<string, array{0: string, 1: string, 2: int}> Pfad => [Eigentümer, Gruppe, Rechte]
     */
    private const TREE = [
        'httpdocs' => ['%u', 'www-data', 0750],
        'logs' => ['%u', 'adm', 0750],
        'tmp' => ['%u', '%g', 0700],
        'conf' => ['root', 'root', 0755],
        '.ssh' => ['%u', '%g', 0700],

        // §5.5: Das Schema hält den Platz für Postfix/Dovecot frei, damit die
        // Mail-Stufe später nicht das Verzeichnis eines laufenden Abonnements
        // umbauen muss.
        'mail' => ['%u', '%g', 0700],
    ];

    public static function name(): string
    {
        return 'subscription.provision';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $name = self::subscriptionName($args['name'] ?? null);
        $user = self::systemUser($args['user'] ?? null);
        $quotaMb = self::quota($args['quota_mb'] ?? null);

        $root = self::VHOSTS.'/'.$name;

        $context->progress(10, 'Gruppe und Systembenutzer');
        $created = $this->account($context, $user, $root);

        $context->progress(45, 'Verzeichnisse');
        $this->tree($root, $user);

        $context->progress(75, 'Quota');
        $quota = $this->quotaApply($context, $user, $quotaMb);

        $context->progress(100, 'fertig');

        return [
            'name' => $name,
            'user' => $user,
            'root' => $root,
            'created' => $created,
            'quota' => $quota,
        ];
    }

    /**
     * Der Name des Abonnements — und damit der Verzeichnisname.
     *
     * Erlaubt sind Kleinbuchstaben, Ziffern, Punkt und Bindestrich, beginnend
     * und endend mit einem alphanumerischen Zeichen. Das deckt Domainnamen ab
     * und schliesst alles aus, was ein Pfad sein könnte: kein Schrägstrich,
     * kein Punkt am Anfang, kein `..`.
     */
    public static function subscriptionName(mixed $value): string
    {
        $name = Guard::string($value, 'name');

        if (! preg_match('/^[a-z0-9]([a-z0-9.\-]{0,61}[a-z0-9])?$/', $name)) {
            throw AgentException::badRequest('Unzulässiger Name für ein Abonnement.', ['name' => $name]);
        }

        if (str_contains($name, '..')) {
            throw AgentException::badRequest('Unzulässiger Name für ein Abonnement.', ['name' => $name]);
        }

        return $name;
    }

    /**
     * Der Systembenutzer: `p` und Ziffern, sonst nichts.
     *
     * Die enge Form ist Absicht. Ein frei gewählter Name wäre ein Weg, über
     * `useradd`/`usermod` ein bestehendes Konto zu berühren — `root`,
     * `www-data`, `srvpanel` oder das Konto eines anderen Abonnements.
     * Ausserdem bleibt die Länge unter der Grenze, ab der `useradd` auf
     * manchen Systemen abweist.
     */
    public static function systemUser(mixed $value): string
    {
        $user = Guard::string($value, 'user');

        if (! preg_match('/^p[0-9]{4,9}$/', $user)) {
            throw AgentException::badRequest(
                'Unzulässiger Systembenutzer — erwartet wird „p" und vier bis neun Ziffern.',
                ['user' => $user],
            );
        }

        return $user;
    }

    private static function quota(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 1024 * 1024 * 16) {
            throw AgentException::badRequest('quota_mb muss eine Zahl zwischen 0 und 16 TiB sein.');
        }

        return $value;
    }

    /**
     * Gruppe und Benutzer anlegen.
     *
     * `--no-user-group` und die eigene Gruppe zuerst: `useradd` legt sonst je
     * nach Distribution eine Gruppe mit demselben Namen an oder steckt den
     * Benutzer in `users` — und dann sähe jedes Abonnement die Dateien jedes
     * anderen, weil `0750` auf einer geteilten Gruppe nichts ausschliesst.
     *
     * Keine Login-Shell und kein Passwort: Der Zugang läuft über SFTP mit
     * Chroot (P6), nicht über eine Shell.
     *
     * @return bool Wurde das Konto in diesem Lauf angelegt?
     */
    private function account(Context $context, string $user, string $root): bool
    {
        if ($this->exists($user)) {
            return false;
        }

        $group = $context->stream('groupadd', ['--force', $user]);

        if (! $group->successful()) {
            throw new AgentException(
                AgentException::FAILED,
                'Gruppe konnte nicht angelegt werden.',
                ['user' => $user, 'stderr' => $group->stderr],
            );
        }

        // `--no-create-home`: Das Home ist die Chroot-Wurzel, und die muss
        // root gehören. Liesse man useradd sie anlegen, gehörte sie dem
        // Benutzer — und OpenSSH verweigerte das Chroot wortlos.
        $result = $context->stream('useradd', [
            '--gid', $user,
            '--no-user-group',
            '--home-dir', $root,
            '--no-create-home',
            '--shell', '/usr/sbin/nologin',
            '--comment', 'SrvPanel-Abonnement',
            $user,
        ]);

        if (! $result->successful()) {
            throw new AgentException(
                AgentException::FAILED,
                'Systembenutzer konnte nicht angelegt werden.',
                ['user' => $user, 'stderr' => $result->stderr],
            );
        }

        return true;
    }

    private function exists(string $user): bool
    {
        return posix_getpwnam($user) !== false;
    }

    /**
     * Das Verzeichnisschema anlegen — und bei jedem Lauf die Rechte
     * geraderücken.
     *
     * Auch für bestehende Verzeichnisse: Ein Abonnement, an dessen Rechten
     * jemand von Hand gedreht hat, kommt so beim nächsten Lauf zurück auf den
     * Stand aus §4.5. Das ist der Unterschied zwischen einem Schema, das
     * gilt, und einem, das einmal galt.
     */
    private function tree(string $root, string $user): void
    {
        $this->directory($root, 'root', 'root', 0755);

        foreach (self::TREE as $part => [$owner, $group, $mode]) {
            $this->directory(
                $root.'/'.$part,
                $owner === '%u' ? $user : $owner,
                $group === '%g' ? $user : $group,
                $mode,
            );
        }
    }

    private function directory(string $path, string $owner, string $group, int $mode): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new AgentException(
                AgentException::FAILED,
                'Verzeichnis konnte nicht angelegt werden.',
                ['path' => $path],
            );
        }

        // Eine Gruppe, die es nicht gibt, ist kein Grund zum Abbruch: `adm`
        // fehlt auf schlanken Installationen. Das Verzeichnis gehört dann dem
        // Benutzer allein — enger als vorgesehen, nicht weiter.
        $groupExists = posix_getgrnam($group) !== false;

        chown($path, $owner);
        chgrp($path, $groupExists ? $group : $owner);
        chmod($path, $mode);
    }

    /**
     * Die Dateisystem-Quota setzen.
     *
     * **Ein Fehlschlag bricht das Anlegen nicht ab, sondern wird gemeldet.**
     * Quota braucht einen Mount mit `usrquota` und ein gelaufenes `quotacheck`.
     * Fehlt das, ist das ein Betriebsproblem des Servers und keine ungültige
     * Anfrage — und ein Abonnement, das deswegen gar nicht erst entsteht,
     * hinterlässt einen halben Zustand, den niemand bestellt hat. Der Aufrufer
     * bekommt `enforced: false` samt Grund und kann es anzeigen.
     *
     * @return array{enforced: bool, limit_mb: int, reason?: string}
     */
    private function quotaApply(Context $context, string $user, int $quotaMb): array
    {
        if ($quotaMb === 0) {
            return ['enforced' => false, 'limit_mb' => 0, 'reason' => 'kein Kontingent gesetzt'];
        }

        $device = $this->deviceFor(self::VHOSTS);

        if ($device === null) {
            return ['enforced' => false, 'limit_mb' => $quotaMb, 'reason' => 'kein Mount für '.self::VHOSTS.' gefunden'];
        }

        // Blöcke in KiB. Weiche und harte Grenze auf denselben Wert: Eine
        // Schonfrist, in der ein Abonnement sein Kontingent überschreiten
        // darf, ist eine Zusage, die niemand verlangt hat.
        $blocks = (string) ($quotaMb * 1024);

        $result = $context->stream('setquota', ['-u', $user, $blocks, $blocks, '0', '0', $device]);

        if (! $result->successful()) {
            return [
                'enforced' => false,
                'limit_mb' => $quotaMb,
                'reason' => trim($result->stderr) !== '' ? trim($result->stderr) : 'setquota fehlgeschlagen',
            ];
        }

        return ['enforced' => true, 'limit_mb' => $quotaMb];
    }

    /**
     * Das Gerät, auf dem ein Pfad liegt.
     *
     * Gelesen aus /proc/mounts und nicht über `df`: Der längste passende
     * Einhängepunkt gewinnt, damit ein eigener Mount für /var/www/vhosts
     * gefunden wird und nicht `/`.
     */
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
}
