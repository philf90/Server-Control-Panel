<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Wo „Anmelden als" seinen Zustand hinterlegt (§6.3).
 *
 * Eine eigene Klasse für eine einzige Zeichenkette — weil sie an drei Stellen
 * gebraucht wird: beim Beginn, beim Rückweg und beim Protokollieren. Als
 * Literal an drei Stellen wäre ein Tippfehler an einer davon ein „Anmelden
 * als", aus dem es keinen Rückweg gibt, oder ein Protokoll, das den Admin
 * verschweigt.
 */
final class Impersonation
{
    /** Enthält die Kennung des Admins, der den Wechsel begonnen hat. */
    public const SESSION_KEY = 'impersonator_account_id';
}
