<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Databases\ImportLimit;
use PHPUnit\Framework\TestCase;

/**
 * Die drei Grenzen eines Uploads passen zusammen.
 *
 * **Der Fehler, gegen den das steht, sieht aus wie ein Netzproblem.** Eine Datei
 * läuft bis 90 % und bricht dann ab — mit einer nginx-Fehlerseite, die von PHP
 * nichts weiss und von diesem Panel erst recht nicht. Der Kunde sieht einen
 * Abbruch ohne Grund, und im Protokoll des Panels steht dazu nichts, weil die
 * Anfrage es nie erreicht hat.
 *
 * Drei Zahlen an drei Orten, und keiner davon weiss von den anderen:
 *
 * | Zahl | Wo sie steht |
 * |---|---|
 * | `client_max_body_size` | `agent/src/Ops/PanelVhost.php` |
 * | `upload_max_filesize`, `post_max_size` | `packaging/etc/fpm.conf` |
 * | die Prüfregel am Formular | {@see ImportLimit} |
 *
 * **Die Reihenfolge ist die Aussage:** nginx am weitesten, PHP dazwischen, die
 * Prüfregel am engsten. Wer abgewiesen wird, soll die Meldung des Panels sehen
 * — die kann sagen, *warum*, und die anderen beiden können es nicht.
 *
 * Wieder dasselbe Muster wie überall hier: drei Zahlen, die aufeinander zeigen,
 * und nichts, das die Zeigerichtung prüft.
 */
final class UploadLimitTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Die Zahl aus dem Server-Block der Oberfläche, in Megabyte. */
    private function nginxLimit(): int
    {
        $source = (string) file_get_contents($this->root().'/agent/src/Ops/PanelVhost.php');

        $this->assertSame(
            1,
            preg_match('/client_max_body_size\s+(\d+)m;/', $source, $treffer),
            'In PanelVhost steht kein client_max_body_size — dann prüft dieser Test nichts.',
        );

        return (int) $treffer[1];
    }

    /**
     * Die Zahlen aus dem FPM-Pool der Oberfläche.
     *
     * @return array<string, int>
     */
    private function phpLimits(): array
    {
        $source = (string) file_get_contents($this->root().'/packaging/etc/fpm.conf');
        $found = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $this->assertSame(
                1,
                preg_match('/php_admin_value\['.$key.'\]\s*=\s*(\d+)M/', $source, $treffer),
                sprintf('In fpm.conf steht kein %s — dann prüft dieser Test nichts.', $key),
            );

            $found[$key] = (int) $treffer[1];
        }

        return $found;
    }

    public function test_the_form_rule_is_the_tightest_of_the_three(): void
    {
        $nginx = $this->nginxLimit();
        $php = $this->phpLimits();

        $this->assertLessThan(
            min($php),
            ImportLimit::MEGABYTES,
            'Die Prüfregel muss enger sein als PHP — sonst weist PHP ab, und die Meldung sagt „keine Datei gewählt".',
        );

        $this->assertLessThan(
            $nginx,
            min($php),
            'PHP muss enger sein als nginx — sonst lässt nginx eine Anfrage durch, die PHP verwirft.',
        );
    }

    /**
     * Und die Klasse sagt dasselbe wie die Dateien.
     *
     * Ohne diese Hälfte wäre {@see ImportLimit} eine Behauptung über zwei
     * Dateien, die niemand nachrechnet — genau die Sorte Zeichenkette, die auf
     * etwas verweist, ohne dass jemand den Bezug prüft.
     */
    public function test_the_class_names_the_same_numbers_as_the_files(): void
    {
        $this->assertSame($this->nginxLimit(), ImportLimit::NGINX_MEGABYTES);

        foreach ($this->phpLimits() as $key => $value) {
            $this->assertSame(
                ImportLimit::PHP_MEGABYTES,
                $value,
                sprintf('%s in fpm.conf und ImportLimit::PHP_MEGABYTES gehen auseinander.', $key),
            );
        }
    }

    /**
     * `post_max_size` ist nicht kleiner als `upload_max_filesize`.
     *
     * PHP nimmt sonst die Datei an und verwirft die ganze Anfrage — dieselbe
     * leere `$_FILES` wie oben, nur aus einem zweiten Grund. Die beiden Zahlen
     * stehen zwei Zeilen auseinander, und genau deshalb fällt es niemandem auf,
     * wenn eine davon geändert wird.
     */
    public function test_post_max_size_is_not_smaller_than_the_upload(): void
    {
        $php = $this->phpLimits();

        $this->assertGreaterThanOrEqual($php['upload_max_filesize'], $php['post_max_size']);
    }

    /** Die Prüfregel rechnet in Kilobyte — das tut Laravel bei `max:`. */
    public function test_the_rule_is_expressed_in_kilobytes(): void
    {
        $this->assertSame('max:'.(ImportLimit::MEGABYTES * 1024), ImportLimit::rule());
        $this->assertSame(ImportLimit::MEGABYTES * 1024 * 1024, ImportLimit::bytes());
    }
}
