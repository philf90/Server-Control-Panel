<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Cron\Schedule;

/**
 * Jedes Feld, über das eine Meldung sprechen kann, hat einen deutschen Namen.
 *
 * ## Der Fund
 *
 * `lang/de/validation.php` führte `'attributes' => []`. Ist diese Liste leer,
 * setzt Laravel den **Bezeichner** ein und macht aus `day_of_week` das Wort
 * „day of week". Deutscher Satzbau, englische Wörter darin — auf jeder Seite
 * dieses Panels, seit es Formulare hat (`docs/64`, Befund 15).
 *
 * Die Bezeichner sind englisch, weil sie das sein sollen (`docs/19 §4a`). Sie
 * waren nie als Anzeige gedacht, und niemand hat je entschieden, dass sie eine
 * werden.
 *
 * ## Warum kein bestehender Wächter das finden konnte
 *
 * `WordChoiceTest` liest die Zeichenketten im Quelltext und die Vorlagen.
 * Dieses Wort steht in keiner von beiden — es entsteht erst beim Ausführen, aus
 * einem Bezeichner.
 *
 * > **Ein Wort, das erst beim Ausführen entsteht, steht in keiner Datei — und
 * > kein Wächter, der Dateien liest, findet es.**
 *
 * Deshalb prüft dieser hier nicht den Text, sondern die **Vollständigkeit**:
 * Jedes Feld, das validiert wird, hat einen Namen; und jeder Name gehört zu
 * einem Feld, das es gibt.
 */
final class AttributeNameTest extends TestCase
{
    /**
     * Spreads in einem Regelblock, deren Schlüssel hier statisch bekannt sind.
     *
     * @var array<string,list<string>>
     */
    private const RESOLVED_SPREADS = [
        'array_fill_keys(Schedule::FIELDS' => Schedule::FIELDS,
    ];

    /**
     * Spreads, deren Schlüssel erst beim Ausführen entstehen.
     *
     * Für sie kann `lang/de/validation.php` nichts tun: Dort stünde eine
     * Abschrift von `Quota::cases()`, und die zweite Liste ist die, die beim
     * nächsten Kontingent vergessen wird. Ihre Namen
     * kommen deshalb als dritter Wert an den Aufruf — geprüft wird hier, dass
     * er da ist.
     *
     * > **Ein Wächter, der nicht hinsehen kann, sagt das — er schweigt nicht.**
     *
     * @var array<string,string>
     */
    private const NAMED_AT_CALL_SITE = [
        'Quotas::rules()' => 'Quotas::names()',
        'Quotas::overrideRules()' => 'Quotas::names()',
    ];

    /**
     * Jedes validierte Feld steht in der Liste der Namen.
     */
    public function test_every_validated_field_has_a_german_name(): void
    {
        $namen = $this->attributes();
        $felder = $this->validatedFields();

        // Eine Null ist nur dann eine Messung, wenn daneben etwas anderes steht.
        $this->assertGreaterThanOrEqual(
            60,
            count($felder),
            'Es werden kaum validierte Felder gefunden — dann prüft dieser Wächter nichts, und '.
            'die Liste in lang/de/validation.php steht ohne ihre Zusage da.',
        );

        $fehlend = [];

        foreach ($felder as $feld => $wo) {
            /*
             * **Eine Ausnahmeliste gibt es hier nicht, und das ist Absicht.**
             * Der erste Anlauf trug eine leere — „damit sichtbar ist, dass es
             * die Möglichkeit gibt". Eine leere Ausnahme ist keine
             * Möglichkeit, sondern ein toter Zweig; PHPStan hat sie als
             * `function.impossibleType` gemeldet. Den Ausweg gibt es ohnehin,
             * und er ist der bessere: der dritte Wert am Aufruf.
             */
            if (isset($namen[$feld])) {
                continue;
            }

            $fehlend[] = sprintf('%s (%s)', $feld, implode(', ', $wo));
        }

        $this->assertSame([], $fehlend, sprintf(
            "Diese Felder werden validiert und haben keinen deutschen Namen:\n  %s\n\n".
            'Ohne Eintrag setzt Laravel den Bezeichner ein, und der ist englisch — „Das Feld '.
            "day of week ist erforderlich\" (docs/64, Befund 15).\n\n".
            'Der Name gehört in `lang/de/validation.php` unter `attributes`, und zwar so, wie er '.
            'am Feld steht. Bedeutet derselbe Bezeichner an zwei Orten Verschiedenes, trägt die '.
            'Liste den häufigeren Fall und der andere seinen Namen als dritten Wert an '.
            '`validate()`.',
            implode("\n  ", $fehlend),
        ));
    }

