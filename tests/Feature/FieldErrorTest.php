<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Ein Fehler steht einmal auf der Seite — als Satz oben, als Markierung am Feld.
 *
 * ## Der Anlass
 *
 * Bis August 2026 stand jede Meldung **zweimal** da: in der Zusammenfassung und
 * wörtlich noch einmal unter dem Feld (`docs/45 §5`). Zwei gleiche Sätze
 * übereinander liest niemand als „Übersicht und Ort", sondern als Versehen.
 *
 * Den Satz trägt jetzt allein die Zusammenfassung aus `FormErrors.vue`; das Feld sagt über `aria-invalid` nur noch *welches*. Das
 * ist genauer als vorher: Die Markierung sitzt am Bedienelement statt als Zeile
 * darunter.
 *
 * ## Was hier wirklich schiefgehen kann
 *
 * Nicht der doppelte Satz — der war nur unschön. Gefährlich ist die
 * Gegenrichtung: **Ein Feld, das markiert werden kann, auf einer Seite ohne
 * Zusammenfassung.** Dann ist die Meldung nicht doppelt, sondern *fort*: Der
 * Rand wird rot, und kein Wort sagt warum. Genau diese Lage hat der Umbau
 * möglich gemacht, und genau die prüft dieser Test.
 *
 * > **Wer die Auskunft an einen Ort verlegt, muss prüfen, dass es den Ort auf
 * > jeder Seite gibt.**
 *
 * Eine Ausnahme bleibt und ist keine: die Meldung „Die beiden Eingaben sind
 * nicht gleich." aus `PasswordFields.vue`. Sie entsteht
 * beim Tippen und geht nie über den Draht — die Zusammenfassung liest die
 * Antwort der letzten Anfrage und kann sie deshalb gar nicht kennen.
 */
final class FieldErrorTest extends TestCase
{
    /**
     * Die eine Meldung, die am Feld bleiben darf.
     *
     * Sie steht hier als Text und nicht als Dateiname: Zöge sie um, soll dieser
     * Test sie weiter erkennen. Ein Pfad wäre der Bezug, der beim Verschieben
     * still bricht — die Sorte Fehler, für die es dieses Repo voller Wächter
     * gibt.
     */
    private const LIVE = 'Die beiden Eingaben sind nicht gleich.';

    /**
     * Kein Template trägt einen Serverfehler als Satz am Feld.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): die Zeile
     * `<p v-if="form.errors.contact" class="error">…</p>` in `Settings/Tls.vue`
     * wieder einsetzen.
     */
    public function test_no_template_repeats_a_server_error_at_the_field(): void
    {
        $found = [];

        foreach ($this->vueFiles() as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                if (! str_contains($line, 'class="error"')) {
                    continue;
                }

                if (str_contains($line, self::LIVE)) {
                    continue;
                }

                /*
                 * Die Zeile der Live-Prüfung trägt ihren Satz erst in der
                 * nächsten Zeile — erkannt wird sie deshalb an ihrer Bedingung.
                 */
                if (str_contains($line, '!matches')) {
                    continue;
                }

                $found[] = sprintf('%s:%d  %s', $this->relative($path), $number + 1, trim($line));
            }
        }

