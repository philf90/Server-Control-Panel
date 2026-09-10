<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Jede Prüfregel, die dieses Panel benutzt, hat einen deutschen Satz.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `docs/19 §4a` ist bindend: alle Texte der Oberfläche sind deutsch. Bis zum
 * 15. August 2026 gab es kein `lang/`-Verzeichnis, und das Gebietsschema stand
 * ausserdem auf `en` — auf jedem installierten Panel, seit P0.
 *
 * Aufgefallen ist es erst im Prüflauf auf `cloudsrv24` (`docs/55`, Befund 7),
 * als ein Formular an einer **Prüfregel** scheiterte statt an einer Absage des
 * Agenten:
 *
 *     Das Formular wurde nicht gespeichert.
 *     The content field must be a string.
 *
 * > **Eine Sprachvorgabe, die nur für selbstgeschriebene Sätze gilt, hält, bis
 * > der erste fremde Satz durchkommt.**
 *
 * ## Warum er die Regeln abzählt statt sie aufzulisten
 *
 * Eine Liste im Test wäre beim nächsten `mimes:` unvollständig — und zwar
 * lautlos, weil eine fehlende Übersetzung nicht scheitert, sondern auf Englisch
 * zurückfällt. Gelesen wird deshalb `app/`: Welche Regel dort steht, braucht
 * hier eine Zeile.
 *
 * > **Ein Rückfall, der lesbar ist, meldet sich nie.**
 *
 * ## Wo er blind war — 10. September 2026
 *
 * Der Abnahmelauf zu `docs/901` fand beim Anlegen eines Kontos mit vergebener
 * Adresse den Satz *„The Anmeldeadresse has already been taken."* Dieser
 * Wächter stand daneben und war grün, und zwar aus **drei** Gründen auf
 * einmal (`docs/903 §11.1`):
 *
 * 1. Er führte eine **eigene Liste** von 58 Regelnamen — und `date_format` und
 *    `enum` standen nicht darin. Der Absatz darüber begründet, warum eine
 *    Liste im Test die schlechtere Zusage ist; genau die stand hier trotzdem.
 * 2. Er suchte nur die Form `'regel'`. `unique`, `enum`, `exists` und
 *    `required_if` reisen in diesem Panel ausschliesslich als **Objekt**
 *    (`Rule::unique(…)`), und dafür war er blind.
 * 3. Seine Gegenprobe verlangte `required`, `string` und `max` — alle drei in
 *    der Zeichenkettenform. Die zweite Form kam darin nicht vor.
 *
 * > **Ein Wächter, der begründet, warum er keine Liste führt, führte eine — und
 * > sie war es, die ihn blind machte.**
 *
 * > **Ein Aufruf, der als Objekt reist, ist für einen Ausdruck über
 * > Zeichenketten verschwunden — nicht harmlos geworden.**
 *
 * > **Eine Untergrenze, die nur die gewohnte Form enthält, belegt die andere
 * > nicht.**
 *
 * Die Grundmenge kommt seitdem aus Laravels **eigener** `en/validation.php`,
 * gelesen wird über `token_get_all()` statt über einen Ausdruck, und die
 * Gegenprobe verlangt eine Regel, die es nur als Objekt gibt.
 *
 * ## Was er nicht kann
 *
 * Er liest die Argumentbereiche der Validierungsaufrufe. Eine Regelliste, die
 * anderswo entsteht und nur als Variable übergeben wird, sieht er nicht — heute
 * gibt es keine (gemessen: 48 `->validate(` und ein `Validator::make(`, alle
 * mit einem Array an Ort und Stelle). Und er sagt nichts darüber, ob der
 * deutsche Satz **richtig** ist; er sagt, dass es ihn gibt.
 */
