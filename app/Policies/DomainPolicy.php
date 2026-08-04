<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Plans\Feature;

/**
 * Wer was mit einer Domain darf.
 *
 * **Die Frage steht am Abonnement, nicht an der Domain.** Wer ein Abonnement
 * benutzen darf, darf seine Domains anlegen und ändern; die Domain selbst
 * trägt kein eigenes Rechtemodell. Das ist die Zusage aus §6.1 — „Kunde: alles
 * innerhalb seiner Abonnements, im Rahmen von Plan und Kontingent" — und sie
 * bleibt genau dann wahr, wenn hier nichts danebensteht.
 *
 * **Kein `Gate::before` für Admins**, aus demselben Grund wie in
 * {@see SubscriptionPolicy}: Eine einzige Zeile „Admin darf alles" beantwortet
 * auch Fragen, die es nicht gibt, und ein vertippter Fähigkeitsname fiele
 * ausschliesslich bei Kunden auf.
 *
 * **Die Mandantenklammer hat vorher schon entschieden.** Eine fremde Domain
 * ist bei der Modellbindung „nicht gefunden" und erreicht diese Policy gar
 * nicht. Sie prüft trotzdem — zwei Schichten, und wenn eine ausfällt, hält die
 * andere (§6.2).
 */
final class DomainPolicy
{
    public function viewAny(Account $account): bool
    {
        return $account->isAdmin() || $account->accessibleSubscriptionIds() !== [];
    }

    public function view(Account $account, Domain $domain): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        return $account->mayAccessSubscription((int) $domain->subscription_id);
    }

    /**
     * Anlegen — geprüft am Abonnement, in dem sie entstehen soll.
     *
     * Laravel reicht die weiteren Routenparameter an die Policy durch; die
     * Route trägt deshalb `can:create,App\Models\Domain,subscription`. Ohne
     * das Abonnement liesse sich nur fragen, ob dieses Konto *irgendwo*
     * Domains anlegen darf — und ein Kunde mit zwei Abonnements dürfte dann in
     * beiden, sobald er in einem darf.
     */
    public function create(Account $account, Subscription $subscription): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        // Zusatzbenutzer brauchen dasselbe Recht wie zum Ändern: Wer eine
        // Domain anlegen darf, bestimmt, was ausgeliefert wird.
        return $account->mayAccessSubscription($subscription)
            && $subscription->usable()
            && $account->hasPermission($subscription, Permission::FilesWrite);
    }

    public function update(Account $account, Domain $domain): bool
    {
        return $this->mayChange($account, $domain);
    }

    public function delete(Account $account, Domain $domain): bool
    {
        return $this->mayChange($account, $domain);
    }

    /**
     * Die PHP-Einstellungen einer Domain ändern.
     *
     * **Eigene Fähigkeit, weil sie an einer Planfreigabe hängt.** `update`
     * fragt, ob jemand die Domain anfassen darf; das hier fragt zusätzlich, ob
     * der Plan diese Funktion überhaupt hergibt. Beides in eine Methode zu
     * legen hiesse, dass ein Kunde ohne die Freigabe auch das DocumentRoot
     * nicht mehr ändern könnte — und der Plan sagt darüber nichts.
     */
    public function updatePhp(Account $account, Domain $domain): bool
    {
        if (! $this->mayChange($account, $domain)) {
            return false;
        }

        if ($account->isAdmin()) {
            return true;
        }

        $subscription = $domain->subscription;

        return $subscription !== null
            && $subscription->feature(Feature::PhpSettings->value)
            && $account->hasPermission($subscription, Permission::PhpSettings);
    }

    /**
     * Die Protokolle einer Domain lesen.
     *
     * `FilesRead` und nicht `Statistics`: Ein Fehlerprotokoll enthält Pfade,
     * Dateinamen und im Zweifel Bruchstücke aus dem Quelltext der Anwendung.
     * Wer Dateien nicht lesen darf, soll sie auch nicht über den Umweg des
     * Protokolls sehen.
     */
    public function viewLogs(Account $account, Domain $domain): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        $subscription = $domain->subscription;

        return $subscription !== null
            && $account->mayAccessSubscription($subscription)
            && $account->hasPermission($subscription, Permission::FilesRead);
    }

    /**
     * Ändern und Entfernen haben dieselben Bedingungen.
     *
     * Ein gesperrtes Abonnement bleibt sichtbar und unbenutzbar — sonst wäre
     * „gesperrt" nur ein Etikett in der Oberfläche.
     */
    private function mayChange(Account $account, Domain $domain): bool
    {
        $subscription = $domain->subscription;

        if ($subscription === null) {
            return false;
        }

        if ($account->isAdmin()) {
            return true;
        }

        return $account->mayAccessSubscription($subscription)
            && $subscription->usable()
            && $account->hasPermission($subscription, Permission::FilesWrite);
    }
}
