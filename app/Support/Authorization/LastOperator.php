<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Models\Account;

/**
 * Es muss immer mindestens einen **aktiven Betreiber** geben.
 *
 * ## Warum das eine Klasse ist und keine drei Zeilen im Controller
 *
 * `docs/82 §3` nennt die Aussperrung als dritte Falle, und `docs/82 §8` sagt
 * dazu den Satz, der die Bauart entscheidet:
 *
 * > **Eine Prüfung, die einen von drei Wegen kennt, ist keine Schranke, sondern
 * > ein Hinweisschild an einer von drei Türen.**
 *
 * Die Wege sind **herabstufen**, **sperren** und **löschen**. Sie sehen im
 * Formular verschieden aus und sind dieselbe Handlung: Danach kommt niemand
 * mehr an die Einstellungen dieses Servers. Wer sie einzeln prüft, prüft
 * irgendwann zwei von dreien.
 *
 * ## Gefragt wird nach dem **Zielzustand** und nicht nach der Handlung
 *
 * {@see self::permits()} bekommt, was nachher gelten soll — Rolle und Zustand.
 * Damit muss der Aufrufer nicht wissen, ob er gerade herabstuft oder sperrt;
 * er beschreibt das Ergebnis, und diese Klasse entscheidet.
 *
 * Der naheliegende Entwurf wäre `mayDemote()` und `maySuspend()` gewesen. Er
 * hätte die Entscheidung, **was** eine Aussperrung ist, wieder in den Controller
 * gelegt — und beim dritten Weg hätte dort jemand eine dritte Methode
 * aufgerufen oder eben nicht.
 *
 * > **Eine Prüfung, die die Handlung entgegennimmt, muss jede Handlung kennen.
 * > Eine, die den Zielzustand entgegennimmt, kennt sie alle.**
 *
 * ## Das Löschen gibt es nicht — und das ist keine Lücke, sondern ein Draht
 *
 * `docs/82 §9` lässt das Löschen von Adminkonten bewusst offen, solange das
 * Protokoll den Handelnden über `nullOnDelete()` verliert. Es gibt also heute
 * nur zwei der drei Wege. `LastOperatorTest` prüft beide **und** stellt fest,
 * dass es keinen dritten gibt: Wer eine Löschroute baut, bekommt dort Rot
 * statt einer stillen dritten Tür.
 */
final class LastOperator
{
    /**
     * Darf dieses Konto in diesen Zustand versetzt werden?
     *
     * @param  AdminRole|null  $role  Die Rolle **nachher**; `null` für ein
     *                                Konto, das keine mehr tragen soll.
     * @param  AccountStatus  $status  Der Zustand **nachher**.
     */
    public static function permits(Account $account, ?AdminRole $role, AccountStatus $status): bool
    {
        /*
         * **Der Zielzustand ist selbst ein aktiver Betreiber.** Dann geht
         * keiner verloren, und es ist gleichgültig, wie viele es gibt — auch
         * eine Änderung am Namen läuft durch diesen Zweig.
         */
        if ($role === AdminRole::Operator && $status === AccountStatus::Active) {
            return true;
        }

        return ! self::isLast($account);
    }

    /**
     * Ist dieses Konto der letzte aktive Betreiber?
     *
     * **Gefragt wird der gespeicherte Zustand**, nicht der des Formulars: Wer
     * es selbst nicht ist, kann auch nicht der letzte sein — und eine Änderung
     * an einem Administrator oder an einem gesperrten Konto ist nie eine
     * Aussperrung.
     */
    public static function isLast(Account $account): bool
    {
        if (! $account->isOperator() || $account->status !== AccountStatus::Active) {
            return false;
        }

        // Das Konto selbst zählt mit — bei genau einem ist es das eigene.
        return self::active() <= 1;
    }

    /**
     * Wie viele aktive Betreiber es gibt.
     *
     * **Beide Achsen und der Zustand**, in derselben Reihenfolge wie in
     * {@see Account::isOperator()}. Eine Abfrage, die nur `role = operator`
     * fragte, zählte ein Kundenkonto mit, das die Spalte durch einen Fehler
     * trägt — und liesse damit den letzten echten Betreiber herabstufen.
     *
     * `Account` trägt keine Mandantenklammer (nachgesehen am 24. August 2026),
     * die Zählung braucht also kein `withoutRestriction()`. Stünde hier je
     * eine, wäre diese Zahl aus der Sicht des Fragenden gezählt — und die
     * Sicht des Fragenden ist bei einer Aussperrung genau das Falsche.
     */
    public static function active(): int
    {
        return Account::query()
            ->where('type', AccountType::Admin)
            ->where('role', AdminRole::Operator)
            ->where('status', AccountStatus::Active)
            ->count();
    }

    /**
     * Der Satz, den der Betreiber liest.
     *
     * **Er steht hier und nicht im Controller**, weil ihn zwei Wege brauchen
     * und zwei Formulierungen auseinanderlaufen. Er sagt, **warum** abgelehnt
     * wurde und **was** hilft — eine Ablehnung ohne Ausweg ist eine Sackgasse
     * mit Begründung.
     */
    public static function refusal(): string
    {
        return 'Das ist der letzte aktive Betreiber. Ohne ihn kommt niemand mehr an die '
            .'Einstellungen dieses Servers. Legen Sie zuerst einen zweiten Betreiber an.';
    }
}
