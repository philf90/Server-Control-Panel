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
