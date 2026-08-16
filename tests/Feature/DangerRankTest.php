<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Der auslösende Knopf und der zustimmende Knopf tragen dieselbe Farbe.
 *
 * ## Der Fund
 *
 * Der Betreiber hat am 16. August 2026 gefragt, ob sich die Aktionen nach ihrer
 * Kritikalität farblich unterscheiden lassen und ob es dafür eine Definition
 * gibt. Es gibt eine — Plan §7.2, drei Ränge, `.button.danger` ist „was sich
 * nicht zurücknehmen lässt". **Sie wurde an sechs Stellen nicht eingehalten**,
 * und eine davon stand auf derselben Seite zweimal:
 *
 * In der Dateiliste war „Entfernen" in der Auswahlleiste rot und dasselbe
 * „Entfernen" in der Zeile darunter grau. Gleiche Handlung, gleiche Seite, zwei
 * Erscheinungen — je nachdem, über welchen Weg man sie auslöste.
 *
 * ## Warum dieser Vergleich und keine Liste
 *
 * „Ist diese Handlung kritisch?" kann kein Test beantworten. Was er beantworten
 * kann: **Sagen die beiden Stellen dasselbe, an denen dieses Panel es schon
 * hinschreibt?** Der Knopf sagt es über `.danger`, die Rückfrage über ihr
 * viertes Argument — und das ist derselbe Satz, zweimal.
 *
 * > **Zwei Angaben über dieselbe Sache sind keine Prüfung, solange niemand sie
 * > nebeneinanderlegt.**
 *
 * Vor diesem Wächter gab es zwei Vokabulare: `.danger` hiess „unumkehrbar",
 * `destructive` hiess „danach ist etwas anders". „Sperren" fragte rot nach und
 * ist umkehrbar; „Zurückspielen" fragte rot nach, überschreibt den Bestand und
 * hatte einen grauen Knopf. Seitdem heisst beides dasselbe.
 *
 * ## Was er nicht sieht
 *
 * Ein Knopf ohne `@click` auf eine Funktion dieser Vorlage — ein `type="submit"`
 * in einem Formular etwa — kommt hier nicht vor. Das ist eine Lücke, und sie
 * wird **gezählt** statt verschwiegen: `test_the_uncovered_buttons_are_counted`
 * hält ihre Zahl fest, damit sie kleiner werden kann und nicht unbemerkt wächst.
 *
 * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
 * > kleiner werden kann.**
 */
final class DangerRankTest extends TestCase
{
    /**
     * Wie viele rote Knöpfe über ein Formular auslösen statt über `@click`.
     *
     * Heute sind es drei, und sie sind nicht dasselbe:
     *
     * - `Databases/Show.vue` „Datenbank entfernen" und `Subscriptions/Show.vue`
     *   „Zurückbauen" tragen die **stärkere** Rückfrage: Man tippt den Namen ab.
     *   Beide sind unumkehrbar, beide sind rot, und dass kein `ask()` dabei ist,
     *   ist richtig.
     * - `Auth/TwoFactorSetup.vue` „Abschalten" ist der eine Fall, in dem die
     *   Regel und das Aussehen auseinandergehen: Die zweite Stufe lässt sich
     *   wieder einschalten, also ist die Handlung umkehrbar — rot ist sie
     *   trotzdem, weil sie eine Absicherung wegnimmt. **Benannt offen**, nicht
     *   stillschweigend entschieden.
     *
     * Wer die Zahl senkt, senkt sie hier mit; wer sie hebt, begründet es.
     */
    private const UNCOVERED = 3;

