<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Der Prozesszustand steht in Worten da — und das Wort des Kernels kommt an.
 *
 * **Warum es diesen Wächter gibt.** Am 11. September 2026 hat der Betreiber
 * gefragt, wofür das `S` in der Prozesstabelle steht. Gemessen war die Antwort
 * nicht „es fehlt eine Legende", sondern: `/proc/<pid>/status` schreibt
 * `State:\tS (sleeping)`, und `SystemInfo::processes()` behielt davon den
 * ersten Buchstaben. Die Erklärung war da und wurde eine Zeile vor der Anzeige
 * weggeworfen.
 *
 * > **Ein Feld, das gelesen und dann gekürzt wird, ist von einem, das es nicht
 * > gibt, für den Leser nicht zu unterscheiden.**
 *
 * **Dieselbe Familie wie Befund 5 aus dem A2-Nachlauf** (`docs/91 §13`): Die
 * Übersicht druckte `active_state` roh. `ServicesViewTest` hält seitdem, dass
 * kein Rohwert **von systemd** auf einer Seite steht; für den Rohwert vom
 * **Kernel** gab es nichts, und der stand auf derselben Seite.
 *
 * **Geprüft wird die Naht und nicht eine Seite davon.** `state_text` entsteht
 * im Agenten, reist durch den Controller und wird in der Vorlage deklariert —
 * drei Dateien, und jede für sich sähe in Ordnung aus, wenn eine ausfiele.
 * Genau dort sitzen die teuren Fehler dieses Repos.
 *
 * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
 * > einem zu unterscheiden, das es nicht gibt.**
 *
 * **Was er nicht kann:** Er sagt nicht, ob die deutschen Wörter *gut gewählt*
 * sind, und er kann die Abbildung nicht auf Vollständigkeit gegen den Kernel
 * prüfen — die Liste in `fs/proc/array.c` steht hier nicht zur Verfügung.
 * Genau deshalb prüft er den **Rückfall**: Was die Abbildung nicht kennt, muss
 * das Wort der Quelle bekommen und kein erfundenes.
 */
