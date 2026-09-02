<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use App\Enums\FindingCheck;
use Illuminate\Support\Carbon;

/**
 * Eine Prüfung des Nachtlaufs, die im Panel läuft (A10 Schritt 5, `docs/98 §3`).
 *
 * ## Warum es diese Naht gibt
 *
 * Vier Prüfungen brauchen kein Systemrecht: Sie fragen, was A2, P4 und
 * `docs/35` gebaut haben — den Unit-Leser, die Zertifikatsablage, die
 * Reservierung der Systembenutzer und die Cron-Dateien. Sie stehen deshalb in
 * `app/` und nicht im Agenten; der Nachtlauf (Schritt 6) fährt sie der Reihe
 * nach und danach `system.diagnose` für alles, was Systemrechte braucht.
 *
 * **Jede Prüfung schreibt genau die Schlüssel, die sie nennt — und schreibt
 * sie ganz.** `FindingLog::replace()` je Schlüssel, wenn sie gemessen hat;
 * `FindingLog::unreachable()`, wenn sie nicht messen konnte. Nie eine leere
 * Liste für „nicht gemessen": Das wäre der Fehler aus `docs/44`, aus „nicht
 * erreichbar" ein „alles in Ordnung" zu machen.
 *
 * **Und jede nennt ihre Gründe als Konstante.** `DiagnoseSeamTest` hält daran,
 * dass jeder Grund, den eine Prüfung ausspricht, dem Katalog bekannt ist — und
 * dass jeder Grund im Katalog einen Sprecher hat. Ohne die Konstante wäre die
 * zweite Richtung nicht zu halten, und ein Grund ohne Sprecher ist ein toter
 * Eintrag, der bei einer Umbenennung entsteht.
 */
interface Check
{
    /**
     * Die Schlüssel, die diese Prüfung schreibt.
     *
     * @return list<FindingCheck>
     */
    public function writes(): array;

    /**
     * Messen und festhalten — mit dem Zeitpunkt des Laufs und nicht dem eigenen.
     *
     * Alle Prüfungen einer Nacht tragen denselben Zeitstempel; sonst stünden auf
     * der Seite vierzehn Werte für „zuletzt gemessen", die sich um Millisekunden
     * unterscheiden.
     */
    public function run(Carbon $measuredAt, FindingLog $log): void;
}