        /*
         * **Die Untergrenze zählt Dateien und nicht Fehlerzeilen.** Es *darf*
         * am Ende keine einzige geben — die Regel wäre dann erfüllt, und ein
         * Zähler auf den Zeilen meldete Rot für genau die Ordnung, die er
         * durchsetzt. Dieselbe Falle, in die dieses Vorgehen schon dreimal
         * gelaufen ist.
         */
        $this->assertGreaterThan(20, count($this->vueFiles()), 'Es werden kaum Templates gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, implode("\n  ", array_merge(
            ['Ein Fehler steht wieder als Satz am Feld — und derselbe Satz steht oben in der '
                .'Zusammenfassung. Zweimal dasselbe liest niemand als Übersicht und Ort:'],
            $found,
        )));
    }

    /**
     * Und wo ein Feld markiert werden kann, steht auch die Zusammenfassung.
     *
     * **Das ist die Richtung mit Folgen.** Ohne sie wäre der Umbau ein Tausch
     * von „doppelt" gegen „gar nicht": roter Rand, kein Wort dazu. Geprüft wird
     * je Seite, und Komponenten zählen mit — `PasswordFields` bringt die
     * Markierung mit, die Zusammenfassung steht auf der Seite, die sie einbaut.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): `<FormErrors />` aus
     * `Pages/Settings/Tls.vue` entfernen.
     */
    public function test_every_page_that_can_mark_a_field_shows_the_summary(): void
    {
        $markiert = [];
        $inhalt = [];

        foreach ($this->vueFiles() as $path) {
            $inhalt[$path] = (string) file_get_contents($path);

            if (str_contains($inhalt[$path], ':aria-invalid=')) {
                $markiert[] = $path;
            }
        }

        $this->assertGreaterThan(
            10,
            count($markiert),
            'Kaum eine Datei markiert noch ein Feld — dann ist der Ausdruck ins Leere gelaufen.',
        );

        $found = [];

        foreach ($inhalt as $path => $source) {
            if (! str_contains($path, '/Pages/')) {
                continue;
            }

            if (! $this->marks($path, $inhalt)) {
                continue;
            }

            if (str_contains($source, '<FormErrors')) {
                continue;
            }

            $found[] = $this->relative($path);
        }

        $this->assertSame([], $found, implode("\n  ", array_merge(
            ['Diese Seite kann ein Feld rot machen und trägt keine Zusammenfassung. Der Rand wird '
                .'rot, und kein Wort sagt warum:'],
            $found,
        )));
    }

    /**
     * Erfolg wird nie am Feld gemeldet.
     *
     * **Der Grund ist nicht Symmetrie, sondern die Aufgabe der Markierung**
     * (`docs/19 §6.3`): Sie zeigt, wo noch etwas zu tun ist. Erfolg hat keinen
     * Ort, an dem man weiterarbeitet — ein grüner Rand an vierzehn Feldern
     * eines gespeicherten Formulars sagt vierzehnmal dasselbe und weist auf
     * nichts hin. Und er entwertet das eine rote Feld: Sind Felder ständig
     * eingefärbt, fällt die Beanstandung nicht mehr auf.
     *
     * Geprüft wird beides — dass es die eine grüne Meldung **gibt** und dass
     * sie nicht in einem Formular steht. Die Untergrenze ist hier nötig, weil
     * „nirgends eine grüne Meldung" sonst als Erfolg durchginge.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): eine `notice ok` in ein
     * Formular auf `Pages/Settings/Tls.vue` setzen.
     */
    public function test_success_is_never_reported_at_a_field(): void
    {
        $stellen = [];

        foreach ($this->vueFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'notice ok')) {
                continue;
            }

            $stellen[] = $this->relative($path);
        }

        $this->assertNotSame(
            [],
            $stellen,
            'Es gibt keine grüne Meldung mehr — dann prüft dieser Test nichts, und der Erfolg '
            .'eines Vorgangs steht nirgends.',
        );

        $found = array_values(array_filter(
            $stellen,
            static fn (string $pfad): bool => ! str_contains($pfad, '/Layouts/'),
        ));

        $this->assertSame([], $found, implode("\n  ", array_merge(
            ['Eine grüne Meldung steht ausserhalb des Layouts. Erfolg ist eine Aussage über den '
                .'Vorgang und nicht über ein Feld (docs/19 §6.3):'],
            $found,
        )));
    }

    /**
     * Markiert diese Seite ein Feld — selbst oder über eine Komponente?
     *
     * Eine Ebene tief, und das reicht: Die drei Komponenten mit Feldern
     * (`CodeField`, `DnsCredentials`, `PasswordFields`) stehen unmittelbar in
     * ihren Seiten. Käme je eine dazu, die eine andere einbaut, meldete der
     * Test es nicht — deshalb steht die Grenze hier und nicht im Kommentar
     * einer Hilfsmethode.
     *
     * @param  array<string, string>  $inhalt
     */
    private function marks(string $path, array $inhalt): bool
    {
        $source = $inhalt[$path];

        if (str_contains($source, ':aria-invalid=')) {
            return true;
        }

        foreach ($inhalt as $anderer => $fremd) {
            if (! str_contains($anderer, '/Components/') || ! str_contains($fremd, ':aria-invalid=')) {
                continue;
            }

            $name = basename($anderer, '.vue');

            if (str_contains($source, '<'.$name)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }
}
