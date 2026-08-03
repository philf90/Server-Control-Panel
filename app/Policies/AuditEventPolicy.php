<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\AuditEvent;

/**
 * Das Protokoll.
 *
 * Kunden sehen ihre eigenen Ereignisse — die zu ihren Abonnements und die zu
 * ihrem eigenen Konto. Nicht die des Betreibers, und nicht die anderer
 * Kunden.
 *
 * **Ein Eintrag ohne Abonnement und ohne Konto ist nur für Admins sichtbar.**
 * Dort liegen die fehlgeschlagenen Anmeldungen unbekannter Adressen; wer sie
 * lesen darf, sieht, unter welchen Adressen jemand geklopft hat.
 *
 * Ändern und Löschen gibt es nicht — auch nicht für Admins. Ein Protokoll,
 * das sich bearbeiten lässt, ist als Nachweis wertlos.
 */
final class AuditEventPolicy
{
    public function viewAny(Account $account): bool
    {
        return true;
    }

    public function view(Account $account, AuditEvent $event): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        if ($event->account_id !== null && (int) $event->account_id === (int) $account->id) {
            return true;
        }

        if ($event->subscription_id === null) {
            return false;
        }

        return $account->mayAccessSubscription((int) $event->subscription_id);
    }

    public function update(Account $account, AuditEvent $event): bool
    {
        return false;
    }

    public function delete(Account $account, AuditEvent $event): bool
    {
        return false;
    }
}
