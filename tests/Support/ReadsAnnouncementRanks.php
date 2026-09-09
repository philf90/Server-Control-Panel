<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Die Ränge einer Ankündigung, gelesen aus der Quelle statt aufgezählt.
 *
 * **Warum das ein eigener Baustein ist und keine Liste je Test.** Zwei Wächter
 * fragen dieselbe Sache: `RankReachTest`, ob jeder Rang seine drei Regeln hat,
 * und `AnnouncementBandTest`, ob jeder Rang seine drei Träger hat. Bis zum
 * 9. September 2026 stand in einem von beiden `['ok', 'warn', 'critical']` —
 * eine Aufzählung, die mit dem Enum nichts verbindet.
 *
 * Aufgefallen ist das, als `Info` seinen eigenen Rang bekam: Der Wächter blieb
 * bei drei Rängen stehen, verlangte weiter eine Regel `.band.ok`, die es nicht
 * mehr gab, und über den vierten sagte er nichts.
 *
 * > **Zwei Leser derselben Marken, die verschieden zählen, sind zwei Fassungen
 * > derselben Regel — und die zweite ist die, die veraltet.**
 *
 * **Gelesen wird als Text und nicht über den Autolader.** Beide Wächter laufen
 * ohne Framework, damit sie in diesem Container fahrbar sind und nicht erst in
 * der CI.
 *
 * **Und gelesen werden die Zweige des `match` und nicht die `@return`-Marke
 * darüber.** Die Marke ist ein Kommentar, und ein Kommentar altert mit dem
 * Code, den er beschreibt — genau daran ist in diesem Repo schon eine Zahl im
 * Kommentar veraltet, ohne dass es jemand meldete.
 */
trait ReadsAnnouncementRanks
{
    /** @return list<string> */
    private function announcementRanks(): array
    {
        $quelle = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Enums/AnnouncementCategory.php',
        );

        $start = strpos($quelle, 'public function badge(): string');

        if (! is_int($start)) {
            return [];
        }

        $ende = strpos($quelle, 'public function', $start + 10);
        $rumpf = substr($quelle, $start, ($ende === false ? strlen($quelle) : $ende) - $start);

        preg_match_all("/=>\s*'([a-z-]+)'/", $rumpf, $treffer);

        return array_values(array_unique($treffer[1]));
    }
}
