<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\DiskQuota;
use SrvPanel\Agent\Op;

/**
 * Die Speichergrenze eines bestehenden Abonnements ändern — und sonst nichts.
 *
 * **Warum es diese Operation gibt, obwohl `subscription.provision` sie
 * mitsetzt.** Provision ist wiederholbar; sie ein zweites Mal aufzurufen wäre
 * der kürzeste Weg gewesen, ein geändertes Kontingent anzuwenden. Sie rückt
 * dabei aber auch die Rechte der Chroot-Wurzel auf `0755` zurecht — und genau
 * dieses Bit ist der Schalter, mit dem {@see SubscriptionSuspend} sperrt. Ein
 * gesperrtes Abonnement wäre nach einer Kontingentänderung wieder erreichbar
 * gewesen, und im Panel hätte weiter „gesperrt" gestanden. Die Sperre wäre
 * damit nicht aufgehoben, sondern unsichtbar geworden.
 *
 * Diese Operation fasst deshalb kein Verzeichnis und kein Konto an. Sie setzt
 * eine Zahl, und ihr Ergebnis sagt, ob das Dateisystem sie durchsetzt.
 *
 * Der Systembenutzer wird gegen dieselbe enge Form geprüft wie beim Anlegen:
 * `p` und vier bis neun Ziffern. Ohne sie wäre `setquota` ein Weg, einem
 * beliebigen Konto des Servers eine Grenze zu setzen — `root` eingeschlossen.
 */
final class SubscriptionQuota implements Op
{
    public static function name(): string
    {
        return 'subscription.quota';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $quotaMb = DiskQuota::limit($args['quota_mb'] ?? null);

        $context->progress(30, 'Quota setzen');
        $quota = DiskQuota::apply($context, $user, $quotaMb);
        $context->progress(100, 'fertig');

        return ['user' => $user, 'quota' => $quota];
    }
}
