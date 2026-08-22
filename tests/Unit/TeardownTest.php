<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Was eine Seite anlegt, räumt sie beim Verlassen wieder weg.
 *
 * ## Der Anlass
 *
 * Die Übersicht lädt ihre Kacheln seit dem 22. August 2026 alle dreissig
 * Sekunden nach — Wunsch des Betreibers. Dafür braucht sie ein `setInterval`
 * und einen Horcher auf `visibilitychange`.
 *
 * **Und genau daran hängt ein Fehler, den dieses Gerüst besonders leicht
 * macht.** Ein Panel mit Seitenwechsel über den Server verliert beides von
 * selbst: Die nächste Seite ist ein neues Dokument. Inertia tauscht die Seite
 * aber **im selben Dokument** aus. Ein Takt, den niemand anhält, fragt bis zum
 * Schliessen des Reiters weiter — von jeder Seite aus, auf der man einmal war,
 * und alle zugleich.
 *
 * > **Ein Takt ohne Abschaltung hört nicht auf, wenn die Seite verschwindet —
 * > er hört auf, wenn der Browser zugeht.**
 *
 * Er ist dabei unsichtbar: Die Anfragen laufen, die Antworten landen in einer
 * Komponente, die es nicht mehr gibt, und keine Meldung entsteht. Auffallen
 * würde es am Server, als Last ohne erkennbaren Anlass.
 *
 * ## Was er prüft
 *
 * Zweierlei, und beides in **derselben Datei**:
 *
 *   - Wer `setInterval` ruft, ruft auch `clearInterval`.
 *   - Wer an `document` oder `window` horcht, hört auch wieder auf — je
 *     Ereignisnamen, nicht bloss der Zahl nach.
 *
 * Und beides nur, wenn die Datei einen Aufräumhaken hat: Ohne `onUnmounted`
 * oder `onBeforeUnmount` läuft der Rückweg nie.
 *
 * ## Warum `document` und `window` und nicht jeder Horcher
 *
 * `useOperationStream.ts` horcht auf einer `EventSource` und räumt sie mit
 * `close()` weg — der Horcher stirbt mit seinem Gegenstand. `document` und
 * `window` sterben nicht; sie überleben jede Seite dieses Panels.
 *
 * > **Ein Horcher an einem Gegenstand, den man wegwirft, ist weg. Einer an
 * > einem, der bleibt, bleibt.**
 *
 * ## Was er nicht prüft
 *
 * Ob dieselbe Funktion abgemeldet wird, die angemeldet wurde — ein
 * `removeEventListener` mit einer anderen Funktion tut nichts, und das sieht
 * kein Ausdruck über den Text. Und `setTimeout` steht nicht darin: Der läuft
 * einmal und ist danach fort.
 */
final class TeardownTest extends TestCase
{
    /** Die Haken, in denen ein Rückweg stehen kann. */
    private const HOOKS = ['onUnmounted(', 'onBeforeUnmount('];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Jede Quelldatei der Oberfläche, Pfad zu Inhalt.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $wurzel = $this->root();
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if (! $datei->isFile() || ! in_array($datei->getExtension(), ['vue', 'ts'], true)) {
                continue;
            }

