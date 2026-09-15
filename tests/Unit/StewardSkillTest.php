<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Steward-Skill nennt keine Jobs, Schritte und Dateien, die es nicht gibt.
 *
 * **Der Anlass ist eine Zeile, die genau so veraltet ist.** `CLAUDE.md` nennt
 * `shellcheck -e SC1091 packaging/bin/*` als „wörtlich in `ci.yml` und einmal
 * Kopieren" — gemessen am 15. September 2026 stehen dort **fünf** Aufrufe, und
 * der genannte deckt acht weitere Skripte nicht ab. Wer ihn kopiert, prüft
 * weniger als die CI und hält seinen Lauf für gleichwertig.
 *
 * > **Ein Befehl, den ein zweites Dokument nacherzählt, ist eine zweite Fassung
 * > — und die zweite ist die, die veraltet.**
 *
 * `.claude/skills/steward/SKILL.md` ist ein solches zweites Dokument, und es
 * wird bei jedem CI-Ereignis gelesen. Benennt jemand einen Job um, zeigt es
 * danach auf einen, den es nicht gibt — und nichts meldet das, weil kein Typ
 * und kein Werkzeug den Bezug prüft. Das ist wortwörtlich die Fehlerklasse aus
 * `CLAUDE.md`: eine Zeichenkette, die auf etwas verweist, ohne dass jemand den
 * Verweis hält.
 *
 * **Geprüft werden beide Richtungen**, aus demselben Grund wie bei
 * `PackagingTest` und dem Wrapper: Die erste allein lässt einen toten Eintrag
 * stehen, die zweite allein einen neuen Job unerwähnt. So entsteht der Schaden
 * wirklich — bei einer Umbenennung trägt man den neuen Namen nach, die erste
 * Richtung ist wieder grün, und der alte bleibt daneben liegen.
 *
 * **Was er nicht kann, und das steht hier als Frage und nicht als Zusage:** Er
 * hält, dass die genannten Namen existieren — nicht, dass stimmt, was der Skill
 * über sie sagt. Ob die Laufzeiten, die Zahl der PRs und die Zahl der Eingriffe
 * noch die gemessenen sind, sagt ihm nichts; sie tragen ihr Datum, und wer sie
 * wieder misst, schreibt das neue daneben.
 */
final class StewardSkillTest extends TestCase
{
    /**
     * Wie viele Jobs die beiden Workflows mindestens führen müssen.
     *
     * **Die Untergrenze ist der Prüfkörper.** Bricht der Leser unten — weil die
     * Einrückung sich ändert oder ein Workflow umzieht —, fände er null Jobs,
     * und beide Richtungen wären wortlos erfüllt: keine Jobs, also auch keine
     * unerwähnten und keine erfundenen.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     *
     * Am 15. September 2026 waren es neun — acht in `ci.yml`, einer in
     * `waechter.yml`.
     */
    private const MINDESTENS_JOBS = 7;

    /**
     * Wie viele Zeilen die Naht-Tabelle mindestens tragen muss.
     *
     * Derselbe Grund. Am 15. September 2026 waren es dreizehn.
     */
    private const MINDESTENS_NAHT = 8;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function skill(): string
    {
        $pfad = $this->root().'/.claude/skills/steward/SKILL.md';
        $text = @file_get_contents($pfad);

        $this->assertIsString(
            $text,
            'Der Steward-Skill fehlt. Er wird bei jedem CI-Ereignis auf einem '
            .'offenen Pull Request gelesen; ohne ihn gelten nur die allgemeinen '
            .'Regeln, und die beschreiben einen Review-Vorgang, den dieses Repo '
            .'nicht führt.'
        );

        return $text;
    }

    /**
     * Jeder Jobname der beiden Workflows, `${{ matrix.name }}` als `…`.
     *
     * Gelesen wird die Einrückung und nicht ein YAML-Parser: Der Wächter muss
     * ohne Framework und ohne Abhängigkeit laufen, damit er im Gestell dieses
     * Containers fahrbar ist. Ein Job steht auf zwei Leerzeichen, sein `name:`
     * auf vier.
     *
     * @return list<string>
     */
    private function jobNamen(): array
    {
        $namen = [];

        foreach (['ci.yml', 'waechter.yml'] as $datei) {
            $text = @file_get_contents($this->root().'/.github/workflows/'.$datei);
            if (! is_string($text)) {
                continue;
            }

            $inJobs = false;
            $offen = false;

            foreach (explode("\n", $text) as $zeile) {
                if (preg_match('/^jobs:\s*$/', $zeile) === 1) {
                    $inJobs = true;

                    continue;
                }
                if (! $inJobs) {
                    continue;
                }

                // Ein Job selbst: genau zwei Leerzeichen, dann sein Schlüssel.
                if (preg_match('/^  [A-Za-z0-9_-]+:\s*$/', $zeile) === 1) {
                    $offen = true;

                    continue;
                }

                // Sein `name:` — vier Leerzeichen, und nur das erste zählt.
                if ($offen && preg_match('/^    name:\s*(\S.*?)\s*$/', $zeile, $t) === 1) {
                    $namen[] = $this->ohneMatrix($t[1]);
                    $offen = false;
                }
            }
        }

        return $namen;
    }

