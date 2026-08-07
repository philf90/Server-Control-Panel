<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Ein abgelegtes Zertifikat entfernen.
 *
 * **Die Operation, die bis August 2026 gefehlt hat.** Dieses System konnte ein
 * Zertifikat bestellen, hochladen und erneuern — aber nirgends löschen. Ein
 * zurückgebautes Abonnement liess sein Verzeichnis unter
 * {@see Store::ROOT} liegen, und darin den **privaten Schlüssel**;
 * `subscription.remove` räumt nur auf, was zum Abo-Verzeichnis gehört, und der
 * Ablageort gehört nicht dazu. Zwölf solcher Verzeichnisse lagen auf dem
 * Zielserver, als die Migration aus docs/35 danach fragte.
 *
 * **Der Pfad entsteht hier und kommt nicht von aussen.** Übergeben wird ein
 * Name, und {@see Store::remove()} macht daraus den Ablageort — durch dieselbe
 * Prüfung, die auch beim Schreiben gilt. Dieselbe Regel wie in `Site` und
 * `SubscriptionProvision`: Ein Prozess mit Systemrechten nimmt keinen Pfad
 * entgegen.
 *
 * **Wer entscheidet, ob ein Ablageort weg darf, ist nicht diese Operation.**
 * Zwei Zertifikate können denselben Ablageort haben — ein erneuertes und sein
 * Vorgänger, oder eines eines zurückgebauten und eines eines lebenden
 * Abonnements. Genau das lag auf dem Zielserver vor: `cloudlab24.de` einmal tot
 * und einmal lebend. Welche Namen fortdürfen, weiss nur die Anwendung, weil nur
 * sie die Zeilen kennt; sie nennt hier einen einzelnen, und der Agent führt aus.
 * Eine Operation, die selbst in der Datenbank nachsähe, wäre eine zweite
 * Fassung dieser Regel — und die zweite ist die, die veraltet.
 */
final class AcmeCertificateRemove implements Op
{
    public function __construct(private readonly Store $store = new Store) {}

    public static function name(): string
    {
        return 'acme.certificate.remove';
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
        $context->progress(40, 'Ablageort entfernen');

        $result = $this->store->remove(is_string($args['name'] ?? null) ? $args['name'] : '');

        $context->progress(100, $result['removed'] ? 'entfernt' : 'nichts zu entfernen');

        return $result;
    }
}
