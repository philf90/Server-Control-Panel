<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein Formular, das bestehende Werte ändert, springt nach dem Speichern nicht
 * auf den Stand vom Seitenaufbau zurück.
 *
 * ## Der Befund
 *
 * Befund 11 aus `docs/84`. Auf der Zugangsseite stand
 * `onSuccess: () => form.reset()`. `reset()` stellt die Werte her, die das
 * Formular **beim Laden** hatte — nach dem Speichern also den Stand *davor*.
 * Eine gelöschte Zeile kam zurück, obwohl der Server sie entfernt hatte.
 *
 * Und es blieb nicht bei der Anzeige: Inertia übernimmt nach einem
 * erfolgreichen Absenden `form.data()` als neuen Ausgangswert, aber **nach**
 * diesem Rückruf — der alte Stand wurde damit zur Grundlage. Wer die
 * wiedergekehrte Zeile für einen Fehlschlag hielt und noch einmal speicherte,
 * legte die Beschränkung wieder an, die er gerade aufgehoben hatte. Beide
 * Vorgänge meldeten Erfolg.
 *
 * > **Eine Anzeige, die den Zustand vor der Änderung zeigt, verleitet zu der
 * > Handlung, die die Änderung zurücknimmt.**
 *
 * ## Warum die Regel an `props` hängt und nicht an `reset()`
 *
 * `reset()` ist für ein **Anlege**-Formular genau richtig: Nach dem Absenden
 * sollen die Felder wieder leer sein, und leer *ist* der Ausgangswert. Dieses
 * Repo tut das an sechs Stellen, und alle sechs sind in Ordnung.
 *
 * Falsch wird es, sobald der Ausgangswert aus `props` kommt — dann ist er der
 * Zustand des Servers **vor** der Änderung. Das Merkmal ist also nicht der
 * Aufruf, sondern woher das Formular seine Werte hat.
 *
 * > **Ein Handgriff, der für ein Anlege-Formular richtig ist, ist für eine
 * > Änderungsmaske das Gegenteil.**
 *
 * ## Was ausdrücklich erlaubt bleibt
 *
 * `reset()` ausserhalb von `onSuccess` — ein „Abbrechen", das auf den Stand vom
 * Laden zurückgeht, ist genau das, was der Knopf verspricht (`Cron.vue`).
 * Und `reset()` **nach** einem `defaults(…)` im selben Rückruf: Dann ist der
 * Ausgangswert vorher neu gesetzt worden, und `reset()` legt ihn auf.
 */
final class FormResetTest extends TestCase
{
    public function test_no_edit_form_resets_to_the_state_before_saving(): void
    {
        $funde = [];
        $formulare = 0;

        foreach ($this->components() as $pfad => $roh) {
            $quelle = $this->withoutComments($roh);
            $namen = $this->formsSeededFromProps($quelle);

            if ($namen === []) {
                continue;
            }

            $formulare += count($namen);

            foreach ($this->successRegions($quelle) as $stelle => $bereich) {
                foreach ($namen as $name) {
                    if (preg_match('/\b'.preg_quote($name, '/').'\.reset\(\s*\)/', $bereich) !== 1) {
                        continue;
                    }

                    if (str_contains($bereich, $name.'.defaults(')) {
                        continue;
                    }

                    $funde[] = sprintf(
                        '%s:%d — %s.reset() in onSuccess, und %s liest seine Werte aus props',
                        $pfad,
                        substr_count(substr($quelle, 0, $stelle), "\n") + 1,
                        $name,
                        $name,
                    );
                }
            }
        }

        /*
         * **Die Untergrenze.** Ohne sie wäre dieser Test auch dann grün, wenn
         * der Ausdruck für `useForm` ins Leere liefe — und dann sagt er nichts.
         *
         * > Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
         * > Null steht.
         */
        $this->assertGreaterThan(10, $formulare,
            'Es wurden fast keine props-gespeisten Formulare gefunden — der Ausdruck greift nicht mehr.');

        $this->assertSame([], $funde, sprintf(
            "Diese Formulare springen nach dem Speichern auf den Stand vom Seitenaufbau zurück:\n  %s\n\n".
            'Nach dem Speichern gehört der Stand des Servers ins Formular — entweder gar kein reset(), '
            .'dann übernimmt Inertia die abgeschickten Werte, oder defaults(…) mit den frischen props '
            .'und danach reset().',
            implode("\n  ", $funde),
        ));
    }

