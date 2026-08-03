<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

/**
 * Ein Abonnement sperren: Webseiten aus, Zugänge aus, Daten bleiben.
 *
 * Die Mechanik steht in {@see SubscriptionState}. Hier stehen die drei Werte,
 * die den Unterschied zum Entsperren ausmachen — sie nebeneinander lesen zu
 * können, ist der Grund für den Zuschnitt.
 */
final class SubscriptionSuspend extends SubscriptionState
{
    public static function name(): string
    {
        return 'subscription.suspend';
    }

    /**
     * `0750` statt `0755`: „andere" verlieren das x-Bit, und damit kommt kein
     * Webserver-Prozess mehr in das Verzeichnis. Der Eigentümer bleibt root,
     * der Inhalt unangetastet.
     */
    protected function rootMode(): int
    {
        return 0750;
    }

    /** @return list<string> */
    protected function accountArgs(string $user): array
    {
        // `--lock` allein hindert niemanden, der sich mit einem Schlüssel
        // anmeldet; `--expiredate 1` ist die Schranke, die SSH und SFTP
        // tatsächlich prüfen. Der 2. Januar 1970 liegt hinreichend weit
        // zurück.
        return ['--lock', '--expiredate', '1', $user];
    }

    protected function stopsProcesses(): bool
    {
        return true;
    }
}
