<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Operation;

/**
 * Vorgänge sieht, wer das zugehörige Abonnement sieht.
 *
 * Ein Vorgang ohne Abonnement gehört dem Betreiber — Paketinstallation,
 * Dienstneustart. Die Mandantenklammer hält ihn schon von Kunden fern; diese
 * Policy sagt dasselbe noch einmal ausdrücklich. Das ist keine Verdopplung
 * aus Nachlässigkeit, sondern die zweite der vier Schichten aus §6.2: Wenn
 * eine davon versagt, soll die andere noch stehen.
 */
final class OperationPolicy
{
    public function viewAny(Account $account): bool
    {
        return true;
    }

    public function view(Account $account, Operation $operation): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        if ($operation->subscription_id === null) {
            return false;
        }

        return $account->mayAccessSubscription($operation->subscription_id);
    }

    /**
     * Einen laufenden Vorgang abbrechen.
     *
     * Wer ihn sehen darf, darf ihn auch abbrechen — er hat ihn in aller Regel
     * selbst ausgelöst, und ein Abbruch nimmt nichts weg, was nicht ohnehin
     * seines wäre.
     */
    public function cancel(Account $account, Operation $operation): bool
    {
        return $this->view($account, $operation) && $operation->open();
    }
}
