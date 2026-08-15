<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Ein Griff, der mehrere Einträge anfasst, schafft die Hälfte — und sagt es.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Mit der Mehrfachauswahl (P6 Schritt 5h) wird der Fall zum Normalfall, der bis
 * dahin einer von tausend war: **Eintrag 7 von 20 scheitert, und die anderen
 * neunzehn sind schon getan.** Ein Vorgang, der dabei „Der Eintrag ist entfernt"
 * meldet, ist genau der Befund aus `docs/48 §3.5`:
 *
 * > **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen, als
 * > wäre sie durchgelaufen.**
 *
 * Dieselbe Frage ist beim Hochladen mehrerer Dateien schon einmal gestellt und
 * dort einmal richtig beantwortet worden. Sie hier ein zweites Mal von Hand zu
 * beantworten hiesse, zwei Fassungen derselben Regel zu haben — und die
 * seltener benutzte ist die, die veraltet.
 *
 * ## Und der zweite Fehler, den er festhält
 *
 * Beim Mehrfach-Upload setzte die Seite den Zielpfad zusammen. Bei **einer**
 * Datei war das richtig; bei zwanzig war es **ein** Pfad für alle — neunzehnmal
 * überschrieben, und der Vorgang meldete Erfolg. Kopieren und Verschieben haben
 * dieselbe Form, und deshalb dieselbe Regel:
 *
 * > **Ein Ziel für viele Quellen ist ein Verzeichnis und kein Pfad.**
 *
 * Geprüft wird der **Quelltext** und nicht das Verhalten: Diese Wege laufen
 * gegen den Agenten, und den gibt es im Container nicht. Was hier steht, ist die
 * Form — und die Form ist es, die beim nächsten Griff abgeschrieben wird.
 */
final class BulkActionTest extends TestCase
{
    /**
     * Die Griffe, die eine Auswahl entgegennehmen.
     *
     * **Eine Aufzählung, und sie wird gegengeprüft** ({@see
     * self::test_the_listed_handlers_all_exist}): Ein Name, den es nicht mehr
     * gibt, sähe aus wie eine Abdeckung und wäre eine Lücke — dieselbe Falle wie
     * `pager` in `BlockSpacingTest`.
     *
     * @var list<string>
     */
    private const HANDLERS = ['remove', 'move', 'copy', 'compress'];

    /**
     * Die Griffe, die je Eintrag arbeiten und je Eintrag scheitern können.
     *
     * `compress` gehört nicht dazu, und das ist kein Versehen: Packen tut
     * **einmal** etwas über alle. Ein Archiv, das die halbe Auswahl enthält und
     * Erfolg meldet, wäre schlimmer als eines, das gar nicht entsteht.
     *
     * @var list<string>
     */
    private const LOOPING = ['remove', 'move', 'copy'];

