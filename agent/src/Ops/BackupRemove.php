<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Filesystem;
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
 * - das **leere** Verzeichnis eines Abonnements (`storage` fehlt) — wenn die
 *   letzte Sicherung eines zurückgebauten Abonnements entfernt worden ist.
 *   `subscription.remove` räumt auf, was zum Abo-Verzeichnis gehört, und
 *   `/var/lib/srvpanel/backups/<abo>` gehört nicht dazu. Dieselbe Lage wie bei
 *   den Dumps und bei den Zertifikatsverzeichnissen, die `docs/35` zutage
 *   gebracht hat.
 *
 *   **Leer und nicht als Baum**, seit dieser Zweig seinen Aufrufer hat
 *   (17. September 2026): Was in einem solchen Verzeichnis noch liegt, ist eine
 *   Datei ohne Zeile — und genau die meldet die Bestandsdiagnose als `orphan`.
 *   Die Begründung steht bei {@see Store::removeDirectory()}.
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
     * Der Satz je Grund — und jeder Grund hat einen.
     *
     * **Eine Abbildung und keine Kette von `if`**: Käme ein vierter Ausgang
     * dazu und stünde hier nicht, bräche der Zugriff laut, statt still auf den
     * harmlosesten Satz zurückzufallen.
     *
     * > **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine
     * > falsche Auskunft.**
     *
     * @var array<string, string>
     */
    private const MELDUNG = [
        Filesystem::REMOVED => 'entfernt',
        Filesystem::ABSENT => 'das Verzeichnis gibt es nicht',
        Filesystem::NOT_EMPTY => 'es liegt noch etwas darin — das Verzeichnis bleibt',
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

            $grund = Store::removeDirectory($subscription);

            /*
             * **Hier stand, „nichts zu entfernen" decke zwei Zustände, und
             * beide seien in Ordnung** — mit der Begründung, zu unterscheiden
             * wäre es nur für einen Leser, den es nicht gibt.
             *
             * Beides war falsch. Es waren **drei** Zustände (der Verweis kam
             * dazu, und der ist eine Weigerung), und den Leser gibt es: Am
             * 18. September 2026 stand der Betreiber auf `cloudsrv24` vor
             * einem Verzeichnis mit einer Datei darin und las „nichts zu
             * entfernen" (`docs/121 §9`, Befund 5).
             *
             * Das wiegt, weil das Verzeichnis liegenbleibt und die Diagnose es
             * **jede Nacht weiter meldet**. Wer dann den Vorgang ansieht, sucht
             * den Fehler bei der Diagnose.
             *
             * > **Zwei Gründe, die dasselbe Ergebnis erzeugen, sind nicht
             * > derselbe Grund — und die Abhilfe für den einen lässt den
             * > anderen stehen.**
             *
             * `removed` bleibt als Wort daneben stehen: Jede entfernende
             * Operation dieses Agenten führt es, und ein Leser, der nur die
             * Frage „hat es geklappt" stellt, soll sie weiter stellen können.
             */
            $context->progress(100, self::MELDUNG[$grund]);

            return [
                'scope' => 'directory',
                'removed' => $grund === Filesystem::REMOVED,
                'reason' => $grund,
            ];
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
