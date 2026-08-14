<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wer eine Datenbank zeigt, nennt ihr System — und zwar überall gleich.
 *
 * **Warum es diesen Wächter gibt.** Die Konsole aus P5c hat `engine_label` seit
 * ihrem ersten Tag im Payload und hat es **nie angezeigt**. Aufgefallen ist es
 * nicht bei einer Durchsicht, sondern dem Betreiber im Betrieb: Beide Systeme
 * haben dieselbe Fläche, dieselben Griffe und dieselben Meldungen, und der Name
 * verrät sein System nur dem, der die Präfixregeln kennt. Wer zwei Konsolen
 * offen hat, verwechselt sie.
 *
 * > **Eine Angabe, die eine Seite bekommt und nicht zeigt, ist entweder
 * > überflüssig oder vergessen — und man sieht ihr nicht an, welches von
 * > beidem.**
 *
 * Die Liste und die Detailseite zeigten sie längst, jede als neutrale Marke.
 * Die Konsole war die dritte Fläche und die einzige ohne — genau die Sorte
 * Lücke, die dieses Projekt sammelt: nicht falsch, sondern **nicht da**, und
 * deshalb von keinem Test bemerkt.
 *
 * **Geprüft wird auch die Form und nicht nur das Vorkommen.** Dieselbe Angabe
 * einmal als Marke und einmal als nackter Text wäre eine dritte Fassung — genau
 * das, was {@see ClassNameTest} und `Badge.vue` schon einmal
 * aufgeräumt haben, als es `.badge`, `.chip`, `.marke` und `.status` für
 * denselben Zweck gab.
 */
final class EngineLabelTest extends TestCase
{
    /**
     * Jede Fläche der Datenbanken, je Pfad ihr Quelltext.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/resources/js/Pages/Databases', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $found[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($found);

        return $found;
    }

    /** Der `<template>`-Block, ohne HTML-Kommentare. */
    private function template(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
            return '';
        }

        return (string) preg_replace('/<!--.*?-->/su', '', $match[1]);
    }

    /**
     * Was die Seite bekommt, zeigt sie auch.
     *
     * **Der Umweg über die Props ist Absicht.** Eine Liste der Seiten, die es
     * betrifft, wäre eine zweite Fassung des Payloads — und die zweite ist die,
     * die veraltet, sobald eine vierte Fläche dazukommt. Die Frage lautet
     * deshalb: Wer die Angabe bekommt, muss sie zeigen. Wer sie nicht braucht,
     * nimmt sie aus den Props.
     */
    public function test_a_page_that_gets_the_engine_shows_it(): void
    {
        $stumm = [];
        $geprueft = 0;

        foreach ($this->pages() as $pfad => $source) {
            $skript = (string) preg_replace('#<template>.*</template>#su', '', $source);

            if (! str_contains($skript, 'engine_label')) {
                continue;
            }

            $geprueft++;

            if (! str_contains($this->template($source), 'engine_label')) {
                $stumm[] = $pfad;
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $geprueft,
            'Weniger als drei Flächen bekommen das System — dann liest dieser Wächter die falschen '.
            'Dateien, und seine Zustimmung bedeutet nichts.',
        );

        $this->assertSame([], $stumm, sprintf(
            "Diese Flächen bekommen den Namen des Datenbanksystems und zeigen ihn nicht:\n  %s\n\n".
            'Beide Systeme sehen in diesem Panel gleich aus; der Datenbankname verrät sein System nur '.
            'dem, der die Präfixregeln kennt.',
            implode("\n  ", $stumm),
        ));
    }

    /**
     * Und alle zeigen ihn in derselben Form.
     *
     * **Eine neutrale Marke und kein Text daneben.** `Badge` trägt vier Ränge
     * für Zustände; ein Datenbanksystem ist keiner, und `neutral` ist der Rang
     * für „kein Zustand" — dieselbe Wahl, die Liste und Detailseite schon
     * getroffen haben.
     */
    public function test_all_of_them_show_it_the_same_way(): void
    {
        $anders = [];
        $geprueft = 0;

        foreach ($this->pages() as $pfad => $source) {
            $template = $this->template($source);

            if (! str_contains($template, 'engine_label')) {
                continue;
            }

            $geprueft++;

            if (preg_match('/<Badge\s+kind="neutral"\s*>\s*\{\{[^}]*engine_label[^}]*\}\}\s*<\/Badge>/su', $template) !== 1) {
                $anders[] = $pfad;
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $geprueft,
            'Weniger als drei Flächen zeigen das System — dann prüft dieser Test die falschen Dateien.',
        );

        $this->assertSame([], $anders, sprintf(
            "Diese Flächen zeigen das System nicht als neutrale Marke:\n  %s\n\n".
            'Dieselbe Angabe in zwei Formen ist eine Fassung zu viel — der Grund, aus dem es '.
            '`Badge.vue` überhaupt gibt.',
            implode("\n  ", $anders),
        ));
    }
}