final class ValidationLanguageTest extends TestCase
{
    /**
     * Regeln ohne eigenen Satz — mit Grund.
     *
     * Sie steuern, **ob** geprüft wird, und erzeugen keine Meldung. Wer hier
     * etwas einträgt, schreibt den Grund dazu; eine Liste ohne Begründung je
     * Eintrag wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    private const WITHOUT_MESSAGE = [
        'nullable' => 'Erlaubt den leeren Wert. Sie kann nicht scheitern.',
        'sometimes' => 'Prüft nur, wenn das Feld da ist. Sie kann nicht scheitern.',
        'bail' => 'Bricht nach dem ersten Fehler ab. Der Satz kommt von der Regel, die zuerst zuschlug.',
        'filled' => 'Wird hier nicht benutzt; steht in der Liste, weil der Ausdruck sie kennt.',
    ];

    private function language(): string
    {
        return dirname(__DIR__, 2).'/lang/de/validation.php';
    }

    /**
     * Die Prüfregeln, die `app/` wirklich benutzt.
     *
     * Gelesen werden die **Argumentbereiche** der Validierungsaufrufe, und
     * darin zweierlei: die Regel als Zeichenkette (`'required'`, `'max:255'`)
     * und die Regel als Objekt (`Rule::unique(…)`). Beides zählt, und die
     * zweite Form ist der Grund, aus dem dieser Wächter drei Wochen lang zu
     * einem englischen Satz geschwiegen hat.
     *
     * **Gelesen wird über `token_get_all()` und nicht über einen Ausdruck.**
     * Eine Klammer in einem `regex:`-Muster brächte jede Klammerzählung aus dem
     * Tritt, und Kommentare fielen mit hinein — dieses Repo hält seinen
     * Vorzustand im Kommentar fest, und der zitiert regelmässig genau die
     * Zeile, um die es geht.
     *
     * @return list<string>
     */
    private function rulesInUse(): array
    {
        $bekannt = $this->vocabulary();
        $gefunden = [];

        foreach ($this->sources() as $quelle) {
            foreach ($this->rulesIn($quelle) as $regel) {
                if (in_array($regel, $bekannt, true)) {
                    $gefunden[$regel] = true;
                }
            }
        }

        ksort($gefunden);

        return array_keys($gefunden);
    }

    /**
     * Die Namen, die Laravel überhaupt als Prüfregel kennt.
     *
     * **Aus seiner eigenen Datei und nicht aus einer Liste hier.** Eine Liste
     * im Test fällt zurück, sobald jemand eine Regel benutzt, an die niemand
     * gedacht hat — und zwar lautlos, weil eine unbekannte Regel hier einfach
     * nicht mitgezählt wird.
     *
     * Sie ist die **Grundmenge**, nicht die Anforderung: Verlangt wird ein
     * deutscher Satz nur für die Regeln, die `app/` wirklich benutzt.
     *
     * @return list<string>
     */
    private function vocabulary(): array
    {
        $pfad = dirname(__DIR__, 2)
            .'/vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php';

        $this->assertFileExists(
            $pfad,
            'Laravels eigene Regelliste ist nicht da. Ohne sie kennt dieser Wächter keinen '.
            'einzigen Regelnamen und wäre grün, ohne etwas gemessen zu haben.',
        );

        /** @var array<string, mixed> $englisch */
        $englisch = require $pfad;

        return array_values(array_diff(
            array_keys($englisch),
            // Keine Regeln, sondern die Ablagen für eigene Sätze und Feldnamen.
            ['custom', 'attributes', 'values'],
        ));
    }

    /**
     * Der Quelltext jeder PHP-Datei unter `app/`.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $quellen = [];
        $wurzel = dirname(__DIR__, 2).'/app';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $quellen[] = (string) file_get_contents($file->getPathname());
            }
        }

        return $quellen;
    }

    /**
     * Die Regelnamen in den Validierungsaufrufen einer Datei.
     *
     * @return list<string>
     */
    private function rulesIn(string $quelle): array
    {
        $tokens = token_get_all($quelle);
        $anzahl = count($tokens);
        $regeln = [];

        for ($i = 0; $i < $anzahl; $i++) {
            $klammer = $this->validationCallAt($tokens, $i);

            if ($klammer === null) {
                continue;
            }

            $ende = $this->closingParenthesis($tokens, $klammer);

            for ($j = $klammer + 1; $j < $ende; $j++) {
                $objekt = $this->ruleObjectAt($tokens, $j);

                if ($objekt !== null) {
                    [$name, $auf] = $objekt;
                    $regeln[] = $this->snake($name);

                    // Der Inhalt zählt nicht: Dort stehen Tabellen und Spalten,
                    // und `accounts` ist keine Prüfregel.
                    $j = $this->closingParenthesis($tokens, $auf);

                    continue;
                }

                $token = $tokens[$j];

                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                // Ein Schlüssel ist der Feldname und keine Regel. Genau daran
                // ist die Suche nach `current_password` einmal hängengeblieben.
                $naechstes = $this->nextSignificant($tokens, $j, $ende);

                if ($naechstes !== null && is_array($tokens[$naechstes]) && $tokens[$naechstes][0] === T_DOUBLE_ARROW) {
                    continue;
                }

                $wert = trim($token[1], "'\"");
                $doppelpunkt = strpos($wert, ':');

                $regeln[] = $doppelpunkt === false ? $wert : substr($wert, 0, $doppelpunkt);
            }

            $i = $ende;
        }

        return array_values(array_unique($regeln));
    }