    /**
     * @return list<string>
     */
    private function templates(): array
    {
        $dateien = [];
        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                dirname(__DIR__, 2).'/resources/js',
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        /** @var SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->getExtension() === 'vue') {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    /**
     * Ab `$von` bis zur schliessenden Klammer — Zeichenketten und Kommentare
     * übersprungen.
     *
     * **Der Aufsatz muss Kommentare kennen und nicht bloss Anführungszeichen.**
     * Der erste Lauf ist genau daran gescheitert: In einem Kommentar neben dem
     * vierten Argument stand ein deutsches Anführungszeichen, der Leser hielt
     * es für den Anfang einer Zeichenkette und verschluckte den Rest des
     * Aufrufs. Das Argument war da, und der Wächter sah es nicht.
     *
     * > **Ein Leser, der Kommentare für Text hält, liest den Code nicht, den er
     * > prüft.**
     */
    private function endOfCall(string $quelle, int $von): int
    {
        $tiefe = 1;
        $i = $von;
        $länge = strlen($quelle);

        while ($tiefe > 0 && $i < $länge) {
            $i = $this->skip($quelle, $i);

            if ($i >= $länge) {
                break;
            }

            $tiefe += match ($quelle[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            $i++;
        }

        return $i - 1;
    }

    /**
     * Steht an `$i` eine Zeichenkette oder ein Kommentar, dann dahinter.
     */
    private function skip(string $quelle, int $i): int
    {
        $länge = strlen($quelle);

        if ($i + 1 < $länge && $quelle[$i] === '/' && $quelle[$i + 1] === '/') {
            while ($i < $länge && $quelle[$i] !== "\n") {
                $i++;
            }

            return $i;
        }

        if (! in_array($quelle[$i], ['"', "'", '`'], true)) {
            return $i;
        }

        $anführung = $quelle[$i];
        $i++;

        while ($i < $länge) {
            if ($quelle[$i] === '\\') {
                $i += 2;

                continue;
            }

            if ($quelle[$i] === $anführung) {
                return $i + 1;
            }

            $i++;
        }

        return $i;
    }

    /**
     * Die Argumente eines Aufrufs, auf oberster Ebene getrennt.
     *
     * @return list<string>
     */
    private function arguments(string $quelle, int $von, int $bis): array
    {
        $teile = [];
        $aktuell = '';
        $tiefe = 0;
        $i = $von;

        while ($i < $bis) {
            $weiter = $this->skip($quelle, $i);

            if ($weiter !== $i) {
                $aktuell .= substr($quelle, $i, $weiter - $i);
                $i = $weiter;

                continue;
            }

            $zeichen = $quelle[$i];

            if (in_array($zeichen, ['(', '[', '{'], true)) {
                $tiefe++;
            }

            if (in_array($zeichen, [')', ']', '}'], true)) {
                $tiefe--;
            }

            if ($zeichen === ',' && $tiefe === 0) {
                $teile[] = $aktuell;
                $aktuell = '';
                $i++;

                continue;
            }

            $aktuell .= $zeichen;
            $i++;
        }

        $teile[] = $aktuell;

        return $teile;
    }

    /**
     * Der Rumpf einer benannten Funktion dieser Vorlage.
     */
    private function body(string $quelle, string $name): ?string
    {
        if (preg_match('/function\s+'.preg_quote($name, '/').'\s*\(/', $quelle, $treffer, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $auf = strpos($quelle, '{', (int) $treffer[0][1] + strlen((string) $treffer[0][0]));

        if ($auf === false) {
            return null;
        }

        $tiefe = 1;
        $i = $auf + 1;
        $länge = strlen($quelle);

        while ($tiefe > 0 && $i < $länge) {
            $i = $this->skip($quelle, $i);

            if ($i >= $länge) {
                break;
            }

            $tiefe += match ($quelle[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            $i++;
        }

        return substr($quelle, $auf, $i - $auf);
    }

    /**
     * Jeder `<button>`-Tag der Vorlage, vollständig.
     *
     * **Zeichenweise und nicht über `[^>]*`.** In diesen Vorlagen steht
     * `:class="{ x: a > b }"`, und ein `>` in einem Attributwert beendet den
     * Tag nicht — für einen solchen Ausdruck aber schon.
     *
     * @return list<string>
     */
    private function buttonTags(string $markup): array
    {
        $tags = [];
        $länge = strlen($markup);

        for ($i = 0; $i < $länge; $i++) {
            if (preg_match('/\G<button\b/', $markup, $treffer, 0, $i) !== 1) {
                continue;
            }

            $j = $i + strlen($treffer[0]);
            $anführung = null;

            for (; $j < $länge; $j++) {
                $zeichen = $markup[$j];

                if ($anführung !== null) {
                    if ($zeichen === $anführung) {
                        $anführung = null;
                    }

                    continue;
                }

                if ($zeichen === '"' || $zeichen === "'") {
                    $anführung = $zeichen;

                    continue;
                }

                if ($zeichen === '>') {
                    break;
                }
            }

            $tags[] = substr($markup, $i, $j - $i);
            $i = $j;
        }

        return $tags;
    }

    /**
     * Jeder Knopf mit `@click` auf eine Funktion, die eine Rückfrage stellt.
     *
     * @return list<array{datei: string, name: string, rot: bool, frageRot: bool}>
     */
    private function asking(): array
    {
        $aus = [];

        foreach ($this->templates() as $pfad) {
            $quelle = (string) file_get_contents($pfad);

            foreach ($this->buttonTags($quelle) as $tag) {
                if (preg_match('/@click="(\w+)\b/', $tag, $klick) !== 1) {
                    continue;
                }

                $rumpf = $this->body($quelle, $klick[1]);

                if ($rumpf === null) {
                    continue;
                }

                if (preg_match('/(?<![\w.])ask\(/', $rumpf, $frage, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $von = (int) $frage[0][1] + strlen((string) $frage[0][0]);
                $argumente = $this->arguments($rumpf, $von, $this->endOfCall($rumpf, $von));

                // Weniger als drei Argumente: Das ist `ask()` aus
                // `usePanelRequest` und keine Rückfrage.
                if (count($argumente) < 3) {
                    continue;
                }

                /*
                 * **Eine gebundene Klasse bleibt aussen vor.** In
                 * `Operations/Index.vue` hängt die Farbe an den Daten
                 * (`:class="{ danger: task.mutating }"`), und ob ein Vorgang
                 * verändert, steht erst zur Laufzeit fest. Ein Wächter, der das
                 * behauptete, behauptete etwas über den Bestand.
                 */
                if (preg_match('/:class="[^"]*\bdanger\b/', $tag) === 1) {
                    continue;
                }

                $vierte = trim($argumente[3] ?? '');

                $aus[] = [
                    'datei' => basename($pfad),
                    'name' => $klick[1],
                    'rot' => preg_match('/\bclass="[^"]*\bdanger\b/', $tag) === 1,
                    // Ohne viertes Argument fragt `ask()` als zerstörend.
                    'frageRot' => $vierte === '' || $vierte === 'true',
                ];
            }
        }

        return $aus;
    }

    public function test_the_button_and_its_confirmation_agree(): void
    {
        $knöpfe = $this->asking();

        /*
         * **Die Untergrenze zählt mit.** Eine leere Liste sähe hier aus wie
         * „alles in Ordnung", und genau das hat in P5c drei Wächter gekostet.
         * Gemessen am 16. August 2026: fünfzehn Knöpfe mit Rückfrage.
         */
        $this->assertGreaterThan(
            8,
            count($knöpfe),
            'Es werden kaum Knöpfe mit Rückfrage gefunden — dann prüft dieser Wächter nichts, und '.
            'seine grüne Meldung sagt nur, dass der Aufsatz über die Vorlagen ins Leere läuft.',
        );

        $uneins = [];

        foreach ($knöpfe as $knopf) {
            if ($knopf['rot'] !== $knopf['frageRot']) {
                $uneins[] = sprintf(
                    '%s::%s — Knopf %s, Rückfrage %s',
                    $knopf['datei'],
                    $knopf['name'],
                    $knopf['rot'] ? 'rot' : 'gewöhnlich',
                    $knopf['frageRot'] ? 'rot' : 'gewöhnlich',
                );
            }
        }

        $this->assertSame(
            [],
            $uneins,
            sprintf(
                "Hier sagen der Knopf und seine Rückfrage Verschiedenes:\n  %s\n\n".
                '`.button.danger` heisst in diesem Panel „lässt sich nicht zurücknehmen" (Plan §7.2), '.
                'und das vierte Argument von `ask()` heisst dasselbe. Eine Handlung ist entweder '.
                "unumkehrbar oder nicht; sie kann es nicht auf halbem Weg werden.\n\n".
                'War die Handlung gemeint, bekommt der Knopf `danger`. War sie es nicht, bekommt die '.
                'Rückfrage ein `false` — und dann ist Rot in diesem Panel wieder eine Auskunft und '.
                'keine Betonung.',
                implode("\n  ", $uneins),
            ),
        );
    }

    /**
     * Und die Knöpfe, die dieser Wächter nicht sieht, sind gezählt.
     *
     * Ein `type="submit"` löst über das Formular aus und nicht über `@click`;
     * die Kette von dort zur Rückfrage kann dieser Aufsatz nicht gehen.
     * Ungezählt hiesse, dass ein neuer solcher Knopf lautlos ausserhalb jeder
     * Prüfung steht.
     */
    public function test_the_uncovered_buttons_are_counted(): void
    {
        $offen = 0;

        foreach ($this->templates() as $pfad) {
            foreach ($this->buttonTags((string) file_get_contents($pfad)) as $tag) {
                if (preg_match('/\bclass="[^"]*\bdanger\b/', $tag) !== 1) {
                    continue;
                }

                if (preg_match('/@click="(\w+)\b/', $tag) === 1) {
                    continue;
                }

                $offen++;
            }
        }

        $this->assertSame(
            self::UNCOVERED,
            $offen,
            sprintf(
                "Es gibt %d rote Knöpfe ohne `@click`, gezählt sind %d.\n\n".
                'Ein `type=\"submit\"` löst über sein Formular aus, und dieser Wächter kann die Kette '.
                'von dort zur Rückfrage nicht gehen. Wer einen hinzufügt, hebt die Zahl hier und '.
                'begründet sie; wer einen abbaut, senkt sie.',
                $offen,
                self::UNCOVERED,
            ),
        );
    }
}
