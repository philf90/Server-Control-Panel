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
     * Gelesen wird die Form `'regel'` und `'regel:…'` innerhalb von
     * Regellisten. Das ist grob und trifft gelegentlich einen Feldnamen, der
     * wie eine Regel heisst — der Preis dafür, dass hier Text gelesen wird und
     * kein Syntaxbaum. Zu viel gefundene Regeln machen den Wächter strenger,
     * nicht falscher.
     *
     * @return list<string>
     */
    private function rulesInUse(): array
    {
        $bekannt = [
            'accepted', 'active_url', 'after', 'alpha', 'alpha_dash', 'alpha_num', 'array', 'bail',
            'before', 'between', 'boolean', 'confirmed', 'date', 'decimal', 'different', 'digits',
            'distinct', 'email', 'ends_with', 'exists', 'file', 'filled', 'gt', 'gte', 'image', 'in',
            'integer', 'ip', 'json', 'lowercase', 'lt', 'lte', 'max', 'mimes', 'min', 'multiple_of',
            'not_in', 'nullable', 'numeric', 'present', 'prohibited', 'regex', 'required',
            'required_if', 'required_unless', 'required_with', 'required_without', 'same', 'size',
            'sometimes', 'starts_with', 'string', 'timezone', 'unique', 'uppercase', 'url', 'uuid',
        ];

        $gefunden = [];
        $wurzel = dirname(__DIR__, 2).'/app';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $quelle = (string) file_get_contents($file->getPathname());

            foreach ($bekannt as $regel) {
                if (preg_match("/'".preg_quote($regel, '/')."(:[^']*)?'/", $quelle) === 1) {
                    $gefunden[$regel] = true;
                }
            }
        }

        ksort($gefunden);

        return array_keys($gefunden);
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
