<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Anlegen und Hochladen — und die Rückmeldung, wenn nur die Hälfte ankommt.
 *
 * ## Zwei Anlässe, und beide sind Fragen des Betreibers
 *
 * **Der erste ist die Fortsetzung von Befund 6.** Eine Datei anzulegen war seit
 * P6 Schritt 3 vollständig gebaut: `files.write` legt an, was es nicht gibt,
 * der Agent meldet es in seiner Antwort (`created`), die Route steht mit ihrem
 * `can:`, und der Controller verlangt nirgends, dass die Datei existiert. In
 * der Zwischenabnahme ist `p6-probe.txt` genau so entstanden — aus `tinker`.
 *
 * **Es fehlte nur der Knopf.** Dieselbe Form wie beim Dateimanager selbst, eine
 * Ebene tiefer: Die Fähigkeit ist da, erreichbar ist sie nicht. `LinkReachTest`
 * konnte das nicht sehen — er prüft Seiten, nicht Fähigkeiten.
 *
 * > **Eine Fähigkeit, die keine Fläche hat, ist gebaut und nicht
 * > ausgeliefert.**
 *
 * **Der zweite ist das Hochladen mehrerer Dateien.** Die Schleife ist die
 * kleinere Hälfte; die grössere ist der Fall, der hier der Normalfall ist —
 * Datei 7 von 20 reisst die Quota, und die anderen neunzehn liegen schon da.
 */