    /**
     * Beginnt hier `->validate(` oder `Validator::make(`? Dann die Stelle der
     * öffnenden Klammer.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function validationCallAt(array $tokens, int $i): ?int
    {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING) {
            return null;
        }

        $davor = $this->previousSignificant($tokens, $i);

        if ($davor === null || ! is_array($tokens[$davor])) {
            return null;
        }

        $passt = false;

        if ($token[1] === 'validate' && $tokens[$davor][0] === T_OBJECT_OPERATOR) {
            $passt = true;
        }

        if ($token[1] === 'make' && $tokens[$davor][0] === T_DOUBLE_COLON) {
            $klasse = $this->previousSignificant($tokens, $davor);

            $passt = $klasse !== null
                && is_array($tokens[$klasse])
                && $tokens[$klasse][0] === T_STRING
                && $tokens[$klasse][1] === 'Validator';
        }

        if (! $passt) {
            return null;
        }

        $auf = $this->nextSignificant($tokens, $i, count($tokens));

        return $auf !== null && $tokens[$auf] === '(' ? $auf : null;
    }

    /**
     * Steht hier `Rule::name(`? Dann der Name und die Stelle seiner Klammer.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: string, 1: int}|null
     */
    private function ruleObjectAt(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'Rule') {
            return null;
        }

        $doppel = $this->nextSignificant($tokens, $i, count($tokens));

        if ($doppel === null || ! is_array($tokens[$doppel]) || $tokens[$doppel][0] !== T_DOUBLE_COLON) {
            return null;
        }

        $name = $this->nextSignificant($tokens, $doppel, count($tokens));

        if ($name === null || ! is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
            return null;
        }

        $auf = $this->nextSignificant($tokens, $name, count($tokens));

        if ($auf === null || $tokens[$auf] !== '(') {
            return null;
        }

