<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Diagnose;

/**
 * Was in einer Konfigurationsdatei **als Anweisung** dasteht — und nicht bloss
 * als Zeichenkette (A10 Schritt 4, `docs/98 §3 B`).
 *
 * ## Warum ein Schnitt und keine Textsuche
 *
 * Gemessen am 2. September 2026 (`docs/81 §2.3o` M3, M21): `nginx -t` lässt ein
 * fehlendes Semikolon in zwei von vier Formen mit `rc=0` durch, und die nächste
 * Anweisung wird zum **Argument** der vorigen. Sie steht dann wörtlich noch in
 * der Datei — `grep access_log` findet sie — und ist wirkungslos.
 *
 * > **Eine Anweisung, die zum Argument der vorigen geworden ist, steht wörtlich
 * > noch da.** Wer nach der Zeichenkette sucht, findet sie; wer nach der
 * > Anweisung sucht, nicht.
 *
 * ## Was dieser Schnitt ist und was nicht
 *
 * **Kein nginx-Parser.** Er trennt an `;`, `{` und `}`, nimmt das erste Wort
 * jeder Anweisung — und für die verschluckte Form zusätzlich die übrigen. Er
 * kennt keine Anführungszeichen, kein `include`, keine Reihenfolge und keine
 * Argumente. Was er nicht kann, steht in `docs/98 §11`; wer mehr will, baut
 * einen Parser, und dann ist es eine zweite Fassung von nginx.
 *
 * **Die Pool-Datei ist INI und kein nginx.** Dort gibt es kein Semikolon, das
 * fehlen könnte — aber eine Zeile, die fort ist. Der Schnitt dafür ist die
 * Zeile, der Schlüssel das, was vor dem `=` steht.
 */
final class Statements
{
    /**
     * Die Anweisungen einer nginx-Datei, je eine als Wortliste.
     *
     * Kommentare (`#` bis Zeilenende) fallen vorher weg — sonst zählte ein
     * Kommentar, der eine Anweisung erwähnt, als Anweisung. Derselbe Grund, aus
     * dem die Wächter dieses Repos `WithoutHashComments` benutzen.
     *
     * @return list<list<string>> je Anweisung: erstes Wort, dann die Argumente
     */
    public static function nginx(string $content): array
    {
        $bare = preg_replace('/#[^\n]*/', '', $content) ?? $content;
        $statements = [];

        foreach (preg_split('/[;{}]/', $bare) ?: [] as $chunk) {
            $words = preg_split('/\s+/', trim($chunk), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words !== []) {
                $statements[] = $words;
            }
        }

        return $statements;
    }

    /**
     * Die Schlüssel einer INI-Datei — was vor dem `=` steht.
     *
     * Abschnittsköpfe (`[p1001]`) und Kommentare (`;`) zählen nicht. Ein
     * Schlüssel wie `php_admin_value[open_basedir]` bleibt ganz: Die eckigen
     * Klammern gehören zum Namen, und genau daran hängt die Abschottung.
     *
     * @return list<string>
     */
    public static function ini(string $content): array
    {
        $keys = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === ';' || $line[0] === '[' || ! str_contains($line, '=')) {
                continue;
            }

            $keys[] = trim(substr($line, 0, (int) strpos($line, '=')));
        }

        return $keys;
    }

    /**
     * Welche zugesagten Anweisungen einer nginx-Datei fehlen — als Anweisung.
     *
     * Zwei Fragen je Zusage, beide aus M3: Steht sie als **erstes Wort** einer
     * Anweisung da? Und steht sie nirgends als **Argument** einer anderen —
     * denn genau so sieht die verschluckte Form aus (`index … access_log …;`).
     *
     * @param  list<string>  $promised
     * @return list<string> die verlorenen, je mit dem Grund
     */
    public static function lostInNginx(string $content, array $promised): array
    {
        $statements = self::nginx($content);
        $heads = [];
        $swallowed = [];

        foreach ($statements as $words) {
            $heads[$words[0]] = true;

            foreach (array_slice($words, 1) as $argument) {
                if (in_array($argument, $promised, true)) {
                    $swallowed[$argument] = $words[0];
                }
            }
        }

        $lost = [];

        foreach ($promised as $directive) {
            if (! isset($heads[$directive])) {
                $lost[] = sprintf('%s fehlt als Anweisung', $directive);
            } elseif (isset($swallowed[$directive])) {
                $lost[] = sprintf('%s steht als Argument von %s', $directive, $swallowed[$directive]);
            }
        }

        return $lost;
    }

    /**
     * Welche zugesagten Schlüssel einer INI-Datei fehlen.
     *
     * @param  list<string>  $promised
     * @return list<string>
     */
    public static function lostInIni(string $content, array $promised): array
    {
        $keys = array_fill_keys(self::ini($content), true);
        $lost = [];

        foreach ($promised as $key) {
            if (! isset($keys[$key])) {
                $lost[] = sprintf('%s fehlt', $key);
            }
        }

        return $lost;
    }
}