final class FileCreationTest extends TestCase
{
    private function listing(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Files/Index.vue',
        );
    }

    private function controller(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/FileController.php',
        );
    }

    /**
     * Es gibt einen Weg, eine Datei anzulegen.
     *
     * Gefragt wird nach dem Knopf **und** nach dem Formular dahinter. Nur nach
     * dem Knopf zu fragen liesse einen zu, der nichts öffnet — und das ist
     * genau die Sorte Fläche, die aussieht wie eine Fähigkeit.
     */
    public function test_a_file_can_be_created_from_the_listing(): void
    {
        $quelle = $this->listing();

        $this->assertStringContainsString(
            'Datei anlegen',
            $quelle,
            'Der Dateimanager bietet nicht an, eine Datei anzulegen. `files.write` kann es seit '.
            'P6 Schritt 3 — es fehlt die Fläche.',
        );

        $this->assertStringContainsString(
            'function submitFile(',
            $quelle,
            'Es gibt einen Knopf, aber kein Formular dahinter.',
        );
    }

    /**
     * Angelegt oder gespeichert entscheidet der Zustand und nicht ein Feld.
     *
     * **Dieselbe Route bedient beides**, und was danach passiert, hängt an der
     * Antwort des Agenten (`created`). Ein Feld im Formular, das mitteilt, was
     * gemeint war, wäre der Fehler aus P4: eine Bedingung an einer **Absicht**
     * statt an einem **Zustand** — und beim nächsten Aufrufer stimmt sie nicht
     * mehr.
     */
    public function test_creating_and_saving_are_told_apart_by_the_answer(): void
    {
        $quelle = $this->controller();

        $this->assertStringContainsString(
            "\$created = (\$result['created'] ?? false) === true;",
            $quelle,
            'Der Controller liest nicht mehr aus der Antwort des Agenten, ob die Datei neu '.
            'entstanden ist.',
        );

        $this->assertStringContainsString(
            'Die Datei ist angelegt.',
            $quelle,
            'Eine neu angelegte Datei wird wie eine gespeicherte gemeldet. Das ist dieselbe '.
            'Meldung für zwei verschiedene Vorgänge.',
        );
    }

    /**
     * Eine **leere** Datei darf entstehen.
     *
     * ## Der Griff war gebaut und hat nie funktioniert
     *
     * Gefunden im Browser auf `cloudsrv24`, beim allerersten Anlegen einer
     * Datei auf einem echten Server (`docs/55`, Befund 6). Das Formular fragt
     * nach einem Namen, schickt `content: ''` — und der Kunde liest:
     *
     *     Das Formular wurde nicht gespeichert.
     *     The content field must be a string.
     *
     * **Laravels globaler Stapel enthält `ConvertEmptyStringsToNull`.** Aus
     * `''` wird `null`, **bevor** die Prüfung läuft. `present` ist damit
     * erfüllt — der Schlüssel ist ja da —, `string` nicht.
     *
     * > **Eine Regel, die den leeren Wert verbietet, verbietet genau den Fall,
     * > für den der Griff gebaut ist.**
     *
     * Es traf beide Wege: das Anlegen aus der Liste **und** das Speichern einer
     * Datei, deren Inhalt jemand im Editor gelöscht hat.
     *
     * ## Warum kein Test das gefunden hat
     *
     * Die drei Wächter daneben lesen den Quelltext und prüfen die **Form** —
     * dass der Knopf da ist, dass der Controller die Antwort des Agenten liest,
     * dass jede Datei ihren Namen behält. Keiner davon schickt eine Anfrage,
     * und ohne Anfrage läuft keine Middleware.
     *
     * > **Ein Wächter, der Quelltext liest, sieht nichts, was erst zwischen
     * > Browser und Controller passiert.**
     *
     * Dieser hier liest deshalb die **Regel** und nicht das Verhalten: Er kann
     * nicht belegen, dass es geht — aber er schlägt zu, wenn `nullable`
     * wegfällt, und genau das war der Fehler.
     */
    public function test_an_empty_file_may_be_created(): void
    {
        $quelle = $this->controller();

        $rumpf = $this->body($quelle, 'write');

        $this->assertNotSame('', $rumpf, 'Den Griff `write` gibt es nicht mehr.');

        $this->assertMatchesRegularExpression(
            "/'content' => \\['present', 'nullable', 'string'\\]/",
            $rumpf,
            "`content` verbietet wieder den leeren Wert.\n\n".
            "Laravels `ConvertEmptyStringsToNull` macht aus `''` ein `null`, bevor die Prüfung \n".
            "läuft. Ohne `nullable` weist `string` es ab, und „Datei anlegen\" scheitert an \n".
            'genau dem Fall, für den es gebaut ist.',
        );

        $this->assertStringContainsString(
            "\$data['content'] ?? ''",
            $rumpf,
            'Der `null`-Fall kommt ungefiltert am Agenten an — dort ist er ein Typfehler und '.
            'keine leere Datei.',
        );
    }

    /**
     * Der Rumpf einer Methode, über Klammernzählung.
     *
     * Dieselbe Begründung wie in {@see BulkActionTest}: Ein Ausdruck bis zur
     * nächsten Signatur nimmt die Dokumentationsblöcke dazwischen mit und meldet
     * dann eine Regel als erfüllt, weil sie in der Methode **daneben** steht.
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

    /**
     * Mehrere Dateien dürfen kommen — und jede bekommt ihren eigenen Namen.
     *
     * **Der Fehler, der beinahe passiert wäre:** Die Seite schickte bis dahin
     * den vollständigen Zielpfad mitsamt Dateinamen. Bei mehreren Dateien wäre
     * das **ein** Pfad für alle gewesen: zwanzig Dateien unter demselben Namen,
     * neunzehnmal überschrieben — und der Vorgang hätte Erfolg gemeldet.
     *
     * > **Ein Vorgang, der zwanzigmal dieselbe Datei schreibt, meldet zwanzig
     * > Erfolge.**
     */
    public function test_every_uploaded_file_keeps_its_own_name(): void
    {
        $this->assertStringContainsString(
            'multiple',
            $this->listing(),
            'Das Feld nimmt nur eine Datei entgegen.',
        );

        $quelle = $this->controller();

        $this->assertStringContainsString(
            "'files.*' => ['required', 'file'],",
            $quelle,
            'Der Controller nimmt keine Liste von Dateien entgegen.',
        );

        $this->assertStringContainsString(
            '$target = rtrim($data[\'path\'], \'/\').\'/\'.$leaf;',
            $quelle,
            'Der Zielpfad wird nicht je Datei zusammengesetzt. Dann bekommen alle denselben.',
        );

        $this->assertStringNotContainsString(
            'here(chosen.name)',
            $this->listing(),
            'Die Seite setzt den Zielpfad weiter aus einem einzelnen Dateinamen zusammen.',
        );
    }

    /**
     * Ein halb gelungener Upload meldet keinen Erfolg.
     *
     * **Das ist der eigentliche Gegenstand dieses Schritts.** Neunzehn von
     * zwanzig Dateien im Verzeichnis und darüber „Die Dateien sind
     * hochgeladen" ist genau der Fehler, den `docs/48` an anderer Stelle
     * gefunden hat:
     *
     * > **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen,
     * > als wäre sie durchgelaufen.**
     *
     * Geprüft wird dreierlei: dass je Datei aufgefangen wird, dass die Zahl der
     * gelungenen im ersten Satz steht, und dass die Schleife bei einem Fehler
     * **weiterläuft**. Ein Abbruch beim ersten wäre die kürzere Fassung und die
     * schlechtere — der Kunde wüsste dann nicht, ob die restlichen an derselben
     * Sache scheitern oder nie versucht wurden.
     */
    public function test_a_partly_failed_upload_does_not_report_success(): void
    {
        $quelle = $this->controller();

        $this->assertStringContainsString(
            'catch (AgentException $exception) {
                $failed[$name] = $exception->getMessage();',
            $quelle,
            'Ein Fehler an einer Datei wird nicht je Datei aufgefangen. Dann bricht der ganze '.
            'Vorgang beim ersten ab, und was schon dalag, bleibt unerwähnt.',
        );

        $this->assertStringContainsString(
            "'Von %d Dateien %s %d hochgeladen.'",
            $quelle,
            'Die Zahl der gelungenen Dateien steht nicht in der Meldung. Ohne sie liest der '.
            'Kunde drei Fehler und weiss nicht, ob die anderen siebzehn durchkamen.',
        );

        /*
         * **Die Reihenfolge ist die Aussage.** Erst wird gezählt, dann wird
         * geworfen — andersherum stünde die Zahl auf dem Stand vor der
         * Schleife.
         */
        $this->assertLessThan(
            strpos($quelle, 'ValidationException::withMessages([\'files\' => $messages])'),
            (int) strpos($quelle, '$done++;'),
            'Es wird geworfen, bevor gezählt wurde.',
        );
    }
}
