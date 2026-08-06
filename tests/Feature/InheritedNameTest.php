<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\InheritedNames;

/**
 * Ein Name, der schon der Basisklasse gehört — der Wächter zum vierten Mal.
 *
 * **Es ist derselbe Fehler wie überall in diesem Projekt, nur an einer Stelle,
 * an der er besonders weh tut: Er bricht beim *Laden* der Klasse.** `php -l`
 * sieht nichts davon, der Testlauf meldet keinen fehlgeschlagenen Fall, sondern
 * stirbt mit `PHP Fatal error: Cannot override final method` — und wer das
 * liest, sucht zuerst an der falschen Stelle.
 *
 * Die drei Male:
 *
 *   1. `count()` in einem PHPUnit-Testfall. Dort ist die Methode `final`.
 *   2. `configure()` in einem Artisan-Kommando. Dort ist sie `protected`, hier
 *      war sie `private` — und damit stand nicht ein Kommando still, sondern
 *      `artisan` mit allen.
 *   3. `name()` in `DnsPacketTest`, eine Hilfsmethode für das Drahtformat. Auch
 *      hier `final`, auch hier ein fataler Fehler statt einer Meldung.
 *
 * Nach dem zweiten Mal stand die Regel in CLAUDE.md: „Wer in einer abgeleiteten
 * Klasse eine private Hilfsmethode einzieht, sieht vorher in der Basisklasse
 * nach." Beim dritten Mal habe ich sie gelesen und bin trotzdem hineingelaufen.
 * **Ein Satz in einer Datei ist kein Wächter.**
 *
 * Wie hier gesucht wird, ohne dabei selbst abzustürzen, steht in
 * {@see InheritedNames}.
 */
final class InheritedNameTest extends TestCase
{
    /**
     * Wo gesucht wird.
     *
     * `tests/` steht mit drin, und zwar zuerst: Zwei der drei Fälle waren
     * Testfälle. Ein Wächter, der nur den Anwendungscode ansieht, hätte den
     * dritten nicht gefunden.
     */
    private const DIRECTORIES = ['tests', 'app', 'agent/src'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_no_class_redeclares_a_name_of_its_base_class(): void
    {
        $found = InheritedNames::conflicts($this->root(), self::DIRECTORIES);

        $this->assertSame([], $found, sprintf(
            "Diese Klassen erklären einen Namen, der der Basisklasse gehört:\n  %s\n\n".
            "Beides bricht beim **Laden** der Klasse und nicht beim Ausführen — als fataler Fehler\n".
            "ohne Zusammenhang, nicht als fehlgeschlagener Durchgang. Eine überschriebene `final`-\n".
            "Methode geht gar nicht; eine Sichtbarkeit lässt sich erweitern und nicht verengen.\n".
            'Die Hilfsmethode bekommt einen anderen Namen — das ist die ganze Behebung.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Und der Wächter sieht überhaupt etwas.
     *
     * **Die Falle, in die dieses Vorgehen dreimal gelaufen ist.** Lässt sich
     * keine Basisklasse mehr auflösen — ein umbenannter Namensraum, ein Fehler
     * beim Auflösen —, findet dieser Wächter nichts mehr und meldet Grün. Er
     * zählt deshalb mit, wie viele Dateien er wirklich prüfen konnte.
     */
    public function test_the_guard_actually_reaches_the_base_classes(): void
    {
        $checked = 0;

        foreach (InheritedNames::files($this->root(), self::DIRECTORIES) as $path) {
            $checked += InheritedNames::reachesItsBase($path) ? 1 : 0;
        }

        $this->assertGreaterThan(100, $checked, 'Es lässt sich kaum eine Basisklasse auflösen — dann prüft dieser Wächter nichts.');
    }
}
