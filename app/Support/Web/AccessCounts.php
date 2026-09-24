<?php

declare(strict_types=1);

namespace App\Support\Web;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Welche Tage aus `web.access.count` gezählt werden dürfen — und welche nicht.
 *
 * **Die Regel steht in `docs/129 §5` und ist eine Entscheidung, keine
 * Bequemlichkeit.** Beim Übergang auf das neue Protokollformat tragen alte
 * Zeilen acht Felder und neue neun. Der Plan hatte zwei Wege zur Wahl und hat
 * den zweiten genommen:
 *
 * > **Ein Zähler, der zwei Formate mischt, liefert eine Zahl, die niemand
 * > nachrechnen kann — und sie sieht aus wie eine Zahl.**
 *
 * Gezählt wird deshalb erst der Tag, der **vollständig** im neuen Format
 * geschrieben ist. Erkannt wird das an den Zeilen selbst und nicht an einem
 * Datum: Ein Datum wüsste nicht, wann die Vorlage auf diesem Server ausgerollt
 * wurde, und zwei Domains können an verschiedenen Tagen umgestellt worden
 * sein.
 *
 * **Und der angefangene Tag zählt auch nicht.** Er ist nicht falsch, er ist
 * nur noch nicht fertig. Er steht als eigener Topf da, damit „noch nicht
 * vorbei" nicht wie „übersprungen" aussieht.
 *
 * **Und seit dem 24. September 2026 auch kein Tag vor dem Vortag**
 * (`docs/134 §0` Punkt 2). Der Agent liest drei Dateien, und darin steht
 * neben dem Vortag auch Älteres — aber nicht mehr ganz: Mit jeder Rotation
 * wandert der Kopf eines Tages eine Datei weiter, und nach der zweiten liegt
 * er ausserhalb dessen, was gelesen wird. Ein solcher Tag hatte seine Nacht.
 * Bis dahin wurde er wieder abgelegt, und die spätere, unvollständige Sicht
 * überschrieb die frühere.
 *
 * > **Ein Lauf, der denselben Tag mehrfach sieht und überschreibt, behält die
 * > letzte Sicht — und die letzte ist nicht die vollständigste.**
 *
 * > **Drei Gründe, aus denen ein Tag keine Zahl bekommt, gehören in drei
 * > Töpfe. Ein gemeinsamer Topf ist eine Zahl ohne Begründung.**
 *
 * Diese Klasse ruft nichts und schreibt nichts. Sie bekommt, was der Agent
 * gemeldet hat, und teilt es auf — damit die Regel ohne Agenten, ohne Platte
 * und ohne Uhr geprüft werden kann.
 */
final class AccessCounts
{
    /**
     * Die Meldung des Agenten, aufgeteilt in vier Töpfe.
     *
     * `$today` ist der laufende Tag in der Zeitrechnung des Servers. Er wird
     * übergeben und nicht hier bestimmt: Eine Klasse, die selbst auf die Uhr
     * sieht, lässt sich nur zur richtigen Tageszeit prüfen. Der Vortag folgt
     * aus ihm und nicht aus einer zweiten Uhr.
     *
     * @param  array<string, mixed>  $result  was `web.access.count` zurückgab
     * @return array{
     *     countable: list<array{subscription:string, domain:string, day:string, requests:int, sent:int, received:int, errors:int}>,
     *     skipped: list<array{subscription:string, domain:string, day:string, legacy:int}>,
     *     open: list<array{subscription:string, domain:string, day:string}>,
     *     earlier: list<array{subscription:string, domain:string, day:string}>,
     *     incomplete: int
     * }
     */
    public static function split(array $result, string $today): array
    {
        $zaehlbar = [];
        $uebersprungen = [];
        $offen = [];
        $frueher = [];

        /*
         * **Als Datum gerechnet und nicht als Zeitpunkt.** Ein Tag ohne
         * Uhrzeit in UTC kennt keine Zeitumstellung; ein „vor 24 Stunden" in
         * der Zone des Servers träfe zweimal im Jahr den falschen Tag.
         */
        $gestern = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');

        $domains = $result['domains'] ?? [];

        /*
         * **`incomplete` zählt, was der Agent gar nicht erst angesehen hat.**
         *
         * Sein Budget lässt Domains liegen, statt in das Zeitlimit des
         * Aufrufers zu laufen und dem Panel *nichts* zu liefern. Diese Zahl
         * steht hier und nicht im Kommando, weil sie eine Regel trägt — ein
         * Lauf mit liegengebliebenen Domains hat seinen Tag nicht fertig
         * gezählt und ist ein Fehlschlag. Und eine Regel im Kommando lässt
         * sich nur mit einem Agenten prüfen.
         *
         * > **Eine Regel, die nur mit halbem Server zu prüfen ist, wird nicht
         * > geprüft.**
         */
        $pending = $result['pending'] ?? [];
        $unvollstaendig = is_array($pending) ? count($pending) : 0;

        if (! is_array($domains)) {
            return ['countable' => [], 'skipped' => [], 'open' => [], 'earlier' => [], 'incomplete' => $unvollstaendig];
        }

        foreach ($domains as $eintrag) {
            if (! is_array($eintrag)) {
                continue;
            }

            $abonnement = (string) ($eintrag['subscription'] ?? '');
            $domain = (string) ($eintrag['domain'] ?? '');
            $tage = $eintrag['days'] ?? [];

            if ($abonnement === '' || $domain === '' || ! is_array($tage)) {
                continue;
            }

            foreach ($tage as $tag => $werte) {
                $tag = (string) $tag;

                if (! is_array($werte)) {
                    continue;
                }

                /*
                 * **Der laufende Tag zuerst, und zwar vor der Formatfrage.**
                 * Sonst stünde ein angefangener Tag mit alten Zeilen unter
                 * „übersprungen", und der Betreiber suchte nach einem
                 * Server-Block, der längst umgestellt ist.
                 */
                if ($tag >= $today) {
                    $offen[] = ['subscription' => $abonnement, 'domain' => $domain, 'day' => $tag];

                    continue;
                }

                /*
                 * **Ein Tag vor dem Vortag hatte seine Nacht** — und auch er
                 * steht vor der Formatfrage. Der Übergangstag liegt eine Nacht
                 * später noch in `.2.gz`; ohne diese Reihenfolge stünde er ein
                 * zweites Mal unter „übersprungen", mit dem Rat, einen Block
                 * umzustellen, der längst umgestellt ist.
                 */
                if ($tag < $gestern) {
                    $frueher[] = ['subscription' => $abonnement, 'domain' => $domain, 'day' => $tag];

                    continue;
                }

                $alt = (int) ($werte['legacy'] ?? 0);

                if ($alt > 0) {
                    $uebersprungen[] = [
                        'subscription' => $abonnement,
                        'domain' => $domain,
                        'day' => $tag,
                        'legacy' => $alt,
                    ];

                    continue;
                }

                $zaehlbar[] = [
                    'subscription' => $abonnement,
                    'domain' => $domain,
                    'day' => $tag,
                    'requests' => (int) ($werte['requests'] ?? 0),
                    'sent' => (int) ($werte['sent'] ?? 0),
                    'received' => (int) ($werte['received'] ?? 0),
                    'errors' => (int) ($werte['errors'] ?? 0),
                ];
            }
        }

        return ['countable' => $zaehlbar, 'skipped' => $uebersprungen, 'open' => $offen, 'earlier' => $frueher, 'incomplete' => $unvollstaendig];
    }
}