    /**
     * Und jeder Name gehört zu einem Feld, das es gibt.
     *
     * **Die Sperrklinke.** Ein Name für ein Feld, das umbenannt oder entfernt
     * wurde, wirkt nirgends und sieht trotzdem nach Pflege aus.
     */
    public function test_every_name_belongs_to_a_field(): void
    {
        $felder = $this->validatedFields();
        $uebrig = [];

        foreach (array_keys($this->attributes()) as $name) {
            if (! isset($felder[$name])) {
                $uebrig[] = $name;
            }
        }

        $this->assertSame([], $uebrig, sprintf(
            "Diese Namen stehen in lang/de/validation.php und gehören zu keinem validierten Feld:\n".
            "  %s\n\n".
            'Entweder ist das Feld weg — dann gehört die Zeile gelöscht — oder es heisst anders, '.
            'und dann wirkt der Name nicht mehr.',
            implode("\n  ", $uebrig),
        ));
    }

    /**
     * Kein Regelblock verbirgt seine Felder hinter einem Spread.
     *
     * ## Warum es diesen Fall gibt
     *
     * Die beiden Fälle darüber lesen die Schlüssel, die im Quelltext stehen.
     * `...array_fill_keys(Schedule::FIELDS, …)` steht dort nicht — und genau
     * deshalb waren `minute`, `hour`, `day_of_month`, `month` und `day_of_week`
     * **grün**, während sie keinen einzigen deutschen Namen hatten. Der Fund
     * von Befund 15 ist wörtlich „Das Feld day of week ist erforderlich"; der
     * Wächter dagegen konnte ihn nicht sehen.
     *
     * > **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
     * > gemessen — er hat an dieser Stelle gar nicht gemessen.**
     *
     * Ein Spread ist deshalb ab jetzt eines von beiden: aufgelöst
     * ({@see self::RESOLVED_SPREADS}) oder am Aufruf benannt
     * ({@see self::NAMED_AT_CALL_SITE}). Ein dritter Fall ist Rot.
     */
    public function test_no_rule_block_hides_its_fields(): void
    {
        $offen = [];
        $gesehen = 0;

        foreach ($this->phpFiles(dirname(__DIR__, 2).'/app') as $datei) {
            $quelle = (string) file_get_contents($datei);

            foreach ($this->ruleBlocks($quelle) as $eintrag) {
                foreach ($this->spreads($eintrag['block']) as $spread) {
                    $gesehen++;

                    foreach (array_keys(self::RESOLVED_SPREADS) as $marke) {
                        if (str_starts_with($spread, $marke)) {
                            continue 2;
                        }
                    }

                    foreach (self::NAMED_AT_CALL_SITE as $marke => $quelleDerNamen) {
                        if (! str_starts_with($spread, $marke)) {
                            continue;
                        }

                        if (str_contains($eintrag['tail'], $quelleDerNamen)) {
                            continue 2;
                        }

                        $offen[] = sprintf(
                            '%s: %s — %s steht nicht am Aufruf',
                            basename($datei),
                            $marke,
                            $quelleDerNamen,
                        );

                        continue 2;
                    }

                    $offen[] = sprintf('%s: %s', basename($datei), $spread);
                }
            }
        }

        // Eine Null ist nur dann eine Messung, wenn daneben etwas anderes steht.
        $this->assertGreaterThanOrEqual(3, $gesehen, sprintf(
            'Es werden nur %d Spreads gefunden. Der Zähler stand beim Bauen auf 3 — sinkt er, '.
            'sucht dieser Wächter an der falschen Stelle, statt dass es weniger Spreads gäbe.',
            $gesehen,
        ));

        $this->assertSame([], $offen, sprintf(
            "Diese Spreads in Regelblöcken sind für diesen Wächter unlesbar:\n  %s\n\n".
            'Entweder die Schlüssel stehen fest — dann gehört der Ausdruck in RESOLVED_SPREADS —, '.
            'oder sie entstehen beim Ausführen; dann gehören ihre Namen als dritter Wert an den '.
            'Aufruf und der Ausdruck in NAMED_AT_CALL_SITE.',
            implode("\n  ", $offen),
        ));
    }

