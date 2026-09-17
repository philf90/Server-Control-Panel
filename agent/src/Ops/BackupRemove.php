<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Eine Sicherung entfernen.
 *
 * **Sie steht vor `backup.create` in der Registry, und das ist derselbe Grund
 * wie bei `db.dump.remove`** (`docs/36 §2`): Was auf der Platte liegenbleibt
 * und beliebig gross wird, braucht seinen Rückweg, bevor es entsteht. Eine
 * Sicherung ist der ganze Baum eines Abonnements plus seine Datenbanken;
 * mehrere davon füllen einen Datenträger, und ein voller Datenträger nimmt
 * jeden anderen Kunden mit.
 *
 * > **Wer etwas anlegt, das auf der Platte bleibt, baut den Weg zurück mit;
 * > sonst findet ihn Jahre später eine Datenmigration.**
 *
 * **Zwei Gegenstände in einem Aufruf**, wie bei `db.dump.remove`:
 *
 * - eine einzelne Ablage (`storage` gesetzt) — wenn eine Aufbewahrungsfrist
 *   abläuft oder jemand aufräumt,
 * - das ganze Verzeichnis eines Abonnements (`storage` fehlt) — beim Rückbau.
 *   `subscription.remove` räumt auf, was zum Abo-Verzeichnis gehört, und
 *   `/var/lib/srvpanel/backups/<abo>` gehört nicht dazu. Dieselbe Lage wie bei
 *   den Dumps und bei den Zertifikatsverzeichnissen, die `docs/35` zutage
 *   gebracht hat.
 *
 * **Wiederholbar.** Eine Ablage, die es nicht mehr gibt, ist der gewünschte
 * Zustand; der Aufruf meldet das und scheitert nicht.
 */
final class BackupRemove implements Op
{
    public static function name(): string
    {
        return 'backup.remove';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $subscription = is_string($args['subscription'] ?? null) ? $args['subscription'] : '';
        $storage = $args['storage'] ?? null;

        if (! is_string($storage) || $storage === '') {
            $context->progress(50, 'Verzeichnis der Sicherungen entfernen');

            $removed = Store::removeDirectory($subscription);

            $context->progress(100, $removed ? 'entfernt' : 'nichts zu entfernen');

            return ['scope' => 'directory', 'removed' => $removed];
        }

        // Der Pfad entsteht hier aus zwei geprüften Hälften und kommt nicht von
        // aussen — die erste Grenze aus `docs/20 §4.1`: Ein Prozess mit
        // Systemrechten nimmt keinen Pfad entgegen, er baut ihn.
        $path = Store::path($subscription, $storage);

        $context->progress(50, 'Sicherung entfernen');

        $removed = is_file($path) && @unlink($path);

        $context->progress(100, $removed ? 'entfernt' : 'nichts zu entfernen');

        return ['scope' => 'file', 'storage' => $storage, 'removed' => $removed];
    }
}
