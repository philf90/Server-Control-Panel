<?php

declare(strict_types=1);

namespace App\Support\Ports;

use App\Http\Controllers\UpdatesController;

/**
 * Die Antwort von `system.ports` für die Seite — und der Schnitt, den sie braucht.
 *
 * **Der Port gehört dem Administrator, der Prozessname dem Betreiber**
 * (`docs/109 §2`, Frage 2, entschieden am 7. September 2026). Eine Portnummer
 * ist dieselbe Art Auskunft wie eine installierte Paketfassung, und die ist in
 * `docs/81 §3` Frage 2 ausdrücklich zugelassen. `ss -ltnp` nennt daneben
 * **Prozessnamen** — dass auf 25 ein `postfix` sitzt und auf 11211 ein
 * `memcached`, ist keine Zugangsdatei und kein Weg zu root, aber es ist eine
 * Landkarte.
 *
 * Derselbe Schnitt wie bei der Schlüsselspalte auf `/updates`
 * ({@see UpdatesController}) — und aus demselben Grund
 * **hier** und nicht in der Vue-Datei: Was der Betrachter nicht sehen darf,
 * darf nicht in der Nutzlast stehen. Ein `v-if` verbirgt es im Bild und
 * schickt es trotzdem über die Leitung.
 *
 * > **Eine Grenze, die erst im Browser gezogen wird, ist keine.**
 */
final class ServerPorts
{
    /**
     * Dieselbe Antwort ohne die Prozessspalte.
     *
     * **Die Schlüssel bleiben stehen und werden auf `null` gesetzt**, statt
     * entfernt zu werden. Die Seite unterscheidet drei Fälle — „nicht
     * nachgesehen", „niemand sichtbar" und ein Name —, und ein fehlender
     * Schlüssel wäre ein vierter, den niemand entworfen hat.
     *
     * @param  array<string,mixed>  $state  die Antwort von `system.ports`
     * @return array<string,mixed>
     */
    public static function withoutProcesses(array $state): array
    {
        if (! is_array($state['listeners'] ?? null)) {
            return $state;
        }

        foreach ($state['listeners'] as $i => $lauscher) {
            if (! is_array($lauscher)) {
                continue;
            }

            $state['listeners'][$i]['process'] = null;
            $state['listeners'][$i]['pid'] = null;
        }

        /*
         * **Und `privileged` fällt mit.** Es sagt, ob der *Agent* nachsehen
         * durfte; für einen Betrachter, der die Spalte ohnehin nicht bekommt,
         * ist es eine Auskunft über nichts — und auf der Seite würde daraus
         * „niemand sichtbar" statt „darfst du nicht sehen".
         *
         * > **Ein Feld, das erklärt, warum eine Spalte leer ist, ist falsch,
         * > wenn die Spalte aus einem anderen Grund leer ist.**
         */
        $state['privileged'] = null;

        return $state;
    }
}
