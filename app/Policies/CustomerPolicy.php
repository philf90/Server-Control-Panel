<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Customer;

/**
 * Wer welche Kunden sieht.
 *
 * Kunden tragen keine Mandantenklammer — sie sind das, woraus die Klammer
 * entsteht. Hier ist deshalb die einzige Stelle, die ihre Sichtbarkeit regelt,
 * und sie muss es vollständig tun.
 *
 * Ein Kunde sieht genau einen Kunden: sich selbst. Ein Zusatzbenutzer sieht
 * gar keinen — er arbeitet in Abonnements, nicht an Vertragsdaten. Dass die
 * Prüfung trotzdem über die Zugehörigkeitskette läuft und nicht über
 * `$account->customer_id === $customer->id`, ist Vorbereitung: Ein Reseller
 * wird später seine Unterkunden sehen (§5.4).
 */
final class CustomerPolicy
{
    public function viewAny(Account $account): bool
    {
        return $account->isAdmin();
    }

    public function view(Account $account, Customer $customer): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        if ($account->customer_id === null) {
            return false;
        }

        $reachable = $account->customer?->descendantIdsIncludingSelf() ?? [];

        return in_array((int) $customer->id, $reachable, true);
    }

    public function create(Account $account): bool
    {
        return $account->isAdmin();
    }

    public function update(Account $account, Customer $customer): bool
    {
        return $account->isAdmin();
    }

    public function delete(Account $account, Customer $customer): bool
    {
        return $account->isAdmin();
    }

    /**
     * „Anmelden als" (§6.3).
     *
     * Nur Admins, und ausdrücklich als eigene Fähigkeit: Wer die Sicht eines
     * Kunden übernehmen darf, ist eine andere Frage als wer ihn bearbeiten
     * darf, auch wenn heute dieselbe Antwort herauskommt.
     */
    /**
     * Sperren und freigeben.
     *
     * Getrennt von `update`, weil es etwas anderes ist: Das Bearbeiten ändert
     * einen Datensatz, das Sperren nimmt einem Kunden seine Abonnements vom
     * Netz. Beides in einer Fähigkeit hiesse, dass jemand, der eine Anschrift
     * korrigieren darf, auch abschalten darf.
     */
    public function suspend(Account $account, Customer $customer): bool
    {
        return $account->isAdmin();
    }

    public function impersonate(Account $account, Customer $customer): bool
    {
        return $account->isAdmin();
    }
}
