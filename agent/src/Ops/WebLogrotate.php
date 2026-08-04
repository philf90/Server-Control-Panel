<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\NginxApply;
use SrvPanel\Agent\Op;

/**
 * Die Rotation der Protokolle eines Abonnements.
 *
 * **Ohne sie füllt das Zugriffsprotokoll die Quota des Kunden.** Die
 * Protokolle liegen nach §4.5 im Verzeichnis des Abonnements und zählen damit
 * auf seinen Speicherplatz. Eine Website mit ein wenig Verkehr schreibt im
 * Monat mehrere Gigabyte — und der Kunde bekäme eine volle Quota für Dateien,
 * die er nie angelegt hat und nicht löschen würde.
 *
 * **Eine Datei je Abonnement, nicht je Domain.** Der Ausdruck deckt alle
 * Domains ab, auch die, die es morgen gibt: `logs/&#42;/&#42;.log`. Eine Datei je
 * Domain hiesse, dass eine vergessene Domain nicht rotiert — und das fiele
 * erst auf, wenn die Quota voll ist.
 *
 * **Der `postrotate`-Abschnitt enthält keinen einzigen übergebenen Wert.** Er
 * ist ein Shell-Fragment, das als root läuft; alles Veränderliche steht im
 * Pfad darüber, und der ist aus dem geprüften Namen des Abonnements gebaut.
 */
final class WebLogrotate implements Op
{
    public const DIRECTORY = '/etc/logrotate.d';

    public static function name(): string
    {
        return 'web.logrotate.apply';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);

        $path = self::DIRECTORY.'/srvpanel-'.$subscription;

        if (! is_dir(self::DIRECTORY)) {
            // logrotate fehlt auf schlanken Installationen. Das ist kein Grund
            // zum Abbruch des Vorgangs, der die Website anlegt — aber der
            // Betreiber soll es erfahren.
            return ['path' => $path, 'written' => false, 'reason' => 'logrotate ist nicht installiert.'];
        }

        NginxApply::write($path, self::template($subscription, $user));

        return ['path' => $path, 'written' => true, 'reason' => null];
    }

    public static function template(string $subscription, string $user): string
    {
        $root = SubscriptionProvision::VHOSTS.'/'.$subscription;

        return <<<CONF
        # Von srvpanel-agentd erzeugt. Änderungen von Hand werden beim nächsten
        # Lauf überschrieben.

        {$root}/logs/*/*.log {$root}/logs/*.log {
            daily
            rotate 14
            missingok
            notifempty
            compress
            delaycompress
            nocreate
            sharedscripts

            # Ohne `su` weigert sich logrotate, in ein Verzeichnis zu
            # schreiben, das einem Nutzer gehört und für die Gruppe schreibbar
            # ist — eine Vorsichtsmassnahme gegen untergeschobene Verweise.
            su root adm

            create 0640 {$user} adm

            postrotate
                /usr/bin/systemctl kill --signal=USR1 nginx.service
            endscript
        }

        CONF;
    }
}
