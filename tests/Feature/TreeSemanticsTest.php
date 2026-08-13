<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein Baum ist ein Baum — auch für den, der ihn nicht sieht.
 *
 * **Warum es diesen Wächter gibt.** Der Navigationsbaum aus `docs/46 §11.1` ist
 * ein Bedienmuster, das dieses Panel vorher nicht hatte. Sichtbar besteht er aus
 * Knöpfen mit einem Dreieck davor; ohne `role="tree"`, `role="treeitem"` und
 * `aria-expanded` ist er für einen Screenreader **eine Liste von Knöpfen ohne
 * Zusammenhang** — und das fällt niemandem auf, der ihn sieht.
 *
 * > **Ein Fehler, den nur bemerkt, wer die Seite nicht sehen kann, hat unter den
 * > Sehenden keinen Finder.**
 *
 * Deshalb prüft dieser Test die Form und nicht das Aussehen, und deshalb prüft
 * er **Verhältnisse** statt Vorkommen: Dass irgendwo ein `aria-expanded` steht,
 * sagt nichts darüber, ob jeder aufklappbare Knoten eines trägt.
 */
final class TreeSemanticsTest extends TestCase
{
    /** @return list<string> */
    private function templates(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
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
     * Jede Vorlage, die einen Baum zeichnet.
     *
     * @return array<string, string>
     */
    private function trees(): array
    {
        $trees = [];

        foreach ($this->templates() as $path) {
            $template = $this->template((string) file_get_contents($path));

            if (str_contains($template, 'role="tree"') || str_contains($template, 'role="treeitem"')) {
                $trees[$this->relative($path)] = $template;
            }
        }

        return $trees;
    }

    public function test_every_tree_carries_its_roles(): void
    {
        $trees = $this->trees();

        $this->assertNotSame([], $trees, 'Es wird keine Vorlage mit einem Baum gefunden — dann prüft dieser Test nichts.');

        foreach ($trees as $name => $template) {
            $this->assertStringContainsString(
                'role="tree"',
                $template,
                $name.' zeichnet Baumelemente, aber keinen Baum. Ohne `role="tree"` am Behälter sind die '
                .'Knoten für einen Screenreader Knöpfe ohne Zusammenhang (docs/46 §14.9).',
            );

            $this->assertGreaterThanOrEqual(
                2,
                preg_match_all('/role="treeitem"/', $template),
                $name.' hat weniger als zwei `role="treeitem"` — ein Baum mit einem Punkt ist keiner, '
                .'und dieser Test rechnet dann an nichts nach.',
            );
        }
    }

    /**
     * Jeder aufklappbare Knoten sagt an, ob er offen ist.
     *
     * **Gezählt und nicht gesucht.** Ein Zweig ist genau dann aufklappbar, wenn
     * unter ihm eine `role="group"` steht — also muss es zu jeder Gruppe genau
     * ein `aria-expanded` geben. Wer einen zweiten Zweig ergänzt und das Attribut
     * vergisst, bekommt hier Rot; wer nur nachsieht, ob das Wort irgendwo
     * vorkommt, bekäme Grün.
     */
    public function test_every_expandable_node_announces_its_state(): void
    {
        foreach ($this->trees() as $name => $template) {
            $gruppen = preg_match_all('/role="group"/', $template);
            $offen = preg_match_all('/aria-expanded/', $template);

            $this->assertGreaterThanOrEqual(
                1,
                $gruppen,
                $name.' hat keinen aufklappbaren Zweig — dann prüft dieser Test nichts.',
            );

            $this->assertSame(
                $gruppen,
                $offen,
                sprintf(
                    '%s hat %d Gruppen und %d `aria-expanded`. Zu jedem aufklappbaren Zweig gehört genau '
                    .'eines: Ohne das Attribut sagt der Knoten nicht an, ob er offen ist, und die '
                    .'Pfeiltasten tun etwas, das niemand angesagt bekommt (docs/46 §14.9).',
                    $name,
                    $gruppen,
                    $offen,
                ),
            );
        }
    }

    /**
     * Zwischen dem Baum und seinen Knoten steht nichts mit eigener Rolle.
     *
     * Ein `<li>` bringt `listitem` mit, und damit stünde zwischen `tree` und
     * `treeitem` eine Liste. `role="none"` nimmt sie zurück — die Struktur
     * bleibt als Markup und verschwindet aus dem Zugänglichkeitsbaum.
     */
    public function test_the_list_between_them_carries_no_role_of_its_own(): void
    {
        foreach ($this->trees() as $name => $template) {
            preg_match_all('/<li\b[^>]*>/', $template, $items);

            $this->assertNotSame([], $items[0], $name.' hat keine Listenelemente — dann prüft dieser Test nichts.');

            foreach ($items[0] as $item) {
                $this->assertStringContainsString(
                    'role="none"',
                    $item,
                    sprintf(
                        '%s hat ein `%s` ohne `role="none"`. Zwischen `tree` und `treeitem` darf nichts '
                        .'mit eigener Rolle stehen, und ein `<li>` bringt `listitem` mit.',
                        $name,
                        trim($item, '<>'),
                    ),
                );
            }
        }
    }

    /**
     * Ein Baum wird mit den Pfeiltasten bedient.
     *
     * **Sonst ist er eine Liste von Knöpfen, durch die man tabbt** — und genau
     * das unterscheidet ihn von der Tabelle, die er ersetzt hat. `docs/46 §11.1`
     * nennt die Pfeiltastenbedienung ausdrücklich als Teil des Musters.
     */
    public function test_a_tree_is_operated_with_the_arrow_keys(): void
    {
        foreach ($this->trees() as $name => $template) {
            $this->assertSame(
                1,
                preg_match('/<[a-z]+[^>]*role="tree"[^>]*>/', $template, $behaelter),
                $name.' hat kein einzelnes Element mit `role="tree"` — dieser Test liest den Baum falsch.',
            );

            $this->assertStringContainsString(
                '@keydown',
                $behaelter[0],
                sprintf(
                    '%s bedient seinen Baum nicht mit der Tastatur: `%s`. Ohne Pfeiltasten ist er eine '
                    .'Liste von Knöpfen, durch die man tabbt (docs/46 §11.1).',
                    $name,
                    trim($behaelter[0], '<>'),
                ),
            );
        }
    }
}
