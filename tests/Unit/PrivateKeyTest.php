<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der private Schlüssel verlässt den Browser nicht.
 *
 * ## Die Zusage, um die es geht
 *
 * Wunsch 2 des Betreibers (`docs/64 §5`) lässt das Panel Schlüssel erzeugen.
 * Der private Teil entsteht dabei im Browser des Kunden — nicht aus Vorsicht,
 * sondern weil der Weg über den Server durch zwei Einrichtungen führt, die
 * **beide auf die Platte schreiben**: die Sitzung (`SESSION_DRIVER=database`)
 * und den Vorgang (`operations.payload`, `operations.result`; die überleben
 * sogar das Zurückbauen des Abonnements).
 *
 * > **Ein privater Schlüssel, den der Server nie hatte, kann er nicht
 * > verlieren.**
 *
 * ## Warum das ein Wächter braucht und kein Kommentar reicht
 *
 * Weil der Bruch **eine Zeile** ist und wie eine Verbesserung aussieht. Ein
 * `form.privat = privaterTeil.value`, damit „der Server den Fingerabdruck
 * gleich mitrechnet", und die Zusage ist fort — ohne Fehlermeldung, ohne
 * sichtbare Änderung, und der Schlüssel liegt danach in `sessions` und in
 * `operations`.
 *
 * > **Eine Zusage, deren Bruch aussieht wie eine Verbesserung, hält kein
 * > Kommentar.**
 */
final class PrivateKeyTest extends TestCase
{
    /** Die Seite, auf der erzeugt wird. */
    private const PAGE = 'resources/js/Pages/Subscriptions/Sftp.vue';

    /** Der Baustein, der erzeugt. */
    private const MODULE = 'resources/js/ssh/openssh.ts';

    /** Die Messung, die ihn gegen ein echtes `ssh-keygen` hält. */
    private const MEASUREMENT = 'tests/schluessel-messen.mjs';

    /**
     * Wörter, an denen man erkennt, dass etwas den Rechner verlässt.
     *
     * @var list<string>
     */
    private const TRANSPORT = [
        'fetch(', 'XMLHttpRequest', 'sendBeacon', 'router.', 'axios',
        'WebSocket', 'EventSource',

        /*
         * **`form.` und nicht `form.post`.** Der erste Anlauf führte nur die
         * drei Sendemethoden — und der Bruch, der diesen Wächter überführt hat,
         * war `form.key = privaterTeil.value`: eine Zuweisung, kein Versand.
         * Sie reist erst eine Zeile später mit, und dort steht der private Teil
         * nicht mehr.
         *
         * > **Ein Wächter, der auf den Versand sieht, verpasst das Einpacken.**
         *
         * Keine berechtigte Zeile dieser Seite trägt beides; geprüft wird
         * zeilenweise, also darf `form.` in der Liste stehen.
         */
        'form.',
    ];

    /**
     * Der Baustein kennt keinen Weg nach draussen.
     *
     * **Die stärkste Fassung dieser Regel**, denn sie hängt an keiner
     * Schreibweise: Wo kein Transportmittel steht, kann nichts reisen.
     */
    public function test_the_module_has_no_way_out(): void
    {
        $quelle = $this->read(self::MODULE);

        foreach (self::TRANSPORT as $wort) {
            $this->assertStringNotContainsString($wort, $quelle, sprintf(
                '%s enthält „%s". Dieser Baustein erzeugt einen privaten Schlüssel; er hat nach '.
                'draussen nichts zu schicken (docs/64 §5.2).',
                self::MODULE,
                $wort,
            ));
        }
    }

    /**
     * Und auf der Seite steht der private Teil auf keiner Zeile, die etwas
     * verschickt.
     *
     * **Zeilenweise und nicht dateiweise.** Die Datei *muss* `form.post`
     * enthalten — der öffentliche Teil geht ja an den Server. Die Frage ist,
     * ob er in derselben Zeile steht wie der private.
     */
    public function test_the_private_part_never_shares_a_line_with_a_transport(): void
    {
        $zeilen = explode("\n", $this->read(self::PAGE));
        $getroffen = [];
        $gesehen = 0;

        foreach ($zeilen as $nummer => $zeile) {
            if (! str_contains($zeile, 'privaterTeil')) {
                continue;
            }

            $gesehen++;

            foreach (self::TRANSPORT as $wort) {
                if (str_contains($zeile, $wort)) {
                    $getroffen[] = sprintf('Zeile %d: %s', $nummer + 1, trim($zeile));
                }
            }
        }

        // Eine Null ist nur dann eine Messung, wenn daneben etwas anderes steht.
        $this->assertGreaterThanOrEqual(4, $gesehen, sprintf(
            'Der private Teil kommt nur %dmal vor. Heisst er anders, prüft dieser Wächter '.
            'nichts mehr — und die Zusage steht ohne ihn da.',
            $gesehen,
        ));

        $this->assertSame([], $getroffen, sprintf(
            "Der private Schlüssel steht auf einer Zeile, die etwas verschickt:\n  %s\n\n".
            'Er entsteht im Browser und bleibt dort. Was der Server bekommt, ist der '.
            'öffentliche Teil — auf demselben Weg wie eine Eingabe von Hand (docs/64 §5.2).',
            implode("\n  ", $getroffen),
        ));
    }