    /**
     * Die Liste der Namen aus der Sprachdatei.
     *
     * @return array<string,string>
     */
    private function attributes(): array
    {
        /** @var array{attributes: array<string,string>} $sprache */
        $sprache = require dirname(__DIR__, 2).'/lang/de/validation.php';

        return $sprache['attributes'];
    }

    /**
     * Jeder Feldname, über den eine Meldung sprechen kann, mit seinen Dateien.
     *
     * **Gesucht wird nur, was wirklich validiert wird** — `->validate([…])`,
     * `Validator::make(…, […])` und `rules(): array`. Der erste Anlauf hat
     * jeden Schlüssel genommen, dessen Wert nach einer Regel aussah, und dabei
     * die `$casts` der Modelle mitgezählt: `duration_ms`, `exit_code`,
     * `truncated`. Aus 80 Feldern wurden so 95.
     *
     * > **Ein Ausdruck, der die Form trifft, trifft noch nicht den Ort.**
     *
     * @return array<string,list<string>>
     */
    private function validatedFields(): array
    {
        $gefunden = [];

        foreach ($this->phpFiles(dirname(__DIR__, 2).'/app') as $datei) {
            $quelle = (string) file_get_contents($datei);

            foreach ($this->ruleBlocks($quelle) as $eintrag) {
                $felder = $this->topLevelKeys($eintrag['block']);

                foreach ($this->spreads($eintrag['block']) as $spread) {
                    foreach (self::RESOLVED_SPREADS as $marke => $schluessel) {
                        if (str_starts_with($spread, $marke)) {
                            $felder = array_merge($felder, $schluessel);
                        }
                    }
                }

                foreach ($felder as $feld) {
                    $gefunden[$feld][] = basename($datei);
                    $gefunden[$feld] = array_values(array_unique($gefunden[$feld]));
                }
            }
        }

        ksort($gefunden);

        return $gefunden;
    }

