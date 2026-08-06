<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wer ein Formular abschickt, erfährt auch, wenn es nicht ankam.
 *
 * **Dieser Wächter kommt aus einem halben Tag Fehlersuche.** Ein Kunde liess
 * sich nicht anlegen: Klick auf „Anlegen", und scheinbar passierte nichts —
 * kein Eintrag im Prüfprotokoll, keine Zeile im Fehlerprotokoll, kein Kunde.
 * Der Grund war eine Anmeldeadresse, die noch belegt war, und die Meldung dazu
 * stand die ganze Zeit da: als kleine rote Zeile unter dem Feld, mitten in
 * einem langen Formular. Inertia setzt die Scrollposition nach einer Antwort
 * zurück; oben angekommen sah der Betreiber dieselben ausgefüllten Felder wie
 * vorher und schloss auf einen kaputten Knopf.
 *
 * **Eine Meldung, die man suchen muss, ist keine.** Seitdem trägt jede Seite,
 * die ein Formular abschickt, {@see self::COMPONENT} ganz oben — dort, wo der
 * Blick nach dem Sprung landet.
 *
 * **Warum das ein Wächter ist und keine Notiz:** Es waren dreizehn Seiten, und
 * bei zwölf davon wäre derselbe Fehlschlag genauso unsichtbar gewesen. Die
 * nächste Seite kommt bestimmt.
 *
 * **Und ohne Ausnahmeliste.** Hier stand eine, leer und mit dem Hinweis, dass
 * ein Eintrag begründet sein will — PHPStan hat sie als das gemeldet, was sie
 * war: ein Vergleich gegen ein leeres Feld, der nie zutrifft. Wer eine Ausnahme
 * braucht, baut die Liste samt Begründung; eine Vorratsliste ist genau die
 * Sorte Zeichenkette, gegen die dieses Projekt seine Wächter stellt.
 */
final class FormErrorTest extends TestCase
{
    /** Die Zusammenfassung, die oben stehen muss. */
    public const COMPONENT = 'FormErrors';

    /** @return list<string> */
    private function pages(): array
    {
        $pages = [];
        $root = dirname(__DIR__, 2).'/resources/js/Pages';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $pages[] = $file->getPathname();
            }
        }

        sort($pages);

        $this->assertGreaterThan(15, count($pages), 'Es werden kaum Seiten gelesen — dann prüft dieser Test nichts.');

        return $pages;
    }

    /**
     * Die Komponenten, die selbst ein Formular abschicken.
     *
     * **Diese Liste kam mit Schritt 6b dazu, und zwar aus einer Lücke, die
     * dieser Wächter selbst hatte.** `DnsCredentials.vue` trägt ein Formular
     * mit `useForm`, steht aber unter `Components/` — eine Seite, die es
     * einbindet, enthielt das Wort `useForm` nirgends und galt damit als
     * formularlos. Genau so wäre die Abonnementseite ohne Zusammenfassung
     * durchgegangen: ein Wächter, der grün meldet, weil die Regel umgezogen ist.
     *
     * Gesucht wird deshalb nicht nach dem Wort, sondern nach der Sache — und
     * die Sache ist „diese Seite kann eine Abweisung kassieren".
     *
     * @return list<string> Die Namen, wie sie im Markup stehen
     */
    private function formComponents(): array
    {
        $names = [];
        $root = dirname(__DIR__, 2).'/resources/js/Components';

        foreach (glob($root.'/*.vue') ?: [] as $path) {
            $name = basename($path, '.vue');

            if ($name === self::COMPONENT) {
                continue;
            }

            if (str_contains((string) file_get_contents($path), 'useForm')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    public function test_every_page_with_a_form_shows_what_went_wrong(): void
    {
        $found = [];
        $checked = 0;
        $components = $this->formComponents();

        foreach ($this->pages() as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);

            $embedded = false;

            foreach ($components as $component) {
                if (str_contains($source, '<'.$component)) {
                    $embedded = true;

                    break;
                }
            }

            if (! str_contains($source, 'useForm') && ! $embedded) {
                continue;
            }

            $checked++;

            if (! str_contains($source, '<'.self::COMPONENT)) {
                $found[] = $relative;
            }
        }

        // **Die Untergrenze zählt mit.** Findet dieser Wächter keine Formulare
        // mehr — umbenanntes Verzeichnis, andere Schreibweise —, meldet er
        // Grün für eine Regel, die er nicht mehr prüft. Dreimal passiert; die
        // Lehre steht in CLAUDE.md.
        $this->assertGreaterThan(8, $checked, 'Es werden kaum Formulare gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Seiten schicken ein Formular ab, ohne zu zeigen, wenn es abgewiesen wird:\n  %s\n\n".
            "`<%s />` gehört über das erste Formular der Seite. Ohne sie steht die einzige Meldung\n".
            "als rote Zeile am betroffenen Feld — und nach einer Antwort springt die Seite nach oben,\n".
            'wo dann nichts steht. Genau so ist ein Fehlschlag einen halben Tag lang als „der Knopf tut '.
            'nichts" gelesen worden.',
            implode("\n  ", $found),
            self::COMPONENT,
        ));
    }

    /**
     * Und die Zusammenfassung liest den Fehlersatz der **Seite**.
     *
     * `useForm().errors` füllt sich nur bei der Anfrage dieser einen Instanz.
     * Die Domainseite hat drei Formulare; gebunden an eines davon bliebe die
     * Zusammenfassung bei zweien stumm — und das ist genau der Fall, für den
     * es sie gibt.
     */
    public function test_the_summary_reads_the_errors_of_the_page(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Components/'.self::COMPONENT.'.vue',
        );

        $this->assertStringContainsString('usePage', $source);
        $this->assertStringContainsString('props.errors', $source);
        $this->assertStringNotContainsString('defineProps', $source);
    }
}