final class ProcessStateTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const AGENT = __DIR__.'/../../agent/src/Ops/SystemInfo.php';

    private const CONTROLLER = __DIR__.'/../../app/Http/Controllers/OverviewController.php';

    private const COMPOSABLE = __DIR__.'/../../resources/js/Composables/useProcessState.ts';

    private const PAGE = __DIR__.'/../../resources/js/Pages/Overview.vue';

    /**
     * Das Wort des Kernels entsteht, reist und kommt an.
     *
     * Alle drei in **einem** Fall: Eine Richtung allein hält nichts. Ein Feld,
     * das der Agent schreibt und der Controller fallen lässt, ist von aussen
     * dasselbe wie eines, das es nicht gibt — und eine Vorlage, die es
     * deklariert, während niemand es schickt, ist `undefined` im Rückfall.
     */
    public function test_the_kernel_word_travels_from_proc_to_the_page(): void
    {
        $stellen = [
            'Der Agent' => [self::AGENT, "'state_text' =>"],
            'Der Controller' => [self::CONTROLLER, "'state_text' =>"],
            'Die Seite' => [self::PAGE, 'state_text'],
        ];

        foreach ($stellen as $wer => [$datei, $gesucht]) {
            $this->assertStringContainsString($gesucht, $this->source($datei), implode("\n", [
                sprintf('%s führt `state_text` nicht mehr.', $wer),
                '',
                'Die drei Stellen sind eine Naht: Fällt eine aus, sehen die anderen beiden',
                'weiter richtig aus, und der Rückfall der Abbildung bekommt nichts zu lesen.',
                'Ein unbekannter Buchstabe stünde danach wieder nackt in der Tabelle.',
            ]));
        }
    }

    /**
     * Keine Seite druckt den Rohwert des Kernels.
     *
     * Weder den Buchstaben noch das englische Wort — beides ist das, wovon der
     * Betreiber am 11. September berichtet hat, und das zweite wäre zusätzlich
     * ein Verstoss gegen `docs/19 §4a`.
     */
    public function test_no_page_prints_a_raw_process_state(): void
    {
        $vorlage = $this->template(self::PAGE);

        foreach (['process.state', 'process.state_text'] as $roh) {
            $this->assertDoesNotMatchRegularExpression(
                '/\{\{[^}]*'.preg_quote($roh, '/').'[^}]*\}\}/',
                $vorlage,
                implode("\n", [
                    sprintf('Die Übersicht druckt `%s` roh.', $roh),
                    '',
                    'Ein Buchstabe erklärt sich nicht, und das Wort des Kernels ist englisch.',
                    'Gezeigt wird, was `useProcessState.ts` daraus macht.',
                ]),
            );
        }

        $this->assertStringContainsString('prozessZustand(process)', $vorlage, implode("\n", [
            'Die Zustandszelle ruft die Abbildung nicht.',
            '',
            'Ohne sie misst der Fall darüber nichts: Eine Zelle, die gar nichts zeigt,',
            'druckt auch keinen Rohwert.',
        ]));
    }

    /**
     * Der Rückfall nimmt das Wort der Quelle und erfindet keines.
     *
     * Die Reihenfolge ist das Tragende und wird als solche gemessen: Erst die
     * eigene Abbildung, dann `state_text`, dann der Buchstabe. Stünde der
     * Buchstabe vor `state_text`, bekäme ein unbekannter Zustand nie sein Wort.
     */
    public function test_the_fallback_takes_the_word_of_the_source(): void
    {
        $quelle = $this->source(self::COMPOSABLE);

        $bei = strpos($quelle, 'state_text !==');
        $this->assertNotFalse($bei, implode("\n", [
            'Der Rückfall auf `state_text` steht nicht mehr in `useProcessState.ts`.',
            '',
            'Ohne ihn bleibt einem Buchstaben, den die Abbildung nicht kennt, nur er',
            'selbst — und das ist genau der Zustand, der diesen Wächter ausgelöst hat.',
        ]));

        $this->assertGreaterThan(
            strpos($quelle, 'const wort = WORDS['),
            $bei,
            'Der Rückfall steht vor der Abbildung; dann käme das deutsche Wort nie zum Zug.',
        );

        /*
         * Kein erfundenes Wort. Gesucht werden die Zeichenketten, die ein
         * Rückfall „aus Höflichkeit" bekommt — sie sagen dem Leser weniger als
         * der Buchstabe und behaupten dabei mehr.
         */
        foreach (['unbekannt', 'unklar', 'nicht feststellbar'] as $erfunden) {
            $this->assertStringNotContainsString("'".$erfunden."'", $quelle, implode("\n", [
                sprintf('`useProcessState.ts` fällt auf „%s" zurück.', $erfunden),
                '',
                'Der Kernel hat ein Wort für diesen Zustand geschrieben, und der Agent',
                'trägt es her. Ein eigenes daneben ist eine Auskunft, die niemand erhoben',
                'hat — und sie verdeckt die, die es gibt.',
            ]));
        }
    }

    /**
     * Die Untergrenze: Die Abbildung trägt die gemessenen Zustände.
     *
     * `R`, `S` und `I` sind am 11. September 2026 über alle Prozesse eines
     * Containers wirklich beobachtet worden. Fehlt einer, greift der Ausdruck
     * ins Leere oder die Abbildung ist zusammengeschrumpft — beides macht die
     * Fälle darüber wertlos, ohne dass sie rot würden.
     */
    public function test_the_measured_states_are_mapped(): void
    {
        $quelle = $this->source(self::COMPOSABLE);

        foreach (['R', 'S', 'I'] as $buchstabe) {
            $this->assertMatchesRegularExpression(
                '/^\s*'.$buchstabe.":\s*'/m",
                $quelle,
                sprintf(
                    "Der Zustand `%s` fehlt in der Abbildung.\n\n".
                    'Er ist gemessen und nicht angenommen; ohne ihn zeigt die Tabelle für '.
                    'den häufigsten Fall das englische Wort des Kernels.',
                    $buchstabe,
                ),
            );
        }
    }

    /** Eine Datei ohne ihre Kommentare — je nach Art. */
    private function source(string $datei): string
    {
        $roh = file_get_contents($datei);
        $this->assertNotFalse($roh, sprintf('`%s` ist nicht lesbar.', basename($datei)));

        /*
         * Warum das hier nicht wegzulassen ist: Der Kopf von
         * `useProcessState.ts` zitiert `state_text` und `substr(…, 0, 1)`, um
         * die Behebung zu erklären. Roh gelesen wären die Fälle oben grün,
         * nachdem jemand den Code entfernt hat.
         */
        return str_ends_with($datei, '.php')
            ? $this->withoutComments($roh)
            : $this->withoutMarkupComments($this->withoutBlockComments($roh));
    }

    /** Der Vorlagenblock einer `.vue`, ohne Kommentare. */
    private function template(string $datei): string
    {
        $quelle = $this->source($datei);

        $von = strpos($quelle, '<template>');
        $this->assertNotFalse($von, sprintf('`%s` hat keinen Vorlagenblock.', basename($datei)));

        $bis = strrpos($quelle, '</template>');

        return substr($quelle, $von, $bis === false ? null : $bis - $von);
    }

    /**
     * `/* … *\/` und `//` heraus — für TypeScript und den Skriptblock einer
     * `.vue`. `WithoutMarkupComments` kennt nur `<!-- … -->`, und die
     * Begründungen dieses Merkmals stehen in Blockkommentaren.
     */
    private function withoutBlockComments(string $quelle): string
    {
        $ohne = preg_replace('#/\*.*?\*/#s', '', $quelle);

        return preg_replace('#(^|\s)//[^\n]*#', '$1', $ohne ?? $quelle) ?? $quelle;
    }
}
