<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Op;

/**
 * Eine Sicherung entfernen.
 *
 * **Die dritte Datei, die vor ihrer `create`-Hälfte entstanden ist** (`docs/36
 * §2`). Eine Sicherung ist das, was P5 auf dem System hinterlässt und was
 * beliebig gross wird: die vollständige Datenbank eines Kunden, komprimiert,
 * unter `/var/lib/srvpanel/dumps`. Ohne diese Operation füllte sie den
 * Datenträger und nähme jeden anderen Kunden mit.
 *
 * **Zwei Gegenstände in einem Aufruf**, und die Unterscheidung steht in den
 * Argumenten:
 *
 * - eine einzelne Ablage (`storage` gesetzt) — der Regelfall, wenn eine
 *   Aufbewahrungsfrist abläuft oder jemand aufräumt,
 * - das ganze Verzeichnis eines Abonnements (`storage` fehlt) — beim Rückbau.
 *
 * Der zweite Fall ist der, den es ohne diese Operation nicht gäbe:
 * `subscription.remove` räumt auf, was zum Abo-Verzeichnis gehört, und
 * `/var/lib/srvpanel/dumps/<abo>` gehört nicht dazu — genau dieselbe Lage wie
 * bei den Zertifikatsverzeichnissen unter `/etc/srvpanel/tls/certs`, die
 * `docs/35` zutage gebracht hat.
 *
 * **Wiederholbar.** Eine Ablage, die es nicht mehr gibt, ist der gewünschte
 * Zustand; der Aufruf meldet das und scheitert nicht.
 */
final class DbDumpRemove implements Op
{
    public static function name(): string
    {
        return 'db.dump.remove';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * Der Satz je Grund — und jeder Grund hat einen.
     *
     * `NOT_EMPTY` steht hier nicht: Dieses Verzeichnis geht über
     * {@see Filesystem::removeTree()} weg und nicht über `rmdir(2)`, also gibt
     * es den Ausgang nicht. Käme er je dazu, bräche der Zugriff **laut**
     * — und das ist der Sinn der Abbildung.
     *
     * @var array<string, string>
     */
    private const MELDUNG = [
        Filesystem::REMOVED => 'entfernt',
        Filesystem::ABSENT => 'das Verzeichnis gibt es nicht',
    ];

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

            /*
             * **Der Grund und nicht bloss das Ergebnis** — dieselbe Behebung
             * wie in {@see BackupRemove} und aus demselben Anlass
             * (`docs/121 §9`, Befund 5): „nichts zu entfernen" stand hier für
             * ein Verzeichnis, das es nicht gibt, **und** für einen Verweis,
             * dem der Griff zu Recht nicht folgt. Der zweite wirft jetzt.
             */
            $grund = Dump::removeDirectory($subscription);

            $context->progress(100, self::MELDUNG[$grund]);

            return [
                'scope' => 'directory',
                'removed' => $grund === Filesystem::REMOVED,
                'reason' => $grund,
            ];
        }

        // Der Pfad entsteht hier aus zwei geprüften Hälften und kommt nicht von
        // aussen — dieselbe Regel wie in `AcmeCertificateRemove` und in
        // `SubscriptionRemove`: Ein Prozess mit Systemrechten nimmt keinen Pfad
        // entgegen.
        $path = Dump::path($subscription, $storage);

        $context->progress(50, 'Sicherung entfernen');

        $removed = is_file($path) && @unlink($path);

        $context->progress(100, $removed ? 'entfernt' : 'nichts zu entfernen');

        return ['scope' => 'file', 'storage' => $storage, 'removed' => $removed];
    }
}
