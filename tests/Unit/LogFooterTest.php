<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemLogsTail;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Die Naht zwischen einer Protokolloperation und ihrer Seite — für **jedes**
 * Paar und nicht für eines.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Die Fusszeile hat zwei Dinge behauptet, die sie nicht wusste (`docs/86`,
 * Befund 14): „gelesen wurden die letzten 500 Zeilen" kam aus der **Konstante**
 * `MAX_LINES` und nicht aus einer Messung, und der Knopf „Mehr Zeilen" stand
 * unter `props.lines < 500` statt unter der Frage, ob es mehr zu zeigen gibt.
 *
 * > **Eine Grenze, die als Zahl mitgesendet wird, ohne dass jemand nachsieht,
 * > ob sie erreicht wurde, ist eine Behauptung über die Datei und keine über
 * > den Lauf.**
 *
 * ## Warum er Paare hält und nicht ein Paar
 *
 * **Bis zum 14. September 2026 kannte er genau eine Seite.** `web.logs.tail`
 * sendete `complete` und `capped` seit `docs/914`, der `DomainController` warf
 * beide weg, und die Domainseite sagte über ihren Umfang gar nichts — derselbe
 * Befund, eine Seite weiter, und dieser Wächter war grün.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * In diesem Repo ist das die am häufigsten wiederholte Familie: der Menüpunkt,
 * der dreimal zu tief lag, die ACME-Prüfdatei gegen `CronApply::SPOOL_DIR`, der
 * Streifen ohne Tabellen darunter. Eine Behebung wird hier zur Regel, indem der
 * Wächter über **alle** Paare läuft; kommt ein drittes Protokoll dazu, trägt es
 * sich in {@see self::PAARE} ein und wird sofort gemessen.
 *
 * ## Beide Richtungen
 *
 * Ein Wächter, der nur prüft, dass die Seite bekannte Felder liest, hält den
 * Fall nicht auf, in dem der Agent ein Feld schickt, das niemand anzeigt —
 * und das ist genau die Familie von `context` (`docs/66`), `Result::truncated`
 * (`docs/914 §12`) und `kind` bei `web.logs.tail` (`docs/919`):
 *
 * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
 * > einem zu unterscheiden, das es nicht gibt.**
 *
 * ## Kommentare fallen weg, bevor gesucht wird
 *
 * **Vorsorglich und nicht aus Vorsicht.** Beide Dateien halten ihren
 * Vorzustand im Kommentar fest — `window` steht dort wörtlich, und roh
 * gelesen bliebe dieser Wächter für ein Feld grün, das es nicht mehr gibt.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht — und ein Kommentar, der die entfernte Zeile zitiert, stellt sie für
 * > ihn wieder her.**
 *
 * **Was er nicht hält:** ob die Zahlen stimmen. Das hält {@see LogWindowTest}
 * an echten Dateien.
 */
