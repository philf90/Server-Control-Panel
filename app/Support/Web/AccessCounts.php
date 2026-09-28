<?php

declare(strict_types=1);

namespace App\Support\Web;

use DateTimeImmutable;
use DateTimeZone;
use SrvPanel\Agent\Ops\WebAccessCount;
use SrvPanel\Agent\Web\AccessLog;

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
 * > **Jeder Grund, aus dem ein Tag keine Zahl bekommt, gehört in seinen eigenen
 * > Topf. Ein gemeinsamer Topf ist eine Zahl ohne Begründung.**
 *
 * Hier stand bis zum 28. September 2026 „Drei Gründe … in drei Töpfe". Seitdem
 * sind es vier, und der Satz zählt nicht mehr mit:
 *
 * > **Eine Zahl im Kommentar altert mit dem Code, den sie zählt, und nichts
 * > meldet es.**
 *
 * **Und seit demselben Tag bekommt ein ruhiger Vortag eine Null** — die
 * Entscheidung des Betreibers zu `docs/138 §6` Frage 2. Bis dahin tauchte eine
 * Domain ohne eine einzige Zeile am Vortag in keinem Topf auf, und die Tabelle
 * bekam für sie nichts. `TrafficEraTest` hielt das ausdrücklich fest und
 * schob die Lücke an B3 weiter — gefüllt hat sie dort niemand, und die Kurve
 * eines ruhigen Abonnements rückte seine Tage zusammen.
 *
 * > **Ein Tag ohne Anfrage ist kein Tag ohne Zahl — die Zahl ist null, und wer
 * > sie nicht ablegt, lässt die Kurve behaupten, es habe ihn nicht gegeben.**
 *
 * **Eine Null bekommt aber nur, wer ganz gelesen ist**: mindestens eine Datei
 * und keine Zeile, die sich nicht deuten liess. Eine unlesbare Zeile trägt
 * keinen Tag, und es kann der Vortag gewesen sein. Wer nicht ganz gelesen ist,
 * steht im vierten Topf, „nicht ganz gelesen", und sein Vortag bleibt ohne
 * Zahl.
 *
 * > **Eine Null, die aus „nicht gelesen" entsteht, ist schlimmer als keine —
 * > sie sieht aus wie eine Messung.**
 *
 * Was diese Klasse dabei nicht sieht: eine Datei, die dasteht und sich nicht
 * öffnen lässt. {@see AccessLog::countFile()} gibt für sie lauter Nullen, wie
 * für eine leere, und der Agent läuft als root — der Kopf von
 * {@see WebAccessCount} nennt die Grenze und warum es keinen Zähler dafür gibt.
 * Bis dahin wurde aus so einer Datei eine Lücke, seit der Null wird daraus eine
 * Null.
 *
 * Diese Klasse ruft nichts und schreibt nichts. Sie bekommt, was der Agent
 * gemeldet hat, und teilt es auf — damit die Regel ohne Agenten, ohne Platte
 * und ohne Uhr geprüft werden kann.
 */
final class AccessCounts
{
    /**
     * Die Meldung des Agenten, aufgeteilt in sechs Töpfe: zählbar und ruhig
     * für die Tage, die eine Zahl bekommen, übersprungen, nicht ganz gelesen,
     * offen und früher für die, die keine bekommen.
     *
     * `$today` ist der laufende Tag in der Zeitrechnung des Servers. Er wird
     * übergeben und nicht hier bestimmt: Eine Klasse, die selbst auf die Uhr
     * sieht, lässt sich nur zur richtigen Tageszeit prüfen. Der Vortag folgt
     * aus ihm und nicht aus einer zweiten Uhr.
     *
     * @param  array<string, mixed>  $result  was `web.access.count` zurückgab
     * @return array{
     *     countable: list<array{subscription:string, domain:string, day:string, requests:int, sent:int, received:int, errors:int}>,
     *     quiet: list<array{subscription:string, domain:string, day:string}>,
     *     skipped: list<array{subscription:string, domain:string, day:string, legacy:int}>,
     *     unread: list<array{subscription:string, domain:string, day:string, files:int, unreadable:int}>,
     *     open: list<array{subscription:string, domain:string, day:string}>,
     *     earlier: list<array{subscription:string, domain:string, day:string}>,
     *     incomplete: int
     * }
     */
    public static function split(array $result, string $today): array
    {
        $zaehlbar = [];
        $ruhig = [];
        $uebersprungen = [];
        $ungelesen = [];
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
            return ['countable' => [], 'quiet' => [], 'skipped' => [], 'unread' => [], 'open' => [], 'earlier' => [], 'incomplete' => $unvollstaendig];
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

            /*
             * Ob der Vortag **überhaupt** vorkommt — gefragt vor jeder anderen
             * Frage an ihn. Ein Vortag, dessen Werte sich nicht lesen lassen,
             * hatte trotzdem Zeilen und ist damit alles, nur nicht ruhig.
             */
            $vortag = false;

            foreach ($tage as $tag => $werte) {
                $tag = (string) $tag;

                if ($tag === $gestern) {
                    $vortag = true;
                }

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

            if ($vortag) {
                continue;
            }

            /*
             * **Kein Vortag in den Zeilen: ruhig — oder nicht ganz gelesen.**
             *
             * Gefragt wird, was der Agent über die Domain im Ganzen meldet, und
             * nicht, was an einem Tag steht; ein Vortag ohne Zeile hat ja keinen
             * Eintrag, an dem etwas stehen könnte. Mindestens eine Datei muss
             * dagewesen sein — ohne eine gibt es kein Protokoll, das „nichts"
             * sagen könnte —, und keine Zeile darf unlesbar gewesen sein: Sie
             * trägt keinen Tag, und es kann der Vortag gewesen sein. Fehlt
             * einer der beiden Werte in der Meldung, zählt er wie null, und die
             * Domain ist nicht ganz gelesen; ein Feld, das der Agent nicht mehr
             * schickt, macht damit aus jeder Null eine Lücke und nicht
             * umgekehrt.
             */
            $dateien = (int) ($eintrag['files'] ?? 0);
            $unrat = (int) ($eintrag['unreadable'] ?? 0);

            if ($dateien > 0 && $unrat === 0) {
                $ruhig[] = ['subscription' => $abonnement, 'domain' => $domain, 'day' => $gestern];

                continue;
            }

            $ungelesen[] = [
                'subscription' => $abonnement,
                'domain' => $domain,
                'day' => $gestern,
                'files' => $dateien,
                'unreadable' => $unrat,
            ];
        }

        return [
            'countable' => $zaehlbar,
            'quiet' => $ruhig,
            'skipped' => $uebersprungen,
            'unread' => $ungelesen,
            'open' => $offen,
            'earlier' => $frueher,
            'incomplete' => $unvollstaendig,
        ];
    }
}