    /**
     * Der Quelltext mit **ausgeblendeten** Kommentaren.
     *
     * **Sein erster Lauf war rot auf der behobenen Datei.** Der Kommentar, der
     * den Befund erklärt, enthält `onSuccess: () => form.reset()` als Zitat —
     * und der Wächter fand darin genau das, wogegen er gebaut ist.
     *
     * > **Ein Wächter, der Kommentare mitliest, findet seine eigene
     * > Begründung.**
     *
     * Derselbe Fehler wie in `AccountAccessReachTest`, am selben Tag und in
     * einer anderen Sprache. Dort schneidet `token_get_all()`; hier gibt es das
     * nicht, also läuft ein kleiner Abtaster über die Zeichenketten hinweg.
     *
     * **Ausgeblendet und nicht entfernt:** Die Zeichen werden durch Leerzeichen
     * ersetzt, damit die Stellen und damit die gemeldeten Zeilennummern
     * stimmen.
     *
     * **Was er nicht kann:** ein reguläres Ausdrucksliteral von einer Division
     * unterscheiden. In diesem Verzeichnis kommt keines vor — käme eines dazu,
     * das `//` enthält, meldete dieser Wächter zu wenig statt zu viel.
     */
    private function withoutComments(string $quelle): string
    {
        $aus = $quelle;
        $laenge = strlen($quelle);
        $i = 0;

        while ($i < $laenge) {
            $c = $quelle[$i];

            // Zeichenketten werden übersprungen, nicht gelesen.
            if ($c === "'" || $c === '"' || $c === '`') {
                $i++;

                while ($i < $laenge && $quelle[$i] !== $c) {
                    $i += $quelle[$i] === '\\' ? 2 : 1;
                }

                $i++;

                continue;
            }

            if ($c === '/' && $i + 1 < $laenge && $quelle[$i + 1] === '*') {
                $ende = strpos($quelle, '*/', $i + 2);
                $ende = $ende === false ? $laenge : $ende + 2;
                $aus = $this->blank($aus, $i, $ende);
                $i = $ende;

                continue;
            }

            if ($c === '/' && $i + 1 < $laenge && $quelle[$i + 1] === '/') {
                $ende = strpos($quelle, "\n", $i);
                $ende = $ende === false ? $laenge : $ende;
                $aus = $this->blank($aus, $i, $ende);
                $i = $ende;

                continue;
            }

            // Auch HTML-Kommentare der Vorlage.
            if ($c === '<' && substr($quelle, $i, 4) === '<!--') {
                $ende = strpos($quelle, '-->', $i + 4);
                $ende = $ende === false ? $laenge : $ende + 3;
                $aus = $this->blank($aus, $i, $ende);
                $i = $ende;

                continue;
            }

            $i++;
        }

        return $aus;
    }

    /** Einen Abschnitt durch Leerzeichen ersetzen, Zeilenumbrüche behalten. */
    private function blank(string $quelle, int $von, int $bis): string
    {
        for ($i = $von; $i < $bis && $i < strlen($quelle); $i++) {
            if ($quelle[$i] !== "\n") {
                $quelle[$i] = ' ';
            }
        }

        return $quelle;
    }

    /**
     * Die Namen der Formulare, deren Ausgangswerte aus `props` kommen.
     *
     * @return list<string>
     */
    private function formsSeededFromProps(string $quelle): array
    {
        $namen = [];

        if (preg_match_all('/const\s+(\w+)\s*=\s*useForm[^(]*\(/', $quelle, $treffer, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($treffer[1] as $i => $name) {
            $start = (int) $treffer[0][$i][1] + strlen($treffer[0][$i][0]) - 1;
            $rumpf = $this->balanced($quelle, $start, '(', ')');

            if (str_contains($rumpf, 'props.')) {
                $namen[] = (string) $name[0];
            }
        }

        return $namen;
    }

    /**
     * Die `onSuccess`-Eigenschaften einer Quelle, Anfangsstelle zu Text.
     *
     * Der Bereich endet am Komma oder an der schliessenden Klammer des
     * umgebenden Objekts — beide Schreibweisen kommen vor, mit und ohne
     * geschweifte Klammern im Rückruf.
     *
     * @return array<int, string>
     */
    private function successRegions(string $quelle): array
    {
        $bereiche = [];
        $offset = 0;

        while (($i = strpos($quelle, 'onSuccess', $offset)) !== false) {
            $bereiche[$i] = $this->property($quelle, $i);
            $offset = $i + 9;
        }

        return $bereiche;
    }

    /** Von einer Eigenschaft bis zu ihrem Ende — Komma oder Ende des Objekts. */
    private function property(string $quelle, int $start): string
    {
        $tiefe = ['(' => 0, '{' => 0, '[' => 0];
        $paar = [')' => '(', '}' => '{', ']' => '['];

        for ($i = $start; $i < strlen($quelle); $i++) {
            $c = $quelle[$i];

            if (isset($tiefe[$c])) {
                $tiefe[$c]++;
            } elseif (isset($paar[$c])) {
                $tiefe[$paar[$c]]--;

                if ($paar[$c] === '{' && $tiefe['{'] < 0) {
                    return substr($quelle, $start, $i - $start);
                }
            } elseif ($c === ',' && $tiefe['('] === 0 && $tiefe['{'] === 0 && $tiefe['['] === 0) {
                return substr($quelle, $start, $i - $start);
            }
        }

        return substr($quelle, $start);
    }

    /** Der geklammerte Abschnitt ab `$start`, einschliesslich der Klammern. */
    private function balanced(string $quelle, int $start, string $auf, string $zu): string
    {
        $tiefe = 0;

        for ($i = $start; $i < strlen($quelle); $i++) {
            if ($quelle[$i] === $auf) {
                $tiefe++;
            } elseif ($quelle[$i] === $zu) {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($quelle, $start, $i - $start + 1);
                }
            }
        }

        return substr($quelle, $start);
    }

    /**
     * Alle Seiten, Pfad zu Quelltext.
     *
     * @return array<string, string>
     */
    private function components(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/resources/js/Pages', FilesystemIterator::SKIP_DOTS)
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $dateien[substr($datei->getPathname(), strlen($wurzel) + 1)]
                    = (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }
}