final class LogFooterTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    /**
     * Operation, Seite, und wie die Antwort auf der Seite heisst.
     *
     * **Der Name der Ablage steht dabei, weil er je Seite ein anderer ist.**
     * `/logs` liest `props.result`, die Domainseite `props.log`. Ein Wächter,
     * der `props\.result\.` fest einbaute, fände auf der zweiten Seite **null**
     * gelesene Felder und meldete jedes gesendete als ungelesen — also einen
     * Befund je Feld für eine Seite, die in Ordnung ist.
     *
     * `ohne_anzeige` sind Felder, die keine Anzeige brauchen: `source` und
     * `label` beschriften die Auswahl darüber, `filter` reist zurück in das
     * Formular. Sie stehen namentlich da und nicht als Muster — eine Ausnahme,
     * die man aufzählen muss, fällt beim Wachsen auf.
     *
     * **Zwei Felder standen hier beinahe dazu und sind stattdessen entfernt
     * worden:** `origin` bei `system.logs.tail` (`docs/914 §12`) und `kind` bei
     * `web.logs.tail` (`docs/919`). Beide wurden gesendet und von niemandem
     * gelesen, weil die Seite denselben Wert aus einer anderen Quelle nimmt.
     * Eine Ausnahme hätte genau den Befund zugedeckt, den dieser Wächter machen
     * soll.
     */
    private const PAARE = [
        'system.logs.tail' => [
            'op' => 'agent/src/Ops/SystemLogsTail.php',
            'controller' => 'app/Http/Controllers/LogsController.php',
            'seite' => 'resources/js/Pages/Logs/Index.vue',
            'ablage' => 'props.result',
            'ohne_anzeige' => ['source', 'label', 'filter'],
            'knopf' => ['truncated'],
        ],
        'web.logs.tail' => [
            'op' => 'agent/src/Ops/WebLogsTail.php',
            'controller' => 'app/Http/Controllers/DomainController.php',
            'seite' => 'resources/js/Pages/Domains/Logs.vue',
            'ablage' => 'props.log',
            'ohne_anzeige' => [],
            'knopf' => ['complete', 'capped'],
        ],
    ];

    public function test_every_field_the_agent_sends_is_read_by_the_page(): void
    {
        foreach (self::PAARE as $name => $paar) {
            $gesendet = $this->gesendeteFelder($paar['op']);
            $gelesen = $this->geleseneFelder($paar['seite'], $paar['ablage']);

            $this->assertGreaterThanOrEqual(
                5,
                count($gesendet),
                sprintf('Unter fünf Feldern greift der Ausdruck für `%s` ins Leere statt zu messen.', $name),
            );

            foreach ($gesendet as $feld) {
                if (in_array($feld, $paar['ohne_anzeige'], true)) {
                    continue;
                }

                $this->assertContains(
                    $feld,
                    $gelesen,
                    sprintf('`%s` sendet `%s`, und die Seite liest es nicht.', $name, $feld),
                );
            }
        }
    }

    public function test_the_page_reads_no_field_the_agent_does_not_send(): void
    {
        foreach (self::PAARE as $name => $paar) {
            $gesendet = $this->gesendeteFelder($paar['op']);

            foreach ($this->geleseneFelder($paar['seite'], $paar['ablage']) as $feld) {
                $this->assertContains(
                    $feld,
                    $gesendet,
                    sprintf('Die Seite zu `%s` liest `%s`, und der Agent sendet es nicht.', $name, $feld),
                );
            }
        }
    }

    /**
     * Der Knopf hängt nicht an der Zahl, die er selbst verstellt.
     *
     * `props.lines` ist die **Anfrage** und keine Auskunft über den Lauf. Unter
     * dieser Bedingung steht der Knopf auch dann da, wenn schon alles zu sehen
     * ist — gemessen an einer Datei mit 36 Zeilen (`docs/919 §1`, M3) — und
     * auch dann, wenn eine grössere Anfrage byteweise dasselbe liefert (M9).
     */
    public function test_the_button_never_hangs_on_the_requested_count(): void
    {
        foreach (self::PAARE as $name => $paar) {
            $this->assertStringNotContainsString(
                'v-if="props.lines <',
                $this->markup($paar['seite']),
                sprintf('Der Knopf der Seite zu `%s` hängt an der Anfrage statt am Ergebnis.', $name),
            );
        }
    }

    /**
     * Und er hängt am Urteil der Antwort — an **jedem** Feld, das dazugehört.
     *
     * **Die Bedingung wird durch ein `computed` hindurch gelesen**, denn welche
     * Frage richtig ist, ist je Seite eine andere: `/logs` fragt `truncated` —
     * mehr Treffer als gezeigte Zeilen —, die Domainseite fragt `complete`
     * **und** `capped`, weil ihr Fenster **die Anfrage** ist und nicht eine
     * feste Grösse. Ein Wächter, der beide über einen Ausdruck prüfte, hielte
     * auf einer der beiden Seiten die falsche Zusage.
     *
     * **„Irgendein Feld der Antwort" wäre zu wenig**, und das ist gemessen: Die
     * erste Fassung dieses Falls verlangte nur, dass die Bedingung überhaupt
     * ein gesendetes Feld nennt. `props.result.read > 0` hätte sie erfüllt und
     * den Knopf wieder falsch stehen lassen.
     *
     * Die Liste je Paar ist deshalb kein Ersatz für eine Regel, sondern die
     * Regel selbst — und sie wird **gegen die Wirklichkeit gehalten**: Jedes
     * genannte Feld muss die Operation auch senden, sonst prüfte ein Tippfehler
     * in dieser Liste stillschweigend nichts mehr.
     */
    public function test_the_button_hangs_on_the_verdict_of_the_answer(): void
    {
        foreach (self::PAARE as $name => $paar) {
            $markup = $this->markup($paar['seite']);
            $bedingung = $this->bedingungDesKnopfes($markup, $name);
            $gesendet = $this->gesendeteFelder($paar['op']);

            $this->assertNotSame([], $paar['knopf'], sprintf('Für `%s` steht kein Urteil da.', $name));

            foreach ($paar['knopf'] as $feld) {
                $this->assertContains(
                    $feld,
                    $gesendet,
                    sprintf('`%s` soll den Knopf über `%s` entscheiden, sendet das Feld aber nicht.', $name, $feld),
                );

                $this->assertStringContainsString(
                    $paar['ablage'].'.'.$feld,
                    $bedingung,
                    sprintf(
                        'Der Knopf der Seite zu `%s` steht unter `%s` und fragt `%s` nicht.',
                        $name,
                        $bedingung,
                        $feld,
                    ),
                );
            }
        }
    }

    /**
     * Der Controller dazwischen lässt nichts fallen.
     *
     * **Das ist der Schenkel, an dem dieser Befund wirklich entstanden ist.**
     * Der Agent sendete `complete` und `capped`, die Seite hätte sie zeigen
     * können — dazwischen stand ein Controller, der die Antwort Feld für Feld
     * abschrieb und drei davon nicht mitnahm. Ein Wächter, der nur Agent und
     * Seite gegeneinanderhält, sagt darüber nichts: Auf der Seite kommt das
     * Feld gar nicht erst an.
     *
     * > **Zwei Enden, die zusammenpassen, sagen über die Strecke dazwischen
     * > nichts.**
     *
     * Gehalten wird es ohne Ausnahme je Seite, weil die beiden Controller es
     * verschieden machen und **beide Arten in Ordnung sind**: `LogsController`
     * weist die Antwort im Ganzen zu (`$result = $answer`), `DomainController`
     * übernimmt getypt Feld für Feld. Die Regel lautet deshalb: **Wer Felder
     * einzeln nennt, nennt alle — und wer keines nennt, reicht sie im Ganzen
     * durch.** Kein Zweig wird übersprungen; ein Wächter, der beim Fehlen
     * seiner Voraussetzung überspringt, meldet das Fehlen der Voraussetzung
     * nicht.
     */
    public function test_a_controller_that_picks_fields_picks_all_of_them(): void
    {
        foreach (self::PAARE as $name => $paar) {
            $quelle = $this->withoutComments(
                (string) file_get_contents(__DIR__.'/../../'.$paar['controller']),
            );

            preg_match_all("/\\\$result\\['([a-z_]+)'\\]\s*=/", $quelle, $treffer);
            $uebernommen = array_values(array_unique($treffer[1]));

            if ($uebernommen === []) {
                $this->assertStringContainsString(
                    '$result = $answer;',
                    $quelle,
                    sprintf(
                        'Der Controller zu `%s` nennt kein einziges Feld und reicht die Antwort auch nicht '
                        .'im Ganzen durch — dann kommt auf der Seite nichts an.',
                        $name,
                    ),
                );

                continue;
            }

            foreach ($this->gesendeteFelder($paar['op']) as $feld) {
                $this->assertContains(
                    $feld,
                    $uebernommen,
                    sprintf(
                        'Der Controller zu `%s` übernimmt Felder einzeln und lässt `%s` dabei fallen.',
                        $name,
                        $feld,
                    ),
                );
            }
        }
    }

    /**
     * Die beiden Gründe, aus denen ein Fenster unvollständig sein kann.
     *
     * Sie stehen als Regel da und nicht als Liste je Seite: `complete` und
     * `capped` bedeuten auf jedem Protokoll dasselbe, und die Abhilfe für den
     * einen lässt den anderen stehen (`docs/914 §2`).
     */
    private const UNVOLLSTAENDIG = ['complete', 'capped'];

    /**
     * Die Seite **sagt** die beiden Gründe — sie benutzt sie nicht nur.
     *
     * **Diese Regel fehlte, und ein Eingriff hat sie gefunden.** Am
     * 14. September 2026 wurde der `capped`-Zweig der Fusszeile durch `false`
     * ersetzt; {@see self::test_every_field_the_agent_sends_is_read_by_the_page}
     * blieb **grün**, weil das Feld im `computed` des Knopfes stehenblieb. Die
     * Seite hätte den Knopf richtig versteckt und nie gesagt, warum.
     *
     * > **Ein Eingriff, der nicht beisst, ist entweder schlecht gewählt — oder
     * > er zeigt eine Regel, die es nicht gibt.**
     *
     * Gefragt wird deshalb der **Vorlagenblock** und nicht die Datei: Ein Feld,
     * das nur im Skript vorkommt, steuert etwas und sagt nichts.
     */
    public function test_the_page_says_both_reasons_and_not_only_uses_them(): void
    {
        foreach (self::PAARE as $name => $paar) {
            $vorlage = $this->vorlage($paar['seite'], $name);

            foreach (self::UNVOLLSTAENDIG as $feld) {
                $this->assertStringContainsString(
                    $paar['ablage'].'.'.$feld,
                    $vorlage,
                    sprintf(
                        'Die Seite zu `%s` liest `%s`, sagt es aber nirgends — ein Feld, das nur im Skript '
                        .'vorkommt, steuert etwas und sagt nichts.',
                        $name,
                        $feld,
                    ),
                );
            }
        }
    }

    /**
     * Die Zahl der gelesenen Zeilen kommt aus der Antwort und nicht aus einer
     * Konstante.
     *
     * **Nur für `/logs`.** Dort wählt ein Filter aus einem Fenster fester
     * Grösse, und `read` sagt, woraus die Treffer stammen. Die Domainseite hat
     * keinen Filter: Sie fragt hundert Zeilen und bekommt hundert; `read` wäre
     * dort die Zahl der Zeilen, die der blockweise Leser zufällig mitgelesen
     * hat — eine Eigenschaft des Verfahrens und keine Auskunft über die Datei
     * (`docs/919 §3`).
     */
    public function test_the_sentence_counts_what_was_read(): void
    {
        $markup = $this->markup(self::PAARE['system.logs.tail']['seite']);

        $this->assertStringContainsString('props.result.read', $markup);
        $this->assertStringNotContainsString(
            'props.result.window',
            $markup,
            '`window` war die Konstante '.SystemLogsTail::MAX_LINES.' und ist fort.',
        );
    }

    /**
     * Die Felder der `return`-Blöcke von `execute()` — und nicht die des
     * ganzen Rumpfes.
     *
     * **Gemessen am 14. September 2026, und das war ein Befund an diesem
     * Wächter.** Sein Ausdruck las den ganzen Rumpf und suchte `'feld' =>`.
     * Für `SystemLogsTail` stimmte das Ergebnis — dort steht sonst nichts
     * dergleichen —, für `WebLogsTail` fand er **elf** statt sieben: Die vier
     * zuviel sind `subscription`, `user`, `domain` und `document_root`, also
     * die Argumente von `Site::fromArgs()`.
     *
     * > **Ein Ausdruck, der über den ganzen Rumpf liest, misst die Rückgabe nur
     * > so lange, wie der Rumpf sonst nichts Ähnliches enthält.**
     *
     * Gezählt wird über die Klammertiefe und nicht mit einem Ausdruck über die
     * Zeilen: Eine Rückgabe darf verschachtelt sein, und ein Muster, das am
     * ersten `]` aufhörte, verlöre alles danach.
     *
     * @return list<string>
     */
    private function gesendeteFelder(string $pfad): array
    {
        $quelle = $this->withoutComments((string) file_get_contents(__DIR__.'/../../'.$pfad));

        $von = strpos($quelle, 'public function execute(');
        $this->assertIsInt($von, 'execute() nicht gefunden in '.$pfad);

        $bis = strpos($quelle, "\n    }", $von);
        $this->assertIsInt($bis, 'Das Ende von execute() nicht gefunden in '.$pfad);

        $rumpf = substr($quelle, $von, $bis - $von);
        $felder = [];
        $ab = 0;

        while (($start = strpos($rumpf, 'return [', $ab)) !== false) {
            $i = $start + strlen('return [');
            $tiefe = 1;

            while ($i < strlen($rumpf) && $tiefe > 0) {
                if ($rumpf[$i] === '[') {
                    $tiefe++;
                }

                if ($rumpf[$i] === ']') {
                    $tiefe--;
                }

                $i++;
            }

            preg_match_all("/'([a-z_]+)' =>/", substr($rumpf, $start, $i - $start), $treffer);
            $felder = array_merge($felder, $treffer[1]);
            $ab = $i;
        }

        return array_values(array_unique($felder));
    }

    /** @return list<string> */
    private function geleseneFelder(string $pfad, string $ablage): array
    {
        preg_match_all(
            '/'.preg_quote($ablage, '/').'\.([a-z_]+)/',
            $this->markup($pfad),
            $treffer,
        );

        return array_values(array_unique($treffer[1]));
    }

    /**
     * Die Bedingung des Knopfes „Mehr Zeilen", aufgelöst durch ein `computed`.
     *
     * Gesucht wird der Knopf an seiner Beschriftung und nicht als erster
     * `<button>` der Seite: Die Domainseite hat zwei Umschalter darüber, und
     * der erste Knopf wäre der falsche.
     */
    private function bedingungDesKnopfes(string $markup, string $name): string
    {
        $bei = strpos($markup, 'Mehr Zeilen (');
        $this->assertIsInt($bei, sprintf('Der Knopf „Mehr Zeilen" fehlt auf der Seite zu `%s`.', $name));

        $auf = strrpos(substr($markup, 0, $bei), '<button');
        $this->assertIsInt($auf, sprintf('Kein `<button>` vor der Beschriftung auf der Seite zu `%s`.', $name));

        $zu = strpos($markup, '>', $auf);
        $this->assertIsInt($zu, 'Die Marke des Knopfes endet nicht.');

        preg_match('/v-if="([^"]+)"/', substr($markup, $auf, $zu - $auf), $treffer);
        $bedingung = $treffer[1] ?? '';

        $this->assertNotSame('', $bedingung, sprintf('Der Knopf auf der Seite zu `%s` trägt kein `v-if`.', $name));

        // Ein blosser Name ist ein `computed`; seine Bedingung steht im Skript.
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $bedingung) === 1) {
            preg_match('/const '.preg_quote($bedingung, '/').' = computed\(([^\n]*)/', $markup, $auflösung);

            $this->assertArrayHasKey(
                1,
                $auflösung,
                sprintf('`%s` ist kein `computed` in einer Zeile auf der Seite zu `%s`.', $bedingung, $name),
            );

            return $auflösung[1];
        }

        return $bedingung;
    }

    /**
     * Der Vorlagenblock einer `.vue`, ohne ihre Kommentare.
     *
     * Gesucht wird das **letzte** `</template>`: Eine Vorlage darf verschachtelte
     * `<template>`-Marken enthalten, und dieses Repo benutzt sie reichlich.
     */
    private function vorlage(string $pfad, string $name): string
    {
        $markup = $this->markup($pfad);

        $von = strpos($markup, '<template>');
        $this->assertIsInt($von, sprintf('Kein Vorlagenblock auf der Seite zu `%s`.', $name));

        $bis = strrpos($markup, '</template>');
        $this->assertIsInt($bis, sprintf('Der Vorlagenblock der Seite zu `%s` endet nicht.', $name));

        return substr($markup, $von, $bis - $von);
    }

    private function markup(string $pfad): string
    {
        return $this->withoutMarkupComments((string) file_get_contents(__DIR__.'/../../'.$pfad));
    }
}