    /**
     * Das Formular schickt genau zwei Felder.
     *
     * **Die Sperrklinke daneben.** Die Regel darüber liest Zeilen; ein drittes
     * Feld im Formular wäre der bequeme Weg daran vorbei.
     */
    public function test_the_form_carries_only_the_public_parts(): void
    {
        $this->assertMatchesRegularExpression(
            "/useForm\(\{\s*label:\s*'',\s*key:\s*''\s*\}\)/",
            $this->read(self::PAGE),
            'Das Formular dieser Seite trägt andere Felder als Bezeichnung und öffentlichen '.
            'Schlüssel. Alles, was hier steht, geht an den Server.',
        );
    }

    /**
     * Und der private Teil wird genau einmal gezeigt.
     *
     * Ein zweites Mal hiesse, dass er zwischen zwei Anfragen irgendwo liegt —
     * und die Frage wäre dann nur noch, wo.
     */
    public function test_it_is_shown_once(): void
    {
        $quelle = $this->read(self::PAGE);

        /*
         * **`=` ist auch das erste Zeichen von `===`.** Der erste Anlauf zählte
         * drei Zuweisungen, und zwei davon waren Vergleiche
         * (`privaterTeil.value === null`). Genau dieselbe Zeile hat am selben
         * Tag in `RevealTest` zwei Löcher erfunden, die es nie gab — und ich
         * habe sie hier eine Stunde später noch einmal geschrieben.
         *
         * > **Ein Ausdruck, der eine Zuweisung sucht, findet jeden Vergleich
         * > mit, solange er das Gleichheitszeichen nicht abgrenzt.**
         */
        $this->assertSame(
            1,
            preg_match_all('/privaterTeil\.value\s*(?<![=!<>])=(?!=)/', $quelle),
            'Der private Teil wird mehr als einmal gesetzt. Gezeigt wird er einmal; wer ihn '.
            'verliert, erzeugt einen neuen und entfernt den alten (docs/64 §5.5).',
        );

        $this->assertStringContainsString(
            'erzeugt.value = true',
            $quelle,
            'Nichts hindert einen zweiten Lauf. Der Knopf gehört nach dem ersten Druck '.
            'gesperrt, sonst steht der zweite Schlüssel über dem ersten.',
        );
    }

    /**
     * Die Messung hält denselben Baustein, den die Seite einbindet.
     *
     * **Die Fehlerklasse, gegen die dieses Projekt die meisten Wächter hat:**
     * eine Zeichenkette, die auf etwas verweist, ohne dass der Bezug geprüft
     * wird. Eine Messung gegen eine Abschrift wäre eine Messung an etwas
     * anderem als dem, was ausgeliefert wird.
     */
    public function test_the_measurement_holds_the_shipped_module(): void
    {
        $messung = $this->read(self::MEASUREMENT);

        /*
         * **Der Name im Text ist nicht der Name im `import`.** Der erste Anlauf
         * suchte den Pfad irgendwo in der Datei — und fand ihn im Kopfkommentar,
         * das den Baustein ohnehin nennt. Der Bruch zeigte die Einbindung auf
         * eine Abschrift, und der Wächter blieb grün.
         *
         * > **Ein Wächter, der einen Satz sucht statt seiner Erreichbarkeit,
         * > ist grün, sobald der Satz irgendwo steht.**
         */
        $this->assertMatchesRegularExpression(
            '/import\(\s*join\([^)]*'.preg_quote(self::MODULE, '/').'/',
            $messung,
            sprintf('%s bindet nicht %s ein. Dann misst sie eine zweite Fassung.', self::MEASUREMENT, self::MODULE),
        );

        $this->assertStringContainsString(
            'ssh-keygen',
            $messung,
            'Die Messung fragt kein echtes ssh-keygen. Ob OpenSSH eine Datei liest, sagt nur '.
            'OpenSSH.',
        );

        $this->assertStringContainsString(
            'Gegenprobe',
            $messung,
            'Die Messung hat keine Gegenprobe. Ein „ja" ohne ein „NEIN" daneben belegt nichts.',
        );

        $this->assertStringContainsString(
            'canGenerate',
            $this->read(self::PAGE),
            'Die Seite fragt nicht, ob der Browser es kann — dann steht der Knopf auch dort, wo '.
            'er nichts tut.',
        );
    }

    private function read(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad);
    }
}
