<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * YAML und Shell ohne ihre Kommentarzeilen — für Wächter, die in Läufen lesen.
 *
 * **Warum es diesen Baustein gibt.** Ein Wächter über `release.yml` sollte
 * belegen, dass die Freigabenotiz nicht mehr als feste Zeile im Lauf steht. Er
 * war beim ersten Durchgang rot — getroffen hat er den Kommentar direkt
 * darüber, der erklärt, was dort früher stand.
 *
 * > **Ein Wächter, der seinen eigenen Kommentar liest, meldet die Erklärung
 * > als den Fehler, den sie erklärt.**
 *
 * Dieselbe Klasse Fehler ist in diesem Repository schon dreimal aufgetreten,
 * jedes Mal in PHP-Quelltext; dafür gibt es `WithoutPhpComments`. In YAML und
 * Shell fehlte sie, und dort wiegt sie schwerer, weil zwei Wächter **zählen**
 * statt zu suchen: Ein Kommentar, der die gesuchte Zeichenkette nennt, macht
 * aus „genau einmal" ein „zweimal" — und der Befund liest sich wie eine zweite
 * Fassung der Regel.
 *
 * **Was er ausdrücklich nicht tut: nachgestellte Kommentare abschneiden.** In
 * `foo: bar # Grund` wäre das richtig — in einem sed-Ausdruck, der selbst eine
 * Raute trennt (version-channel.sh hat einen), und in jeder Zeichenkette mit
 * einer Raute darin wäre es falsch. Beides steht in den Läufen dieses
 * Projekts. Entfernt werden deshalb nur Zeilen, deren erstes Zeichen
 * nach dem Einzug eine Raute ist. Das ist die Form, in der die Kommentare hier
 * geschrieben sind, und der Rest bleibt unangetastet.
 *
 * Die Zeilenumbrüche bleiben stehen — sonst rutschen die Zeilennummern, und
 * eine Fundstelle liesse sich nicht mehr zuordnen. Dieselbe Machart wie bei
 * `WithoutPhpComments`.
 */
trait WithoutHashComments
{
    private function withoutHashComments(string $source): string
    {
        $lines = explode("\n", $source);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                $lines[$index] = '';
            }
        }

        return implode("\n", $lines);
    }
}
