<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Http\Controllers\Auth\LoginController;
use App\Models\Account;
use App\Models\Customer;

/**
 * Darf dieses Konto das Panel benutzen?
 *
 * ## Warum es diese Klasse gibt
 *
 * Die Frage wurde an drei Türen gestellt — Anmeldung, zweiter Faktor, Rückkehr
 * aus einer fremden Sicht — und an jeder etwas anders. Der
 * {@see LoginController} fragte
 * `status->canSignIn()` **und** ob der Kunde zurückgezogen ist; die beiden
 * anderen nur das erste. Ein zurückgezogener Kunde kam damit über den zweiten
 * Faktor herein.
 *
 * > **Zwei Eingänge zu derselben Einstellung teilen ihre Prüfung, oder die
 * > Einstellung hat zwei Bedeutungen.**
 *
 * ## Und warum sie jetzt auch bei jeder Anfrage gestellt wird
 *
 * Das ist Befund 6 aus `docs/84`. Keine der sieben Mittelschichten fragte den
 * Kontozustand; gefragt wurde ausschliesslich beim **Anmelden**. Ein gesperrtes
 * Adminkonto behielt seine Rechte damit bis zu 30 Tage — so lange, wie die
 * absolute Obergrenze der Sitzung reicht, und der Leerlauf setzt sich bei jedem
 * Klick zurück.
 *
 * > **Eine Schranke, die nur an der Tür steht, gilt für niemanden, der schon
 * > drin ist.**
 *
 * Derselbe Satz wie bei {@see AdminNetwork}, und aus demselben Grund. Er ist in
 * P7b zweimal fällig geworden: einmal für das Netz, einmal für den Zustand.
 *
 * ## Was hier **nicht** gefragt wird
 *
 * Ob die Zugangsdaten stimmen. Das ist die Frage der Anmeldung und keine über
 * den Zustand eines Kontos — deshalb bleiben „unbekannte Adresse" und
 * „falsches Passwort" im {@see LoginController}.
 */
final class AccountAccess
{
    /** Darf dieses Konto herein — und drinbleiben? */
    public static function permits(Account $account): bool
    {
        return self::refusal($account) === null;
    }

    /**
     * Warum nicht — oder `null`, wenn es darf.
     *
     * Der Satz geht ins Protokoll und **nie** an den Browser: Wer
     * unterscheidet, verrät, welche Adressen es gibt.
     *
     * **Die Reihenfolge ist die des Protokolls seit P1** — ein zurückgezogener
     * Kunde wird als solcher geführt, auch wenn sein Konto zusätzlich gesperrt
     * ist. Das ist die genauere Auskunft: Gesperrt hat ein Mensch, zurückgezogen
     * wurde der ganze Kunde.
     */
    public static function refusal(Account $account): ?string
    {
        if (self::customerWithdrawn($account)) {
            return 'Kunde zurückgezogen';
        }

        if (! $account->status->canSignIn()) {
            return 'Konto deaktiviert';
        }

        return null;
    }

    /**
     * Gehört das Konto zu einem zurückgezogenen Kunden?
     *
     * Kunden werden nicht gelöscht, sondern zurückgezogen — ihre Zeile bleibt
     * stehen, damit die Kundennummer verbraucht bleibt. Ihre Konten bleiben
     * damit ebenfalls stehen, mit gültigem Passwort und Status „aktiv". Ohne
     * diese Frage meldet sich ein gekündigter Kunde weiter an; er sähe zwar
     * nichts, weil die Mandantenklammer über den Kunden läuft und dann eine
     * leere Menge liefert — aber „kommt rein und sieht nichts" ist keine
     * Kündigung, sondern ein Fehler, der wie einer aussieht.
     *
     * `withTrashed()` beim Nachschlagen, sonst wäre die Zeile leer und die
     * Unterscheidung zu „Konto ohne Kunde" ginge verloren.
     *
     * **`Customer` trägt keine Mandantenklammer** — nachgesehen und nicht
     * angenommen. Diese Frage darf deshalb aus einer Mittelschicht gestellt
     * werden, in der die Klammer schon steht.
     */
    private static function customerWithdrawn(Account $account): bool
    {
        if ($account->customer_id === null) {
            return false;
        }

        return Customer::query()
            ->withTrashed()
            ->whereKey($account->customer_id)
            ->value('deleted_at') !== null;
    }
}
