<?php

declare(strict_types=1);

namespace App\Support\Notify;

/**
 * Wie eine Zustellung ausgegangen ist.
 *
 * **Drei Ausgänge und nicht zwei**, und der dritte ist der Grund für diese
 * Aufzählung: *Kein Empfänger* ist weder Erfolg noch Fehlschlag. Ein
 * Abonnement, dessen Kunde kein Konto mit Adresse hat, ist nichts, was ein
 * erneuter Versuch heilt — und ein `failed` daneben färbte die Unit rot für
 * einen Zustand, den der Betreiber auf der Kontenseite behebt und nicht am
 * Mailweg.
 *
 * > **Ein Griff, der sich weigert, und einer, der niemanden findet, geben
 * > dieselbe Antwort — und nur der erste ist eine Auskunft, die jemand
 * > braucht.** Derselbe Satz wie bei `Store::removeDirectory()` aus P8.
 *
 * **Gebucht wird allein {@see self::Sent}.** Die beiden anderen lassen den
 * Befund fällig; was nicht ankam, wird in der nächsten Nacht erneut versucht.
 */
enum Delivery
{
    /** Angekommen — und nur dieser Ausgang schreibt eine Zeile. */
    case Sent;

    /** Niemand da, dem zuzustellen wäre. Kein Fehlschlag des Kanals. */
    case WithoutRecipient;

    /** Der Kanal hat es versucht und es ist nicht angekommen. */
    case Failed;
}