            $dateien[str_replace($wurzel.'/', '', $datei->getPathname())] = (string) file_get_contents($datei->getPathname());
        }

        ksort($dateien);

        return $dateien;
    }

    /**
     * Die Ereignisnamen, auf die eine Quelle an `document` oder `window`
     * horcht — beziehungsweise aufhört.
     *
     * @return list<string>
     */
    private function globalListeners(string $quelle, string $richtung): array
    {
        preg_match_all(
            '/\b(?:document|window)\.'.$richtung."EventListener\\(\\s*['\"]([\\w-]+)['\"]/",
            $quelle,
            $treffer,
        );

        return array_values(array_unique($treffer[1]));
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Ohne einen Fund im Bestand prüfen die Fälle darunter nichts und sind
     * grün, ohne etwas gesehen zu haben.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_is_something_to_tear_down(): void
    {
        $takte = 0;
        $horcher = 0;

        foreach ($this->sources() as $quelle) {
            $takte += substr_count($quelle, 'setInterval(');
            $horcher += count($this->globalListeners($quelle, 'add'));
        }

        $this->assertGreaterThanOrEqual(1, $takte, 'Es gibt keinen Takt mehr — dann prüft die Regel darunter nichts.');
        $this->assertGreaterThanOrEqual(1, $horcher, 'Es horcht niemand mehr an document oder window — dann prüft die Regel darunter nichts.');
    }

    /**
     * **Und die Gegenprobe zum Ausdruck selbst.**
     *
     * Der Fall darüber zählt am Bestand. Er merkt nicht, dass der Ausdruck die
     * **Abgrenzung** verloren hat — ein Ausdruck, der jedes
     * `addEventListener` nimmt, zöge `useOperationStream.ts` mit hinein und
     * verlangte einen Rückweg für einen Horcher, der mit seiner `EventSource`
     * stirbt.
     *
     * Deshalb ein Prüfkörper von Hand und nicht eine Zahl am Bestand:
     *
     * > **Eine Gegenprobe über eine Menge merkt nicht, dass ein Teil der Menge
     * > fehlt.**
     */
    public function test_the_expression_tells_the_two_apart(): void
    {
        $probe = implode("\n", [
            "document.addEventListener('visibilitychange', onVisible)",
            "window.addEventListener('resize', onResize)",
            "source.addEventListener('state', handler)",
            "document.removeEventListener('visibilitychange', onVisible)",
        ]);

        $this->assertSame(
            ['visibilitychange', 'resize'],
            $this->globalListeners($probe, 'add'),
            'Der Ausdruck trennt den Horcher an document/window nicht von dem an einem eigenen Gegenstand.',
        );

        $this->assertSame(
            ['visibilitychange'],
            $this->globalListeners($probe, 'remove'),
            'Der Ausdruck findet die Abmeldung nicht.',
        );
    }

    /** Wer einen Takt setzt, hält ihn auch an. */
    public function test_every_interval_is_cleared(): void
    {
        $ohne = [];

        foreach ($this->sources() as $pfad => $quelle) {
            if (! str_contains($quelle, 'setInterval(')) {
                continue;
            }

            if (! str_contains($quelle, 'clearInterval(')) {
                $ohne[] = $pfad;
            }
        }

        $this->assertSame([], $ohne, implode("\n", [
            'Diese Dateien setzen einen Takt und halten ihn nie an:',
            ...$ohne,
            '',
            'Inertia tauscht die Seite im selben Dokument aus. Ein setInterval',
            'ueberlebt das und fragt bis zum Schliessen des Reiters weiter — von',
            'jeder Seite aus, auf der man einmal war, und alle zugleich.',
            '',
            'Der Weg: clearInterval in onUnmounted.',
        ]));
    }

    /** Wer an `document` oder `window` horcht, hört auch wieder auf. */
    public function test_every_global_listener_is_removed(): void
    {
        $offen = [];

        foreach ($this->sources() as $pfad => $quelle) {
            $ab = $this->globalListeners($quelle, 'remove');

            foreach ($this->globalListeners($quelle, 'add') as $ereignis) {
                if (! in_array($ereignis, $ab, true)) {
                    $offen[] = sprintf('%s — %s', $pfad, $ereignis);
                }
            }
        }

        $this->assertSame([], $offen, implode("\n", [
            'Diese Horcher an document oder window werden nie abgemeldet:',
            ...$offen,
            '',
            'document und window ueberleben jede Seite dieses Panels. Ein Horcher',
            'daran bleibt liegen, laeuft auf einer Komponente, die es nicht mehr',
            'gibt, und meldet dabei nichts.',
            '',
            'Der Weg: removeEventListener mit demselben Ereignis in onUnmounted.',
        ]));
    }

    /** Und der Rückweg steht in einem Haken, der beim Verlassen läuft. */
    public function test_the_teardown_sits_in_an_unmount_hook(): void
    {
        $ohne = [];

        foreach ($this->sources() as $pfad => $quelle) {
            $braucht = str_contains($quelle, 'setInterval(')
                || $this->globalListeners($quelle, 'add') !== [];

            if (! $braucht) {
                continue;
            }

            foreach (self::HOOKS as $haken) {
                if (str_contains($quelle, $haken)) {
                    continue 2;
                }
            }

            $ohne[] = $pfad;
        }

        $this->assertSame([], $ohne, implode("\n", [
            'Diese Dateien legen etwas an, das die Seite ueberlebt, und haben',
            'keinen Haken, in dem der Rueckweg laufen koennte:',
            ...$ohne,
            '',
            'Ein clearInterval, das nirgends gerufen wird, steht im Quelltext und',
            'nicht im Ablauf — und ein Waechter, der nur nach dem Wort sucht,',
            'waere damit gruen.',
        ]));
    }
}