    /**
     * `Installation auf ${{ matrix.name }}` wird zu `Installation auf …`.
     *
     * Der Skill kann den aufgelösten Namen nicht führen — er lautet je nach
     * Plattform anders, und eine Liste der vier hier wäre eine zweite Fassung
     * der Matrix.
     */
    private function ohneMatrix(string $name): string
    {
        return trim((string) preg_replace('/\$\{\{[^}]*\}\}/', '…', $name));
    }

    /**
     * Die erste Zelle jeder Tabellenzeile, die vollständig in Backticks steht.
     *
     * Genau daran hängt die Trennung: In den Tabellen des Skills steht in der
     * ersten Spalte entweder ein Jobname — dann vollständig in Backticks — oder
     * eine Beschriftung wie „Lebensdauer". Eine halb ausgezeichnete Zelle wie
     * „`ci.yml` — 15 Jobs" ist keine Jobzeile und fällt heraus.
     *
     * @return list<array{0: string, 1: string, 2: int}> Name, zweite Zelle, Zeilennummer
     */
    private function tabellenZeilen(string $text): array
    {
        $treffer = [];

        foreach (explode("\n", $text) as $nr => $zeile) {
            if (! str_starts_with(trim($zeile), '|')) {
                continue;
            }

            $zellen = array_map('trim', explode('|', trim($zeile, "| \t")));

            if ($zellen === [] || preg_match('/^`([^`]+)`$/', $zellen[0], $t) !== 1) {
                continue;
            }

            $treffer[] = [$t[1], $zellen[1] ?? '', $nr + 1];
        }

        return $treffer;
    }

    public function test_every_job_the_skill_names_exists(): void
    {
        $jobs = $this->jobNamen();
        $this->assertGreaterThanOrEqual(
            self::MINDESTENS_JOBS,
            count($jobs),
            'Der Leser findet kaum noch Jobs — vermutlich ist er kaputt und '
            .'nicht die Workflows. Ohne Jobs wäre jede Prüfung hier wortlos grün.'
        );

        $genannt = $this->tabellenZeilen($this->skill());
        $this->assertNotEmpty($genannt, 'Der Skill nennt keinen einzigen Job in einer Tabelle.');

        foreach ($genannt as [$name, , $nr]) {
            // Nur Zeilen, deren erste Zelle ein Jobname sein soll — eine
            // Dateizeile wie `ci.yml` ist keine. Erkannt wird das daran, dass
            // die Tabellen des Skills Jobs in Spalte 1 führen und Dateien nur
            // im Fliesstext nennen.
            if (str_contains($name, '.yml') || str_contains($name, '/')) {
                continue;
            }

            $this->assertContains(
                $name,
                $jobs,
                sprintf(
                    'SKILL.md Zeile %d nennt den Job „%s" — den führt weder '
                    .'ci.yml noch waechter.yml. Ein Steward, der bei rotem Lauf '
                    ."danach sucht, findet ihn nicht.\nVorhanden: %s",
                    $nr,
                    $name,
                    implode(' · ', $jobs)
                )
            );
        }
    }

    public function test_every_job_of_the_workflows_is_placed_in_the_skill(): void
    {
        $text = $this->skill();
        $jobs = $this->jobNamen();

        $this->assertGreaterThanOrEqual(self::MINDESTENS_JOBS, count($jobs));

        foreach ($jobs as $name) {
            $this->assertStringContainsString(
                '`'.$name.'`',
                $text,
                sprintf(
                    'Der Job „%s" steht in keinem der beiden Verzeichnisse des '
                    .'Skills. Er gehört entweder in die Naht-Tabelle in §3 — '
                    .'dann ist er vor dem Push messbar — oder in die Tabelle in '
                    .'§4, wenn nur die CI ihn beantworten kann. Ein Job, den '
                    .'niemand eingeordnet hat, wird beim nächsten roten Lauf '
                    .'zum ersten Mal gelesen.',
                    $name
                )
            );
        }
    }

