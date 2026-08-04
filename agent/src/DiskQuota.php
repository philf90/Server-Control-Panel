<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Die Dateisystem-Quota eines Systembenutzers setzen.
 *
 * **Warum das nicht in der Operation steht, die es zuerst brauchte.** Bis
 * August 2026 stand es in {@see SubscriptionProvision} — dort entsteht ein
 * Abonnement, und dabei bekommt es seine Grenze. Dann kam das Ändern eines
 * Kontingents dazu, und mit ihm die Frage, wie man die Grenze neu setzt, ohne
 * das Abonnement noch einmal anzulegen. Der bequeme Weg wäre gewesen, einfach
 * `subscription.provision` ein zweites Mal aufzurufen — sie ist ja
 * wiederholbar. Sie setzt dabei aber auch die Rechte der Wurzel auf `0755`
 * zurück, und das ist genau der Schalter, mit dem `subscription.suspend`
 * sperrt: Ein gesperrtes Abonnement wäre nach einer Kontingentänderung wieder
 * erreichbar gewesen, ohne dass irgendwo „entsperrt" stünde.
 *
 * Deshalb steht die Quota jetzt hier, und es gibt eine eigene Operation, die
 * nichts anderes tut.
 */
final class DiskQuota
{
    /**
     * Die Grenze prüfen.
     *
     * Null bis 16 TiB. Die Obergrenze ist kein Verbot, sondern ein
     * Vertipper-Fang: `setquota` nimmt jede Zahl an, und eine Null zu viel
     * ergibt eine Grenze, die es auf keinem Dateisystem gibt.
     */
    public static function limit(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 1024 * 1024 * 16) {
            throw AgentException::badRequest('quota_mb muss eine Zahl zwischen 0 und 16 TiB sein.');
        }

        return $value;
    }

    /**
     * Setzen — und im Fehlerfall melden statt abzubrechen.
     *
     * **Ein Fehlschlag ist keine Ausnahme.** Quota braucht einen Mount mit
     * `usrquota` und ein gelaufenes `quotacheck`. Fehlt das, ist das ein
     * Betriebsproblem des Servers und keine ungültige Anfrage — und ein
     * Abonnement, das deswegen gar nicht erst entsteht, hinterlässt einen
     * halben Zustand, den niemand bestellt hat. Der Aufrufer bekommt
     * `enforced: false` samt Grund und kann es anzeigen.
     *
     * @return array{enforced: bool, limit_mb: int, reason?: string}
     */
    public static function apply(Context $context, string $user, int $quotaMb): array
    {
        if ($quotaMb === 0) {
            return ['enforced' => false, 'limit_mb' => 0, 'reason' => 'kein Kontingent gesetzt'];
        }

        $device = Mounts::deviceFor(SubscriptionProvision::VHOSTS);

        if ($device === null) {
            return [
                'enforced' => false,
                'limit_mb' => $quotaMb,
                'reason' => 'kein Mount für '.SubscriptionProvision::VHOSTS.' gefunden',
            ];
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
}
