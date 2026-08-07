<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Database;
use App\Models\Subscription;
use App\Support\Databases\Databases;
use App\Support\Plans\Feature;

/**
 * Wer was mit einer Datenbank darf.
 *
 * **Kein `Gate::before` für Admins**, aus demselben Grund wie überall in diesem
 * Verzeichnis: Eine Adminzeile in einem Provider beantwortet auch Fragen, die
 * es gar nicht gibt, und ein vertippter Fähigkeitsname liefert für Admins
 * weiterhin `true`. Die Zeile steht deshalb in jeder Methode einzeln.
 *
 * **Datenbanken hängen an keiner Freigabe des Plans, sondern an einem
 * Kontingent.** {@see Feature::forPermission()} liefert für
 * {@see Permission::Databases} kein `Feature` — das ist Absicht und keine
 * Lücke: Ein Plan, der keine Datenbanken vorsieht, setzt das Kontingent auf
 * `0`, und `0` heisst null Stück (`docs/23 §2`). Eine zweite Freigabe daneben
 * wäre eine zweite Antwort auf dieselbe Frage, und die beiden liefen
 * auseinander.
 *
 * Was das Recht {@see Permission::Databases} regelt, ist die andere Frage: ob
 * ein **Zusatzbenutzer** innerhalb des Abonnements herandarf. Beides prüft
 * {@see SubscriptionPolicy::useFeature()} in einem Zug.
 */
final class DatabasePolicy
{
    public function viewAny(Account $account): bool
    {
        return $account->isAdmin() || $account->accessibleSubscriptionIds() !== [];
    }

    public function view(Account $account, Database $database): bool
    {
        $subscription = $database->subscription;

        if ($subscription === null) {
            // Eine verwaiste Datenbank gehört niemandem mehr — sie ist die
            // Spur eines gescheiterten Rückbaus (`docs/36 §5`). Sie sieht der
            // Betreiber, und aufgeräumt wird sie über `srvpanel db prune`.
            return $account->isAdmin();
        }

        return $this->may($account, $subscription);
    }

    /**
     * Anlegen wird am **Abonnement** gefragt und nicht an der Datenbank.
     *
     * Ohne das Abonnement liesse sich nur fragen, ob dieses Konto *irgendwo*
     * Datenbanken anlegen darf — dieselbe Unterscheidung wie bei den Domains
     * in P3, und sie steht auch in der Adresse: `/subscriptions/{s}/databases`.
     *
     * Das Kontingent prüft die Policy **nicht**. Es ist keine Rechtefrage,
     * sondern eine Mengenfrage, und die Antwort darauf braucht einen Zähler und
     * eine verständliche Meldung — sie steht in
     * {@see Databases::guardQuota()}.
     */
    public function create(Account $account, Subscription $subscription): bool
    {
        return $this->may($account, $subscription);
    }

    /** Ändern: Zugänge anlegen, Rechte vergeben, Passwörter zurücksetzen. */
    public function update(Account $account, Database $database): bool
    {
        $subscription = $database->subscription;

        return $subscription !== null && $this->may($account, $subscription);
    }

    /**
     * Löschen — dieselbe Fähigkeit wie Ändern, und das ist eine Entscheidung.
     *
     * Bei einem Abonnement liegt das Löschen beim Betreiber, weil es ein
     * Vertragsgegenstand ist. Eine Datenbank gehört dem Kunden: Sie ist seine
     * Anwendung, und wer sie anlegen darf, darf sie auch wieder loswerden. Die
     * Rückfrage in der Oberfläche nennt den Namen und sagt, dass es keine
     * Sicherung gibt — dieselbe Form wie beim Rückbau eines Abonnements
     * (`docs/26 §6`).
     */
    public function delete(Account $account, Database $database): bool
    {
        return $this->update($account, $database);
    }

    /**
     * Zugang, Zustand und Recht in einem Zug.
     *
     * `useFeature` weist ein gesperrtes Abonnement ab — ein gesperrtes bleibt
     * sichtbar, aber unbenutzbar, sonst wäre „gesperrt" nur ein Etikett.
     */
    private function may(Account $account, Subscription $subscription): bool
    {
        return app(SubscriptionPolicy::class)->useFeature($account, $subscription, Permission::Databases);
    }
}