        return [$tokens[$name][1], $auf];
    }

    /**
     * Die Stelle der Klammer, die die bei `$auf` wieder schliesst.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function closingParenthesis(array $tokens, int $auf): int
    {
        $tiefe = 0;
        $anzahl = count($tokens);

        for ($i = $auf; $i < $anzahl; $i++) {
            if ($tokens[$i] === '(') {
                $tiefe++;
            } elseif ($tokens[$i] === ')') {
                $tiefe--;

                if ($tiefe === 0) {
                    return $i;
                }
            }
        }

        return $anzahl - 1;
    }

    /**
     * Das nächste Token, das kein Leerraum und kein Kommentar ist.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function nextSignificant(array $tokens, int $i, int $ende): ?int
    {
        for ($j = $i + 1; $j < min($ende, count($tokens)); $j++) {
            if (! $this->isNoise($tokens[$j])) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Das vorige Token, das kein Leerraum und kein Kommentar ist.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function previousSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $this->isNoise($tokens[$j])) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private function isNoise(array|string $token): bool
    {
        return is_array($token)
            && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** `requiredIf` heisst als Meldungsschlüssel `required_if`. */
    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '_$1', $name));
    }

    /** @return array<string, mixed> */
    private function messages(): array
    {
        /** @var array<string, mixed> $meldungen */
        $meldungen = require $this->language();

        return $meldungen;
    }

    public function test_the_german_messages_exist(): void
    {
        $this->assertFileExists(
            $this->language(),
            'Es gibt keine deutschen Prüfmeldungen mehr. Ohne sie formuliert Laravel selbst, '.
            'und zwar auf Englisch — mitten in einer deutschen Oberfläche.',
        );
    }

    /**
     * Und die Anwendung spricht sie auch.
     *
     * **Die Datei allein genügt nicht.** `config/app.php` stand auf `'en'`, und
     * `lang/de` wäre damit ein Verzeichnis gewesen, das niemand liest — genau
     * der Fehler, den dieses Projekt am häufigsten macht: eine Zeichenkette,
     * die auf etwas verweist, ohne dass jemand den Bezug prüft.
     */
    public function test_the_application_speaks_german(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/config/app.php');

        $this->assertMatchesRegularExpression(
            "/'locale' => env\('APP_LOCALE', 'de'\)/",
            $quelle,
            "Das Gebietsschema steht nicht auf Deutsch.\n\n".
            'Dann liegt `lang/de` da und wird nicht gelesen — die Meldungen kommen weiter auf '.
            'Englisch, und die Datei sieht aus wie eine Zusage.',
        );
    }

    /**
     * Und die Übersetzung liegt auch im Paket.
     *
     * ## Der Fehler, der zwischen zwei richtigen Prüfungen durchfiel
     *
     * `lang/de/validation.php` war da, `config/app.php` stand auf `de`, beide
     * Wächter waren grün — und auf `cloudsrv24` kam weiter der englische Satz
     * (`docs/55`, Befund 11). **`packaging/build.sh` führt eine Positivliste der
     * Verzeichnisse, die ins Paket wandern, und `lang` stand nicht darin.**
     *
     * > **Eine Datei, die ein Wächter im Repo prüft, ist damit noch nicht auf
     * > dem Server.**
     *
     * Das ist derselbe Schnitt wie bei `PackagingTest`, nur eine Ebene tiefer:
     * Dort ruft eine systemd-Unit ein Kommando über eine Zeichenkette auf, hier
     * lädt das Framework ein Verzeichnis über eine Konvention. Beide Male hält
     * nichts den Bezug — ausser einem Test, der ihn nachrechnet.
     *
     * **Und die Liste schweigt, wenn etwas fehlt:** `build.sh` überspringt jeden
     * Eintrag, den es nicht findet (`if [ -e … ]`). Ein Tippfehler im Namen
     * baut also ein Paket ohne die Datei und meldet Erfolg.
     */
    public function test_the_translations_are_shipped(): void
    {
        $bau = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/build.sh');

        $this->assertMatchesRegularExpression(
            '/^\s*agent app .*\blang\b/m',
            $bau,
            "`packaging/build.sh` nimmt `lang` nicht ins Paket.\n\n".
            "Die Übersetzung liegt dann im Repo, jeder Wächter hier ist grün — und auf dem Server\n".
            'formuliert Laravel weiter selbst, auf Englisch.',
        );
    }

    public function test_every_rule_in_use_has_a_german_sentence(): void
    {
        $meldungen = $this->messages();
        $fehlend = [];

        foreach ($this->rulesInUse() as $regel) {
            if (isset(self::WITHOUT_MESSAGE[$regel]) || isset($meldungen[$regel])) {
                continue;
            }

            $fehlend[] = $regel;
        }

        $this->assertSame(
            [],
            $fehlend,
            sprintf(
                "Diese Prüfregeln benutzt `app/`, und `lang/de/validation.php` kennt sie nicht:\n  %s\n\n".
                'Laravel fällt dafür auf seinen englischen Satz zurück — lesbar, und deshalb meldet '.
                'sich das nie von selbst.',
                implode("\n  ", $fehlend),
            ),
        );
    }

    /**
     * Und die Suche findet auch etwas.
     *
     * **Ohne diese Gegenprobe ist der Test darüber wertlos.** Er behauptet eine
     * leere Liste, und die liefert ein kaputter Ausdruck genauso zuverlässig wie
     * eine vollständige Übersetzung.
     *
     * > **Eine Messung, die nie etwas anderes als Null liefern kann, ist
     * > keine.**
     */
    public function test_the_search_really_finds_rules(): void
    {
        $regeln = $this->rulesInUse();

        $this->assertGreaterThanOrEqual(
            15,
            count($regeln),
            sprintf(
                'Es werden nur %d Prüfregeln in `app/` gefunden (%s). Dann sucht dieser Wächter an '.
                'der falschen Stelle, und seine grüne Antwort bedeutet nichts.',
                count($regeln),
                implode(', ', $regeln) ?: '(keine)',
            ),
        );

        foreach (['required', 'string', 'max'] as $muss) {
            $this->assertContains(
                $muss,
                $regeln,
                sprintf('`%s` steht in jedem zweiten Formular und wird nicht gefunden.', $muss),
            );
        }

        // **Und die zweite Form.** `unique` steht in diesem Panel an fünf
        // Stellen und an keiner als `'unique'` — es reist ausschliesslich als
        // `Rule::unique(…)`. Genau daran war dieser Wächter blind, und genau
        // deshalb steht es hier: Eine Untergrenze aus drei Zeichenketten hätte
        // den Fehler nicht bemerkt.
        foreach (['unique', 'enum'] as $objekt) {
            $this->assertContains(
                $objekt,
                $regeln,
                sprintf(
                    '`%s` wird in `app/` nur als `Rule::…()` benutzt und nicht gefunden. Dann liest '.
                    'dieser Wächter wieder nur Zeichenketten, und die Objektform ist ihm unsichtbar.',
                    $objekt,
                ),
            );
        }
    }

    /**
     * Die Grundmenge kommt aus Laravels eigener Datei.
     *
     * **Ohne diese Prüfung wäre der Rückfall auf eine Liste im Test nicht zu
     * sehen.** Sie war der erste der drei Gründe, aus denen dieser Wächter zu
     * `date_format` und `enum` geschwiegen hat: Beide standen nicht darin.
     */
    public function test_the_vocabulary_is_not_a_list_in_this_test(): void
    {
        $bekannt = $this->vocabulary();

        $this->assertGreaterThan(
            100,
            count($bekannt),
            sprintf(
                'Laravel kennt über hundert Prüfregeln, gefunden sind %d. Dann kommt die Grundmenge '.
                'nicht mehr aus seiner Datei, und was sie nicht kennt, zählt dieser Wächter nicht mit.',
                count($bekannt),
            ),
        );

        foreach (['date_format', 'enum', 'unique'] as $regel) {
            $this->assertContains(
                $regel,
                $bekannt,
                sprintf('`%s` fehlt in der Grundmenge — und dann fehlt es auch im Befund.', $regel),
            );
        }
    }

    /**
     * Kein Satz ist auf Englisch stehengeblieben.
     *
     * Eine Zeile abzuschreiben und die Übersetzung zu vergessen ist der
     * naheliegende Fehler — und er sieht in der Datei aus wie jede andere Zeile.
     */
    public function test_no_sentence_stayed_english(): void
    {
        $englisch = [];

        $pruefe = static function (mixed $wert, string $pfad) use (&$pruefe, &$englisch): void {
            if (is_array($wert)) {
                foreach ($wert as $schluessel => $inneres) {
                    $pruefe($inneres, $pfad === '' ? (string) $schluessel : $pfad.'.'.$schluessel);
                }

                return;
            }

            if (is_string($wert) && preg_match('/\b(The|must be|field is|may not|has already)\b/', $wert) === 1) {
                $englisch[] = $pfad.' — '.$wert;
            }
        };

        $pruefe($this->messages(), '');

        $this->assertSame(
            [],
            $englisch,
            "Diese Sätze sind englisch geblieben:\n  ".implode("\n  ", $englisch),
        );
    }

    /**
     * Und jede Ausnahme trägt ihren Grund.
     *
     * Eine Liste ohne Begründung je Eintrag wächst, bis sie alles enthält —
     * dieselbe Regel wie in {@see AgentOperationReachTest}.
     */
    public function test_every_exemption_carries_a_reason(): void
    {
        foreach (self::WITHOUT_MESSAGE as $regel => $grund) {
            $this->assertNotSame(
                '',
                trim($grund),
                sprintf('`%s` steht ohne Grund in der Ausnahmeliste.', $regel),
            );
        }
    }
}