    public function test_every_step_the_seam_names_exists_in_its_job(): void
    {
        $text = $this->skill();

        // Die Naht-Tabelle an ihrer Kopfzeile, nicht an ihrer Lage: Ein
        // Abschnitt, der dazwischenrutscht, verschöbe jede feste Zeilennummer.
        $kopf = '| Job | Schritt | vor dem Push |';
        $this->assertStringContainsString(
            $kopf,
            $text,
            'Die Naht-Tabelle fehlt oder heisst anders. Ohne sie ordnet der '
            .'Skill keinen Job mehr einem Schritt zu, und dieser Wächter misst '
            .'nichts.'
        );

        $zeilen = explode("\n", $text);
        $ab = array_search($kopf, array_map('trim', $zeilen), true);
        $this->assertIsInt($ab);

        $schritte = $this->schrittNamen();
        $gezaehlt = 0;

        foreach (array_slice($zeilen, (int) $ab + 2) as $i => $zeile) {
            if (! str_starts_with(trim($zeile), '|')) {
                break;   // Die Tabelle ist zu Ende.
            }

            $zellen = array_map('trim', explode('|', trim($zeile, "| \t")));
            if (count($zellen) < 2 || preg_match('/^`([^`]+)`$/', $zellen[0], $j) !== 1) {
                continue;
            }
            if (preg_match('/^`([^`]+)`$/', $zellen[1], $s) !== 1) {
                continue;   // „(der Schritt trägt keinen Namen)" — ausgeschrieben, nicht ausgezeichnet.
            }

            $gezaehlt++;

            $this->assertContains(
                $s[1],
                $schritte[$j[1]] ?? [],
                sprintf(
                    'SKILL.md Zeile %d ordnet dem Job „%s" den Schritt „%s" zu '
                    ."— den hat er nicht.\nEr hat: %s",
                    (int) $ab + 3 + $i,
                    $j[1],
                    $s[1],
                    implode(' · ', $schritte[$j[1]] ?? ['(keinen benannten)'])
                )
            );
        }

        $this->assertGreaterThanOrEqual(
            self::MINDESTENS_NAHT,
            $gezaehlt,
            'Die Naht-Tabelle ist fast leer — der Leser trifft sie nicht mehr, '
            .'oder sie ist zusammengeschrumpft. In beiden Fällen prüft dieser '
            .'Fall nichts.'
        );
    }

    /**
     * Die benannten Schritte je Job, gelesen wie die Jobnamen.
     *
     * @return array<string, list<string>>
     */
    private function schrittNamen(): array
    {
        $je = [];

        foreach (['ci.yml', 'waechter.yml'] as $datei) {
            $text = @file_get_contents($this->root().'/.github/workflows/'.$datei);
            if (! is_string($text)) {
                continue;
            }

            $job = null;
            $offen = false;
            $inJobs = false;

            foreach (explode("\n", $text) as $zeile) {
                if (preg_match('/^jobs:\s*$/', $zeile) === 1) {
                    $inJobs = true;

                    continue;
                }
                if (! $inJobs) {
                    continue;
                }

                if (preg_match('/^  [A-Za-z0-9_-]+:\s*$/', $zeile) === 1) {
                    $job = null;
                    $offen = true;

                    continue;
                }
                if ($offen && preg_match('/^    name:\s*(\S.*?)\s*$/', $zeile, $t) === 1) {
                    $job = $this->ohneMatrix($t[1]);
                    $je[$job] ??= [];
                    $offen = false;

                    continue;
                }
                if ($job !== null && preg_match('/^      - name:\s*(\S.*?)\s*$/', $zeile, $t) === 1) {
                    $je[$job][] = trim($t[1]);
                }
            }
        }

        return $je;
    }

    public function test_every_file_the_skill_names_exists(): void
    {
        $text = $this->skill();

        preg_match_all('/`([^`\s]+)`/', $text, $t);

        $geprueft = 0;

        foreach (array_unique($t[1]) as $wert) {
            // Ein Pfad und kein Wort: enthält einen Schrägstrich, endet nicht
            // auf einem (dann ist es ein Verzeichnis, und `vendor/` gibt es
            // nicht auf jeder Maschine), und trägt keinen Platzhalter.
            if (! str_contains($wert, '/') || str_ends_with($wert, '/')) {
                continue;
            }
            if (str_contains($wert, '…') || str_contains($wert, '<') || str_contains($wert, '...')) {
                continue;
            }

            $geprueft++;
            $voll = $this->root().'/'.$wert;

            if (str_contains($wert, '*')) {
                $this->assertNotEmpty(
                    glob($voll) ?: [],
                    sprintf('SKILL.md nennt „%s" — dorthin passt keine Datei.', $wert)
                );

                continue;
            }

            $this->assertFileExists(
                $voll,
                sprintf(
                    'SKILL.md nennt „%s" — die Datei gibt es nicht. Ein Griff, '
                    .'den der Steward bei rotem Lauf kopiert, läuft damit ins '
                    .'Leere.',
                    $wert
                )
            );
        }

        $this->assertGreaterThanOrEqual(
            4,
            $geprueft,
            'Der Skill nennt kaum noch Pfade — vermutlich greift der Ausdruck '
            .'ins Leere. Ohne Treffer ist dieser Fall wortlos grün.'
        );
    }

    public function test_the_skill_carries_its_frontmatter(): void
    {
        $text = $this->skill();

        $this->assertStringStartsWith(
            "---\n",
            $text,
            'Ohne Frontmatter ist der Skill nicht als `/steward` aufrufbar. '
            .'Gelesen wird er bei einem CI-Ereignis zwar trotzdem — aber von '
            .'Hand holen kann ihn dann niemand.'
        );

        $this->assertMatchesRegularExpression(
            '/^---\n(?:.*\n)*?name:\s*steward\s*\n/D',
            $text,
            'Der Name im Frontmatter ist nicht `steward`. Der Ordnername '
            .'entscheidet, ob die Sitzung den Skill bei einem PR-Ereignis '
            .'findet; der Name im Frontmatter, ob ein Mensch ihn rufen kann.'
        );
    }
}
