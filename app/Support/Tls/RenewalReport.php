<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Support\Tenancy\Tenancy;

/**
 * Was ein Erneuerungslauf getan hat.
 *
 * **Als Gegenstand und nicht als Ablage**, damit der Lauf hinter
 * {@see Tenancy::withoutRestriction()} eine Form behält, die sich prüfen
 * lässt: Der Rückgabewert von dort ist `mixed`, und was daraus wieder
 * herauskommt, muss sich benennen lassen.
 *
 * `due` sind die fälligen Zertifikate, `ordered` die bestellten, `corrected`
 * die, die schon erneuert im Ablageort lagen und nur im Bestand nachgetragen
 * wurden — und `left` die, die dieser Lauf hat liegen lassen.
 *
 * **`left` steht hier, weil eine Grenze, die niemand meldet, wie „alles
 * erledigt" aussieht.** Der nächste Lauf ist einen Tag später; bei vielen
 * Domains dauert das Aufholen dann seine Zeit, und das gehört auf den Schirm
 * dessen, der zusieht.
 */
final class RenewalReport
{
    public function __construct(
        public readonly int $due = 0,
        public readonly int $ordered = 0,
        public readonly int $corrected = 0,
        public readonly int $left = 0,
    ) {}
}
