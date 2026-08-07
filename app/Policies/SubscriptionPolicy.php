<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Subscription;
use App\Support\Plans\Feature;

/**
 * Wer was mit einem Abonnement darf.
 *
 * **Kein `Gate::before` für Admins.** Der bequeme Weg wäre eine einzige Zeile
 * in einem Provider: „ist der Anmelder ein Admin, darf er alles". Sie hat
 * einen Haken, der erst spät auffällt — sie beantwortet auch Fragen, die es
 * gar nicht gibt. Wird eine Policy-Methode umbenannt oder ein Fähigkeitsname
 * vertippt, liefert `$admin->can('vertipptes-recht')` weiterhin `true`, und
 * der Fehler zeigt sich ausschließlich bei Kunden. Deshalb steht die
 * Adminzeile in jeder Policy einzeln: Ein Tippfehler scheitert dann für alle,
 * sofort und sichtbar.
 *
 * **Zwei Fragen, nicht eine.** Bei einem Zusatzbenutzer entscheidet die
 * Zuordnung, *ob* er an das Abonnement darf, und der Rechtekatalog, *was* er
 * darin darf. Beides muss zutreffen. Eine Prüfung, die nur eines von beidem
 * ansieht, ist entweder zu streng oder zu großzügig — und zu großzügig fällt
 * niemandem auf.
 */
final class SubscriptionPolicy
{
    public function viewAny(Account $account): bool
    {
        return $account->isAdmin() || $account->accessibleSubscriptionIds() !== [];
    }

    public function view(Account $account, Subscription $subscription): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        return $account->mayAccessSubscription($subscription);
    }

    /** Anlegen und Löschen bleiben beim Betreiber. */
    public function create(Account $account): bool
    {
        return $account->isAdmin();
    }

    public function delete(Account $account, Subscription $subscription): bool
    {
        return $account->isAdmin();
    }

    /**
     * Stammdaten ändern — Name, Plan, Zustand.
     *
     * Ein Kunde darf sein Abonnement nicht umschreiben; das ist der Vertrag.
     * Was er darin tun darf, regeln die Policies der Objekte darunter.
     */
    public function update(Account $account, Subscription $subscription): bool
    {
        return $account->isAdmin();
    }

    /** Sperren und Entsperren ist Betreibersache. */
    public function suspend(Account $account, Subscription $subscription): bool
    {
        return $account->isAdmin();
    }

    /**
     * Die eigenen DNS-Zugangsdaten hinterlegen (`docs/34 §5`).
     *
     * **Warum das eine eigene Fähigkeit ist und nicht `useFeature(Dns)`.** Es
     * ist dieselbe Freigabe — `Feature::DnsEdit` mit `Permission::Dns` —, aber
     * die Frage lautet hier nicht „darf dieses Konto DNS-Einträge bearbeiten",
     * sondern „gibt es für dieses Abonnement überhaupt ein eigenes Profil".
     * Ohne die Freigabe im Plan gilt das Profil des Betreibers, und dann gibt
     * es hier nichts zu hinterlegen — kein Formular, kein Knopf, keine Route.
     *
     * **Der Admin ist hier ausdrücklich nicht pauschal dabei.** Er darf sonst
     * alles, aber ein Abonnement ohne `DnsEdit` hat kein eigenes Profil; ein
     * Formular, das für ihn erscheint und ein Profil anlegt, das nie gefragt
     * wird, wäre eine Ablage ohne Leser. Was der Betreiber hinterlegen will,
     * gehört unter `betrieb` — dafür gibt es die Seite in den Einstellungen.
     */
    public function manageDns(Account $account, Subscription $subscription): bool
    {
        if (! $subscription->feature(Feature::DnsEdit->value)) {
            return false;
        }

        return $this->useFeature($account, $subscription, Permission::Dns);
    }

    /**
     * Eine Fachfunktion innerhalb des Abonnements.
     *
     * Der Einstieg für alles, was ab P2 dazukommt: Datenbanken, DNS, Cron,
     * Sicherungen. Drei Bedingungen, und alle drei müssen gelten — Zugang zum
     * Abonnement, Freigabe im Plan, Recht des Zusatzbenutzers.
     */
    public function useFeature(Account $account, Subscription $subscription, Permission $permission): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        if (! $account->mayAccessSubscription($subscription)) {
            return false;
        }

        // Ein gesperrtes Abonnement bleibt sichtbar, aber unbenutzbar. Sonst
        // wäre „gesperrt" nur ein Etikett in der Oberfläche.
        if (! $subscription->usable()) {
            return false;
        }

        if (! $this->planAllows($subscription, $permission)) {
            return false;
        }

        return $account->hasPermission($subscription, $permission);
    }

    /**
     * Gibt der Plan diese Funktion überhaupt frei?
     *
     * Die Zuordnung ist bewusst unvollständig: Was keiner Freigabe zugeordnet
     * ist, ist keine planabhängige Funktion und damit immer erlaubt — Dateien
     * lesen etwa gehört zu jedem Abonnement.
     *
     * Die Zuordnung selbst stand bis August 2026 hier als `match` mit vier
     * Zeichenketten darin. Sie steht jetzt in {@see Feature}, wo auch die
     * Beschriftung und der Vorgabewert stehen — vier Literale an einer Stelle,
     * die nichts von den Plänen weiss, waren vier Gelegenheiten für einen
     * Tippfehler, der eine Funktion stillschweigend für alle freigibt.
     */
    private function planAllows(Subscription $subscription, Permission $permission): bool
    {
        $feature = Feature::forPermission($permission);

        return $feature === null || $subscription->feature($feature->value);
    }
}
