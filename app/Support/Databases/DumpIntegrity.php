<?php

declare(strict_types=1);

namespace App\Support\Databases;

use SrvPanel\Agent\Db\Dump;

/**
 * Stimmt eine abgelegte Sicherung noch mit ihrer Datei überein?
 *
 * **Der Anlass ist eine Zahl, die niemand je geprüft hätte** (`docs/36
 * §22.3w`). Am 9. August 2026 stand auf `cloudsrv24` in `database_dumps.bytes`
 * für eine Sicherung 69255, auf der Platte lagen 69362 — und `bytes` ist genau
 * die Zahl, die dem Kunden als „Grösse" angezeigt wird. Woher die Abweichung
 * dieser einen Zeile kam, war nicht mehr zu klären; der Fund ist, dass es keine
 * Rolle spielte, **weil nichts die beiden je gegeneinander hielt**.
 *
 * Dieselbe Familie wie das `GRANT`, das sein Schema überlebte (§22.3p), und die
 * Zeile, die ihre Datei überlebte (§22.3r): eine Angabe im Bestand, die auf
 * etwas ausserhalb zeigt, ohne dass jemand nachsieht.
 *
 * **Warum das eine eigene Klasse ist und keine Methode im Kommando.** Das
 * Sicherungsverzeichnis liegt fest unter `/var/lib/srvpanel/dumps`
 * ({@see Dump::ROOT}) — dort kann kein Test eine Datei
 * anlegen. Hier bekommt der Vergleich seinen Pfad übergeben und ist damit an
 * jedem Ort prüfbar, ohne dass die Konstante des Agenten aufweicht.
 */
final class DumpIntegrity
{
    /**
     * Der Grund, warum diese Sicherung nicht mehr zu ihrer Datei passt.
     *
     * **Beide Zahlen stehen in der Meldung, nicht nur „weicht ab".** Der
     * Unterschied zwischen 69255 und 69362 sagt einem Betreiber, dass etwas die
     * Datei angefasst hat; „stimmt nicht" sagt ihm nur, dass er selbst
     * nachsehen muss.
     *
     * @return string|null `null`, wenn alles zusammenpasst
     */
    public static function reason(?int $recorded, string $path): ?string
    {
        if (! is_file($path)) {
            return 'die Datei fehlt: '.$path;
        }

        $real = filesize($path);

        // Ohne abgelegte Grösse gibt es nichts zu vergleichen — das ist kein
        // Befund, sondern eine Sicherung aus einer Zeit vor dieser Spalte.
        if ($recorded === null || $real === false) {
            return null;
        }

        return $recorded === $real
            ? null
            : sprintf('Bestand %d Byte, Datei %d Byte', $recorded, $real);
    }
}
