<?php

declare(strict_types=1);

namespace App\Support\Panel;

/**
 * Wohin der Quelltext-Link in der Fusszeile zeigt.
 *
 * ## Die Auflage, und warum sie bisher nicht eingelöst war
 *
 * Abschnitt 13 der AGPL verlangt, dass wer die Software über das Netz benutzt,
 * an ihren Quelltext kommt — **an den der laufenden Fassung**, nicht bloss an
 * das Repository. Genau so steht es als Begründung über
 * `config('srvpanel.source')`.
 *
 * Eingelöst war es nicht. Der Link hing an `SRVPANEL_COMMIT`, und diese
 * Umgebungsvariable wird nirgends gesetzt: nicht vom Paket, nicht vom
 * Freigabelauf, nicht von der Einrichtung — in `.env.example` steht sie leer.
 * Die Fusszeile zeigte damit auf jedem Server auf `main`, also auf einen Stand,
 * der mit dem laufenden nichts zu tun haben muss.
 *
 * **Es ist derselbe Fund wie bei {@see Release}, nur eine Datei weiter.** Dort
 * war es `SRVPANEL_VERSION` mit `0.1.0-dev` als Vorgabe; hier ist es
 * `SRVPANEL_COMMIT` mit der leeren Zeichenkette. Beide Male sah die Zeile
 * richtig aus, und beide Male fehlte das, was sie lesen wollte. Gefunden am
 * 10. August 2026, als der Fassungsbefehl entstand — die zweite Stelle fällt
 * nur auf, wenn man nach der ersten *danach sucht*.
 *
 * ## Warum die Fassung genügt und der Commit nicht nötig ist
 *
 * Eine Freigabe ist ein annotierter Tag `v<fassung>` auf `main`, und das
 * Verzeichnis der Auslieferung trägt dieselbe Fassung. `v0.5.1-rc.3` zeigt
 * damit auf genau den Stand, der läuft — **ohne dass irgendjemand beim Bauen
 * etwas setzen muss.** Eine Angabe, die aus dem entsteht, was ohnehin da ist,
 * kann nicht vergessen werden; eine, die gesetzt werden muss, wird es.
 *
 * Der Commit bleibt trotzdem der genauere Verweis und hat deshalb Vorrang:
 * Setzt ein Freigabelauf ihn eines Tages, zeigt der Link auf den Stand und
 * nicht auf den Tag, der sich theoretisch verschieben liesse.
 *
 * ## Und im Quellbaum steht das Repository
 *
 * Dort gibt es weder Tag noch Commit, und ein erfundener Verweis wäre schlimmer
 * als der allgemeine: Ein Link auf `tree/v` oder `tree/` führt ins Leere, und
 * ein toter Link löst keine Auflage ein.
 */
final class Source
{
    /**
     * Die Adresse für die Fusszeile — eine Antwort, im Server entschieden.
     *
     * **Die Wahl stand vorher im Template**, als Bedingung über
     * `source.commit`. Das war eine zweite Fassung derselben Regel an der
     * Stelle, an der man sie am wenigsten sucht: Wer die Herkunft der Angabe
     * ändert, ändert die Vorlage nicht mit. Die Oberfläche bekommt jetzt eine
     * fertige Adresse und keine Zutaten.
     */
    public static function url(): string
    {
        return self::of(
            (string) config('srvpanel.source.repository'),
            (string) config('srvpanel.source.commit'),
            Release::version(),
        );
    }

    /**
     * Dieselbe Wahl aus drei Angaben — getrennt, damit sie prüfbar ist.
     *
     * **Dieselbe Bauart wie {@see Release::of()}, und aus demselben Grund.** Im
     * Test läuft die Anwendung immer im Quellbaum, also gäbe es an
     * {@see self::url()} allein genau einen der drei Fälle zu sehen — und zwar
     * den, der auf keinem Server vorkommt.
     */
    public static function of(string $repository, string $commit, string $version): string
    {
        $repository = rtrim($repository, '/');
        $commit = trim($commit);

        if ($commit !== '') {
            return $repository.'/tree/'.$commit;
        }

        if ($version === Release::UNRELEASED) {
            return $repository;
        }

        // Das `v` gehört dem Tag und nicht dem Verzeichnis — `Release` liefert
        // die Version ohne, `git tag` vergibt sie mit.
        return $repository.'/tree/v'.$version;
    }
}
