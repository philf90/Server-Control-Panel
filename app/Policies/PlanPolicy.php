<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Plan;

/**
 * Pläne gehören dem Betreiber.
 *
 * Ein Kunde sieht den Plan, an dem sein Abonnement hängt — er muss wissen,
 * wovon seine Kontingente kommen. Ändern darf ihn niemand außer dem
 * Betreiber, denn eine Änderung wirkt auf alle daran gebundenen Abonnements.
 */
final class PlanPolicy
{
    public function viewAny(Account $account): bool
    {
        return $account->isAdmin();
    }

    public function view(Account $account, Plan $plan): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        // Nur der Plan, an dem eines der eigenen Abonnements hängt.
        return $plan->subscriptions()
            ->whereIn('id', $account->accessibleSubscriptionIds())
            ->exists();
    }

    public function create(Account $account): bool
    {
        return $account->isAdmin();
    }

    public function update(Account $account, Plan $plan): bool
    {
        return $account->isAdmin();
    }

    public function delete(Account $account, Plan $plan): bool
    {
        return $account->isAdmin();
    }
}
