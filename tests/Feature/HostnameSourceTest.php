<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Names;
use Tests\Support\WithoutPhpComments;

/**
 * „Wie heisst dieser Rechner?" beantwortet `Names` — und sonst niemand.
 *
 * **Dieser Wächter kommt beim dritten Mal.** `php_uname('n')` liefert den
 * Knotennamen des Kernels, und der ist auf den meisten Servern der kurze:
 * „cloudsrv24" statt „cloudsrv24.de". Der vollständige Name steht nicht im
 * Kernel — er steht in `/etc/hosts` oder im Namensdienst, und `Names::fqdn()`
 * ist die Funktion, die dort nachsieht.
 *
 * Die drei Male:
 *
 *   1. `srvpanel setup` schrieb den kurzen Namen ins Zertifikat.
 *   2. `Names::forThisHost()` leitete aus dem Knotennamen noch eine Kurzform
 *      ab — auf einem Server mit ohnehin kurzem Knotennamen kam damit
 *      ausschliesslich „cloudsrv24" ins Zertifikat, und wer „cloudsrv24.de"
 *      aufrief, bekam eine Warnung über einen Namen, der nicht passt.
 *   3. `PanelProvision` setzte `APP_URL` aus dem Knotennamen zusammen — die
 *      Adresse, unter der sich das Panel in Mails und erzeugten Verweisen
 *      selbst nennt.
 *
 * Zweimal wurde es einzeln behoben, und beide Male stand danach ein Kommentar
 * da, der die Regel erklärt. Ein Kommentar ist kein Wächter. CLAUDE.md sagt
 * über genau diese Funktion: „sie ist die *einzige* Stelle, die diese Frage
 * beantworten darf. Sie ist schon zweimal neu erfunden worden."
 */
final class HostnameSourceTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Wer `php_uname('n')` fragen darf — mit Grund.
     *
     * `Names.php` ist die Antwort selbst. `SystemInfo` zeigt den Knotennamen
     * neben der Kernelfassung an: Das ist bewusst die Auskunft „so nennt der
     * Kernel diese Maschine" und nicht „unter diesem Namen ist der Server
     * erreichbar" — zwei verschiedene Fragen, und nur die zweite gehört zu
     * `Names`.
     */
    private const ALLOWED = [
        'agent/src/Names.php',
        'agent/src/Ops/SystemInfo.php',
    ];

    /** @return list<string> */
    private function sources(): array
    {
        $files = [];

        foreach (['app', 'agent/src', 'config', 'bootstrap'] as $verzeichnis) {
            $wurzel = dirname(__DIR__, 2).'/'.$verzeichnis;

            if (! is_dir($wurzel)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        $this->assertGreaterThan(30, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    public function test_only_names_asks_the_kernel_for_the_hostname(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->sources() as $path) {
            $relativ = $this->relative($path);
            $source = $this->withoutComments((string) file_get_contents($path));

            /*
             * Auch `gethostname()` — sie beantwortet dieselbe Frage mit
             * demselben kurzen Ergebnis und wäre der naheliegende Weg, den
             * Ausdruck oben zu umgehen, ohne dass etwas meldet.
             */
            $treffer = preg_match_all("/php_uname\(\s*'n'\s*\)|\bgethostname\(\s*\)/", $source);

            if ($treffer === 0) {
                continue;
            }

            if (in_array($relativ, self::ALLOWED, true)) {
                $checked += $treffer;

                continue;
            }

            $found[] = sprintf('%s: %d×', $relativ, $treffer);
        }

        $this->assertGreaterThan(1, $checked, 'Die erlaubten Stellen fragen gar nicht mehr — dann ist die Wortliste veraltet.');

        $this->assertSame([], $found, sprintf(
            "Diese Dateien fragen den Kernel selbst nach dem Rechnernamen:\n  %s\n\n".
            "`php_uname('n')` liefert den **kurzen** Namen — „cloudsrv24\" statt „cloudsrv24.de\". Der\n".
            "vollständige steht in /etc/hosts oder im Namensdienst, und dort sieht SrvPanel\\Agent\\Names\n".
            "nach: `fqdn()` für den vollständigen Namen oder `null`, `host()` für „der beste Name, den\n".
            'dieser Rechner hat". Genau dieser Fehler ist dreimal aufgetreten.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Und `Names::host()` gibt immer einen Namen zurück.
     *
     * Sie ist die Funktion für alle, die eine Adresse zusammensetzen — und die
     * können mit „keiner" nichts anfangen. Ohne diese Zusicherung stünde beim
     * nächsten Mal wieder ein `?? php_uname('n')` daneben.
     */
    public function test_the_best_name_is_never_empty(): void
    {
        $this->assertNotSame('', Names::host());
    }
}