    /**
     * Die Regelblöcke einer Datei, jeweils von `[` bis zur schliessenden `]`.
     *
     * **Der `tail` ist das, was hinter der schliessenden Klammer steht.** Dort
     * stehen die beiden weiteren Werte von `validate()`: die eigenen Meldungen
     * und die eigenen Namen. Ohne ihn liesse sich {@see self::NAMED_AT_CALL_SITE}
     * nicht belegen.
     *
     * @return list<array{block: string, tail: string}>
     */
    private function ruleBlocks(string $quelle): array
    {
        $bloecke = [];

        $muster = [
            '/->validate\(\s*\[/',
            '/Validator::make\([^,]+,\s*\[/',
            '/function rules\(\)[^{]*\{\s*return\s*\[/',
        ];

        foreach ($muster as $regex) {
            preg_match_all($regex, $quelle, $treffer, PREG_OFFSET_CAPTURE);

            foreach ($treffer[0] as $t) {
                $von = (int) $t[1] + strlen((string) $t[0]) - 1;
                $block = $this->bracket($quelle, $von);

                if ($block !== '') {
                    $bloecke[] = [
                        'block' => $block,
                        'tail' => substr($quelle, $von + strlen($block), 120),
                    ];
                }
            }
        }

        /*
         * **Und die Regeln, die erst in einer Variablen stehen.**
         *
         * Beim Bauen von Befund 16 sind die Cron-Regeln aus dem Aufruf in ein
         * `$regeln = […]` gewandert, weil die Experteneingabe zwei Zweige
         * braucht. Damit waren `label`, `command` und `active` für diesen
         * Wächter verschwunden — die Sperrklinke hat es im selben Lauf
         * gemeldet.
         *
         * > **Ein Wächter, der eine Schreibweise liest, verliert das Feld beim
         * > Umschreiben — nicht beim Löschen.**
         *
         * Gesucht wird deshalb auch die Variable, die an `validate()` oder
         * `Validator::make()` geht, und dann ihre Zuweisung in derselben Datei.
         */
        preg_match_all(
            '/(?:->validate\(\s*|Validator::make\([^,]+,\s*)\$(\w+)/',
            $quelle,
            $ueber,
        );

        foreach (array_unique($ueber[1]) as $variable) {
            if (preg_match('/\$'.preg_quote($variable, '/').'\s*=\s*\[/', $quelle, $t, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $von = (int) $t[0][1] + strlen($t[0][0]) - 1;
            $block = $this->bracket($quelle, $von);

            if ($block !== '') {
                $bloecke[] = [
                    'block' => $block,
                    'tail' => substr($quelle, $von + strlen($block), 120),
                ];
            }
        }

        return $bloecke;
    }

    /**
     * Die Spreads auf der obersten Ebene eines Blocks.
     *
     * **Nur die oberste Ebene.** `['required', 'confirmed', ...Policy::rules()]`
     * schiebt Regeln in ein Feld und keine Felder in den Block; er steht eine
     * Ebene tiefer und geht diesen Wächter nichts an.
     *
     * Zurück kommt der Text ab den drei Punkten, gekürzt — er dient nur dazu,
     * den Spread wiederzuerkennen.
     *
     * @return list<string>
     */
    private function spreads(string $block): array
    {
        $tiefe = 0;
        $gefunden = [];

        preg_match_all('/[\[\]]|\.\.\./', $block, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[0] as $t) {
            if ($t[0] === '[') {
                $tiefe++;
            } elseif ($t[0] === ']') {
                $tiefe--;
            } elseif ($tiefe === 1) {
                $gefunden[] = trim(substr($block, (int) $t[1] + 3, 60));
            }
        }

        return $gefunden;
    }

    /**
     * Von einer öffnenden Klammer bis zu ihrer schliessenden.
     */
    private function bracket(string $quelle, int $von): string
    {
        $tiefe = 0;
        $laenge = strlen($quelle);

        for ($i = $von; $i < $laenge; $i++) {
            if ($quelle[$i] === '[') {
                $tiefe++;
            } elseif ($quelle[$i] === ']') {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($quelle, $von, $i - $von + 1);
                }
            }
        }

        return '';
    }

    /**
     * Die Schlüssel auf der obersten Ebene eines Blocks.
     *
     * **Nur die oberste Ebene**, denn `['required', 'string']` steht darunter
     * und ist eine Regel und kein Feld.
     *
     * @return list<string>
     */
    private function topLevelKeys(string $block): array
    {
        $tiefe = 0;
        $felder = [];

        preg_match_all("/[\[\]]|'([a-zA-Z][a-zA-Z0-9_.*]*)'\s*=>/", $block, $treffer, PREG_SET_ORDER);

        foreach ($treffer as $t) {
            if ($t[0] === '[') {
                $tiefe++;
            } elseif ($t[0] === ']') {
                $tiefe--;
            } elseif ($tiefe === 1) {
                $felder[] = $t[1];
            }
        }

        return $felder;
    }

    /**
     * Alle `.php`-Dateien unterhalb eines Verzeichnisses.
     *
     * @return list<string>
     */
    private function phpFiles(string $wurzel): array
    {
        $dateien = [];

        foreach ((array) scandir($wurzel) as $eintrag) {
            if (! is_string($eintrag) || $eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $pfad = $wurzel.'/'.$eintrag;

            if (is_dir($pfad)) {
                $dateien = array_merge($dateien, $this->phpFiles($pfad));

                continue;
            }

            if (str_ends_with($eintrag, '.php')) {
                $dateien[] = $pfad;
            }
        }

        return $dateien;
    }
}
