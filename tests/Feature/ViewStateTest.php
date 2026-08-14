<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Wunsch wird erst zur Anzeige, wenn die Antwort da ist.
 *
 * **Warum es diesen Wächter gibt.** Im Abnahmelauf von P5c stand die Kopfzeile
 * der Zeilentabelle auf `wert ↑`, während darunter die nach `id` sortierte Seite
 * lag (`docs/48 §3.4`). Der Griff hatte seinen Zustand gesetzt und danach
 * geladen; die Ladung lief ins Zeitlimit, und die Beschriftung blieb stehen.
 *
 * > **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen, als
 * > wäre sie durchgelaufen.**
 *
 * **Kein Test dieses Projekts konnte das sehen**, und das liegt nicht an einer
 * Lücke in der Abdeckung: Es entsteht erst aus dem Zusammenspiel von
 * Zeitüberschreitung und Anzeige, und beides zusammen gibt es weder im Container
 * noch in der CI. Gefunden hat es ein Mensch auf einem echten Server.
 *
 * Deshalb prüft dieser Wächter nicht das Verhalten, sondern die **Form, die das
 * Verhalten trägt**: Es gibt genau eine Stelle, die den Zustand sichert und
 * zurücknimmt, und was sie sichert, nimmt sie auch zurück.
 *
 * **Die zweite Hälfte ist die wichtigere.** Der Fehler, der hier wiederkommt,
 * ist nicht „jemand baut den Rückweg aus" — es ist „jemand fügt eine sechste
 * Angabe hinzu und trägt sie nur in die Sicherung ein". Die Anzeige ist dann für
 * fünf Angaben richtig und für eine falsch, und genau diese eine fällt niemandem
 * auf.
 */
final class ViewStateTest extends TestCase
{
    private function console(): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/Pages/Databases/Console.vue';

        return (string) file_get_contents($path);
    }

    /**
     * Der Block ab der ersten `{` nach `$from`, mit passenden Klammern.
     *
     * **Zeichenketten sind hier nicht ausgenommen**, und das ist eine bewusste
     * Grenze: In den Blöcken, die dieser Wächter liest, steht keine geschweifte
     * Klammer in einer Zeichenkette. Käme je eine dazu, zählte dieser Test
     * falsch und würde rot — was besser ist als stillschweigend das Falsche zu
     * lesen.
     */
    private function block(string $source, int $from): string
    {
        $start = strpos($source, '{', $from);

        if ($start === false) {
            return '';
        }

        $depth = 0;

        for ($i = $start; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            }

            if ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    /**
     * Die Ladung sagt, ob sie durchkam.
     *
     * **Ohne diesen Rückgabewert gibt es keinen Rückweg.** Sie fing den Fehler
     * vorher selbst ab und meldete nach aussen nichts; der Aufrufer konnte gar
     * nicht wissen, dass sein Zustand jetzt eine Anzeige beschreibt, die es
     * nicht gibt.
     */
    public function test_loading_a_page_says_whether_it_worked(): void
    {
        $source = $this->console();

        $this->assertStringContainsString(
            'async function loadPage(): Promise<boolean>',
            $source,
            'Die Ladung meldet nicht mehr, ob sie durchkam — dann kann niemand etwas zurücknehmen.',
        );

        $this->assertStringContainsString(
            'if (await loadPage()) {',
            $source,
            'Der Rückgabewert wird nicht gelesen. Ein Wert, den niemand liest, ist keiner.',
        );
    }

    /**
     * Was gesichert wird, wird auch zurückgenommen.
     *
     * Beide Richtungen: eine gesicherte Angabe ohne Rückweg lässt die Anzeige
     * falsch stehen, ein Rückweg ohne Sicherung schreibt `undefined` hinein.
     */
    public function test_every_saved_field_is_restored(): void
    {
        $source = $this->console();
        $start = strpos($source, 'async function change(');

        $this->assertNotFalse($start, 'Es gibt keine Stelle mehr, die den Zustand sichert.');

        $body = $this->block($source, $start);
        $snapshot = $this->block($body, (int) strpos($body, 'const zuvor ='));

        $this->assertNotSame('', $snapshot, 'Die Sicherung des Zustands ist fort.');

        preg_match_all('/^\s{4}(\w+):/m', $snapshot, $gesichert);
        preg_match_all('/^\s{2}(\w+)\.value = zuvor\.(\w+);?$/m', $body, $zurueck);

        $this->assertGreaterThanOrEqual(
            5,
            count($gesichert[1]),
            'Weniger als fünf gesicherte Angaben — dann liest dieser Wächter den falschen Block.',
        );

        $this->assertSame(
            $gesichert[1],
            $zurueck[1],
            'Gesichert und zurückgenommen wird nicht dasselbe. Eine Angabe ohne Rückweg lässt die '.
            'Anzeige falsch stehen; ein Rückweg ohne Sicherung schreibt `undefined` hinein.',
        );

        $this->assertSame(
            $zurueck[1],
            $zurueck[2],
            'Eine Angabe wird aus dem Feld einer anderen zurückgeholt.',
        );
    }

    /**
     * Und wer die Sicht ändert, ändert nur, was gesichert ist.
     *
     * **Das ist der Fall, der wiederkommt.** Eine neue Angabe der Sicht — ein
     * zweiter Filter, eine Gruppierung — wird in einem der Griffe gesetzt, und
     * die Sicherung erfährt davon nichts. Alles andere steht danach richtig da,
     * und diese eine nicht.
     */
    public function test_a_change_touches_nothing_it_cannot_take_back(): void
    {
        $source = $this->console();
        $body = $this->block($source, (int) strpos($source, 'async function change('));
        $snapshot = $this->block($body, (int) strpos($body, 'const zuvor ='));

        preg_match_all('/^\s{4}(\w+):/m', $snapshot, $gesichert);

        $erlaubt = $gesichert[1];
        $offset = 0;
        $geprueft = 0;

        while (($stelle = strpos($source, 'await change(() => {', $offset)) !== false) {
            $rumpf = $this->block($source, $stelle);
            $offset = $stelle + 1;
            $geprueft++;

            preg_match_all('/(\w+)\.value\s*=/', $rumpf, $gesetzt);

            foreach (array_unique($gesetzt[1]) as $name) {
                $this->assertContains(
                    $name,
                    $erlaubt,
                    sprintf(
                        '`%s` wird in einem Griff gesetzt, aber nicht gesichert. Scheitert die Ladung, '.
                        'bleibt diese eine Angabe auf dem neuen Wert stehen, während alles andere '.
                        'zurückgeht — und die Anzeige widerspricht sich an genau einer Stelle.',
                        $name,
                    ),
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            4,
            $geprueft,
            'Weniger als vier Griffe gehen über die Sicherung. Sortieren, Filtern, Filter entfernen, '.
            'Vor und Zurück sind fünf — wer hier abnimmt, hat einen davon am Rückweg vorbeigeführt.',
        );
    }
}