    private function controller(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/FileController.php',
        );
    }

    /**
     * Der Rumpf einer Methode, über Klammernzählung.
     *
     * **Nicht über einen regulären Ausdruck bis zur nächsten `public function`.**
     * Zwischen zwei Methoden dieser Klasse stehen Dokumentationsblöcke mit
     * geschweiften Klammern in `{@see …}`, und ein Ausdruck, der bis zur
     * nächsten Signatur liest, nimmt sie mit — er meldet dann eine Regel als
     * erfüllt, weil sie in der Methode **daneben** steht.
     */
    private function body(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');

        if ($start === false) {
            return '';
        }

        $auf = strpos($source, '{', $start);

        if ($auf === false) {
            return '';
        }

        $tiefe = 0;

        for ($i = $auf; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $tiefe++;
            } elseif ($source[$i] === '}') {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($source, $auf, $i - $auf + 1);
                }
            }
        }

        return '';
    }

    public function test_the_listed_handlers_all_exist(): void
    {
        $source = $this->controller();

        foreach (self::HANDLERS as $griff) {
            $this->assertNotSame(
                '',
                $this->body($source, $griff),
                sprintf(
                    'BulkActionTest nennt den Griff `%s`, und FileController hat ihn nicht. '.
                    'Ein Eintrag, den die Suche nie erreicht, sieht aus wie eine Abdeckung und ist eine Lücke.',
                    $griff,
                ),
            );
        }
    }

    /**
     * Jeder Griff liest die Auswahl an derselben Stelle.
     *
     * Die Obergrenze, das `min:1` und das `array_values` stehen in
     * `selection()`. Viermal abgeschrieben wäre es viermal die Gelegenheit,
     * eines davon zu vergessen — und die Absage käme dann aus der Tiefe des
     * Agenten statt als Feldmeldung.
     */
    public function test_every_handler_reads_the_selection_in_one_place(): void
    {
        $source = $this->controller();

        foreach (self::HANDLERS as $griff) {
            $rumpf = $this->body($source, $griff);

            $this->assertStringContainsString(
                '$this->selection($request',
                $rumpf,
                sprintf(
                    "`%s` liest die Auswahl selbst statt über `selection()`.\n\n".
                    'Dann gilt für diesen einen Griff eine andere Obergrenze als für die drei '.
                    'anderen — und niemand merkt es, weil beide Fassungen funktionieren.',
                    $griff,
                ),
            );

            $this->assertStringNotContainsString(
                "'paths' => ['required'",
                $rumpf,
                sprintf('`%s` schreibt die Regeln für `paths` ein zweites Mal auf.', $griff),
            );
        }
    }

    /**
     * Wer je Eintrag arbeitet, meldet je Eintrag.
     *
     * `each()` sammelt, `report()` sagt es. Eines ohne das andere ist genau der
     * Vorgang, der die Hälfte schafft und Erfolg meldet.
     */
    public function test_every_looping_handler_reports_what_failed(): void
    {
        $source = $this->controller();

        foreach (self::LOOPING as $griff) {
            $rumpf = $this->body($source, $griff);

            $this->assertStringContainsString(
                '$this->each(',
                $rumpf,
                sprintf('`%s` fasst mehrere Einträge an, ohne sie einzeln zu nehmen.', $griff),
            );

            $this->assertStringContainsString(
                '$this->report(',
                $rumpf,
                sprintf(
                    "`%s` sammelt Fehlschläge und meldet sie nicht.\n\n".
                    'Der Kunde bekommt dann eine Erfolgsmeldung über einem Verzeichnis, in dem '.
                    'neunzehn statt zwanzig Einträge liegen.',
                    $griff,
                ),
            );
        }
    }

    /**
     * Die Zahl steht vor den Gründen.
     *
     * **Ohne sie liest der Kunde drei Fehlermeldungen und weiss nicht, ob die
     * anderen siebzehn durchgekommen sind.** Geprüft wird die Reihenfolge im
     * Quelltext: Die Zeile mit der Zahl entsteht, bevor die Schleife über die
     * Gründe läuft.
     */
    public function test_the_tally_stands_before_the_reasons(): void
    {
        $rumpf = $this->body($this->controller(), 'report');

        $zahl = strpos($rumpf, 'Von %d Einträgen');
        $gruende = strpos($rumpf, 'foreach ($result[\'failed\']');

        $this->assertIsInt($zahl, 'Die Rückmeldung nennt die Zahl der Einträge nicht mehr.');
        $this->assertIsInt($gruende, 'Die Rückmeldung zählt die Gründe nicht mehr auf.');

        $this->assertLessThan(
            $gruende,
            $zahl,
            "Die Gründe stehen vor der Zahl.\n\n".
            'Wer drei Meldungen liest und die Gesamtzahl erst darunter findet, hat die Frage '.
            '„sind die anderen durch?" schon dreimal falsch beantwortet.',
        );
    }

    /**
     * Bei genau einem Eintrag steht sein Grund allein da.
     *
     * „Von 1 Einträgen ist 0 entfernt." ist die Zahl ohne die Auskunft — und die
     * Auskunft ist bei einem Eintrag alles, was es zu sagen gibt. Ausserdem wäre
     * es die Mehrzahl hinter einer Eins, und dafür gibt es
     * {@see CountedNounTest}.
     */
    public function test_a_single_entry_gets_its_reason_without_a_tally(): void
    {
        $rumpf = $this->body($this->controller(), 'report');

        $this->assertMatchesRegularExpression(
            '/if \(count\(\$paths\) === 1\)/',
            $rumpf,
            'Die Rückmeldung unterscheidet den einzelnen Eintrag nicht mehr von der Auswahl.',
        );
    }

    /**
     * Das Ziel einer Auswahl ist ein Verzeichnis und kein Pfad.
     *
     * **Der Fehler, den der Mehrfach-Upload schon einmal gemacht hat.** Ein
     * vollständiger Zielpfad für mehrere Quellen ist **ein** Pfad für alle: Der
     * letzte Eintrag gewinnt, die anderen sind fort, und der Vorgang meldet
     * Erfolg.
     */
    public function test_the_target_of_a_batch_is_a_directory(): void
    {
        $source = $this->controller();

        foreach (['move', 'copy'] as $griff) {
            $rumpf = $this->body($source, $griff);

            $this->assertStringContainsString(
                '$this->into($ziel, $path)',
                $rumpf,
                sprintf(
                    "`%s` reicht das Ziel weiter, wie es hereinkam.\n\n".
                    'Bei mehreren Quellen ist das ein Pfad für alle — neunzehn Einträge werden '.
                    'überschrieben, und der Vorgang meldet Erfolg.',
                    $griff,
                ),
            );

            $this->assertStringNotContainsString(
                sprintf('$this->files->%s($subscription, $path, $ziel)', $griff),
                $rumpf,
                sprintf('`%s` benutzt das Zielverzeichnis als vollständigen Pfad.', $griff),
            );
        }

        $this->assertStringContainsString(
            "rtrim(\$directory, '/').'/'.basename(\$path)",
            $this->body($source, 'into'),
            'Der Name am Ziel kommt nicht mehr von der Quelle.',
        );
    }

    /**
     * Und umbenennen ist etwas anderes als verschieben.
     *
     * Solange beide dasselbe Feld `to` benutzten, musste der Aufrufer wissen,
     * welche der beiden Bedeutungen gerade gilt — und die Seite hat den Zielpfad
     * dafür selbst zusammengesetzt.
     *
     * > **Ein Feld mit zwei Bedeutungen hat keine.**
     */
    public function test_renaming_asks_for_a_name_and_not_for_a_path(): void
    {
        $rumpf = $this->body($this->controller(), 'rename');

        $this->assertNotSame('', $rumpf, 'Den Griff `rename` gibt es nicht mehr.');

        $this->assertStringContainsString(
            "'name' => ['required'",
            $rumpf,
            'Umbenennen fragt nicht mehr nach einem Namen.',
        );

        $this->assertStringContainsString(
            'basename(',
            $rumpf,
            'Der neue Name darf keinen Verzeichnisteil mitbringen — das entscheidet `basename()`.',
        );

        $seite = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Files/Index.vue',
        );

        $this->assertStringNotContainsString(
            'rename.to = here(',
            $seite,
            'Die Seite setzt den Zielpfad des Umbenennens wieder selbst zusammen.',
        );
    }

    /**
     * Und jede Zeile der Rückmeldung erreicht auch den Browser.
     *
     * ## Der Weg dazwischen war lossy, und drei Wächter sahen es nicht
     *
     * `report()` baut die Zahl und je Fehlschlag eine Zeile. Inertias
     * Laravel-Anbindung bildet den Fehlerbeutel aber auf **„Feld => erste
     * Meldung"** ab — alles nach der Zahl fiel weg, bevor die Seite es sah.
     *
     * Gemessen auf `cloudsrv24` (`docs/55`, Befund 12): „Von 3 Einträgen sind 2
     * entfernt." und kein einziger Grund. Bei sechs geschützten Verzeichnissen
     * dasselbe.
     *
     * **Es traf auch den Mehrfach-Upload aus Schritt 5e**, seit dem ersten Tag.
     * `FileCreationTest::test_a_partly_failed_upload_does_not_report_success`
     * ist grün und liest den Quelltext des Controllers.
     *
     * > **Eine Meldung, die der Controller schreibt, ist damit noch keine, die
     * > jemand liest.**
     *
     * Geprüft werden deshalb **beide** Enden des Weges: dass die Middleware
     * jede Meldung mitnimmt, und dass die Zusammenfassung sie wieder in Zeilen
     * zerlegt. Eines ohne das andere ist eine halbe Kette.
     */
    public function test_every_reason_survives_the_way_to_the_browser(): void
    {
        $mittelschicht = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Middleware/HandleInertiaRequests.php',
        );

        $this->assertStringContainsString(
            'public function resolveValidationErrors(',
            $mittelschicht,
            "`HandleInertiaRequests` überschreibt die Fehlerauflösung nicht mehr.\n\n".
            'Inertias Voreinstellung nimmt je Feld nur die **erste** Meldung mit — die Gründe '.
            'je Eintrag fallen dann weg, bevor die Seite sie sieht.',
        );

        $this->assertStringContainsString(
            'implode("\n", $meldungen)',
            $mittelschicht,
            'Die Meldungen eines Feldes werden nicht mehr zusammengeführt.',
        );

        $zusammenfassung = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Components/FormErrors.vue',
        );

        $this->assertStringContainsString(
            "message.split('\\n')",
            $zusammenfassung,
            "`FormErrors` zerlegt die verbundenen Meldungen nicht mehr in Zeilen.\n\n".
            'Dann steht die ganze Rückmeldung als ein Satz da, und der Zeilenumbruch, den die '.
            'Mittelschicht setzt, ist unsichtbar.',
        );
    }

    /**
     * Die Auswahl fällt weg, sobald man das Verzeichnis wechselt.
     *
     * **Sonst entfernt sie Einträge, die niemand mehr sieht.** Eine Auswahl, die
     * eine Navigation überlebt, ist eine Liste von Pfaden aus einem anderen
     * Verzeichnis — und die Leiste darüber sagt „3 Einträge ausgewählt", während
     * die Tabelle darunter keinen einzigen Haken zeigt.
     */
    public function test_the_selection_falls_away_when_the_directory_changes(): void
    {
        $seite = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Files/Index.vue',
        );

        $this->assertMatchesRegularExpression(
            '/watch\(\(\) => props\.path, \(\) => \{[^}]*selected\.value = \[\]/s',
            $seite,
            "Die Auswahl überlebt den Wechsel des Verzeichnisses.\n\n".
            'Dann steht über der Tabelle eine Zahl, zu der kein einziger Haken gehört — und der '.
            'nächste Klick auf „Entfernen" trifft Einträge aus einem anderen Ordner.',
        );
    }

    /**
     * Jeder Knopf der Auswahlleiste ruft etwas, das es gibt.
     *
     * Derselbe Schnitt wie bei `AgentOperationReachTest` und `LinkReachTest`:
     * eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ oder ein Test
     * den Bezug prüft. `vue-tsc` fängt einen fehlenden Namen; was es **nicht**
     * fängt, ist eine Adresse, die auf keine Route mehr zeigt.
     */
    public function test_every_action_of_the_selection_reaches_a_route(): void
    {
        $seite = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Files/Index.vue',
        );

        $routen = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        preg_match_all('~/files/([a-z]+)`~', $seite, $treffer);

        $gesehen = array_unique($treffer[1]);

        $this->assertGreaterThanOrEqual(
            5,
            count($gesehen),
            sprintf(
                'Es werden nur %d Adressen gefunden (%s). Dann sucht dieser Ausdruck an der '.
                'falschen Stelle und seine grüne Antwort bedeutet nichts.',
                count($gesehen),
                implode(', ', $gesehen) ?: '(keine)',
            ),
        );

        /*
         * **Die beiden Griffe der Auswahl stehen absichtlich daneben.**
         *
         * Kopieren und Verschieben setzt die Seite zur Laufzeit zusammen
         * (`/files/${aktion}`), und der Ausdruck oben kann eine Adresse, die es
         * im Quelltext gar nicht gibt, nicht finden. Ohne diese zwei Zeilen wäre
         * ausgerechnet der neue Teil der einzige ungeprüfte.
         */
        foreach (array_merge($gesehen, ['copy', 'move']) as $pfad) {
            $this->assertStringContainsString(
                "/files/{$pfad}'",
                $routen,
                sprintf(
                    "Der Dateimanager ruft `/files/%s` auf, und `routes/web.php` kennt die Adresse nicht.\n\n".
                    'Der Kunde bekommt dort eine 404, und nichts im Bau meldet es.',
                    $pfad,
                ),
            );
        }
    }
}
