<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Markup ohne seine Kommentare — für Wächter, die im Code lesen.
 *
 * Drei Formen, weil dieses Repo drei benutzt: `/* … *\/` und `// …` im
 * `<script setup>`, `<!-- … -->` in einer Vorlage und `{{-- … --}}` in Blade.
 * Die letzte ist dazugekommen, als der erste Wächter über eine Blade-Datei
 * lief und prompt den Kommentar meldete, der die Regel begründet.
 *
 * **Der Zwilling von {@see WithoutPhpComments}, und aus demselben Grund.** Dort
 * steht, wie drei Wächter dieselbe kaputte Zeile abgeschrieben hatten und einer
 * davon grün blieb, während seine Regel gebrochen war. Für PHP beantwortet
 * `token_get_all()` die Frage exakt; für Vue gibt es das nicht, also läuft hier
 * ein Abtaster, der Zeichenketten überspringt statt sie zu lesen.
 *
 * **Warum es ihn überhaupt braucht.** Am 25. August 2026 sind zwei frisch
 * gebaute Wächter an ihrem eigenen Kommentar rot geworden: Wer aufschreibt,
 * *was* falsch war, schreibt den falschen Ausdruck hin.
 *
 * > **Ein Wächter, der Kommentare mitliest, findet seine eigene Begründung.**
 *
 * Und für die Oberfläche ist das keine Umgehung, sondern die Regel richtig
 * gesagt: Ein Kommentar in einer `.vue` ist Text für den, der sie baut, und
 * nicht für den, der die Seite ansieht.
 *
 * **Ausgeblendet und nicht entfernt.** Die Zeichen werden durch Leerzeichen
 * ersetzt, Zeilenumbrüche bleiben stehen — sonst rutschen die Stellen, und eine
 * Fundstelle liesse sich weder zuordnen noch mit einer Zeilennummer melden.
 *
 * **Was er nicht kann:** ein reguläres Ausdrucksliteral von einer Division
 * unterscheiden. Käme eines mit `//` darin dazu, meldete ein Wächter darauf zu
 * wenig statt zu viel — der Fehler fällt zur lauten Seite, weil die
 * Untergrenzen der Wächter dann anschlagen.
 */
trait WithoutMarkupComments
{
    private function withoutMarkupComments(string $quelle): string
    {
        $aus = $quelle;
        $laenge = strlen($quelle);
        $i = 0;

        while ($i < $laenge) {
            $c = $quelle[$i];

            // Zeichenketten werden übersprungen, nicht gelesen: In ihnen steht
            // jedes `//` einer Adresse, und jedes `/*` einer Meldung.
            if ($c === "'" || $c === '"' || $c === '`') {
                $i++;

                while ($i < $laenge && $quelle[$i] !== $c) {
                    $i += $quelle[$i] === '\\' ? 2 : 1;
                }

                $i++;

                continue;
            }

            $ende = match (true) {
                $c === '/' && substr($quelle, $i, 2) === '/*' => $this->until($quelle, $i, '*/', 2),
                $c === '/' && substr($quelle, $i, 2) === '//' => $this->until($quelle, $i, "\n", 0),
                $c === '<' && substr($quelle, $i, 4) === '<!--' => $this->until($quelle, $i, '-->', 3),
                $c === '{' && substr($quelle, $i, 4) === '{{--' => $this->until($quelle, $i, '--}}', 4),
                default => null,
            };

            if ($ende === null) {
                $i++;

                continue;
            }

            $aus = $this->blank($aus, $i, $ende);
            $i = $ende;
        }

        return $aus;
    }

    /** Die Stelle hinter dem nächsten `$marke` — oder das Ende der Quelle. */
    private function until(string $quelle, int $von, string $marke, int $dazu): int
    {
        $treffer = strpos($quelle, $marke, $von + 2);

        return $treffer === false ? strlen($quelle) : $treffer + $dazu;
    }

    /** Einen Abschnitt durch Leerzeichen ersetzen, Zeilenumbrüche behalten. */
    private function blank(string $quelle, int $von, int $bis): string
    {
        $laenge = strlen($quelle);

        for ($i = $von; $i < $bis && $i < $laenge; $i++) {
            if ($quelle[$i] !== "\n") {
                $quelle[$i] = ' ';
            }
        }

        return $quelle;
    }
}
