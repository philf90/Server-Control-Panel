<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Jede Adminfähigkeit gehört einer Rolle — und die Voreinstellung ist der
 * Betreiber.
 *
 * ## Warum dieser Wächter vor A9 kommt
 *
 * `docs/20 §6.1` teilt die Admin-Ebene in **Betreiber** und **Administrator**;
 * gebaut wird das in A9. Dazwischen liegt P7b, und die Stufe baut vier
 * Merkmale, die alle Adminfunktionen sind: Logs, Dienste, Diagnose, Pakete und
 * Updates.
 *
 * > **Wer eine Adminfunktion baut, entscheidet beim Bauen, auf welcher Seite
 * > sie liegt — und nicht später.**
 *
 * Käme die Entscheidung erst mit A9, müsste jede dieser Seiten ihre
 * `can`-Ablage und ihre Bilder ein zweites Mal bekommen: `AbilityReachTest`
 * besteht darauf, dass ein Knopf, den der Betrachter nicht drücken darf, gar
 * nicht gezeigt wird. Mit zwei Fähigkeitsnamen von Anfang an ändert A9 nur die
 * **Auflösung** der Gates.
 *
 * ## Was hier gehalten wird
 *
 * 1. **Die Voreinstellung ist der Betreiber.** Eine Route unter `/settings/`
 *    mit einer Fähigkeit trägt `can:operate-server` — es sei denn, sie steht in
 *    `AdminAbility::administratorRoutes()` mit ihrer Begründung. Der Fehler fällt damit zur sicheren Seite: Eine
 *    Seite, die versehentlich zu streng ist, meldet sich beim Administrator;
 *    eine, die versehentlich zu offen ist, meldet sich nie.
 * 2. **Die Registratur veraltet nicht.** Jeder Eintrag gehört noch zu einer
 *    Route — dieselbe zweite Richtung wie bei `RouteGuard`.
 * 3. **Kein Verweis ins Leere.** Was in `routes/web.php` als `can:`-Fähigkeit
 *    steht oder im Fliesstext genannt wird, gibt es auch.
 *
 * ## Warum hier nichts geladen wird — auch nicht die Registratur
 *
 * Gelesen werden `routes/web.php` **und** `AdminAbility` als **Text**, nicht
 * über den Router und nicht über die Klasse. Damit läuft dieser Wächter ohne
 * Framework, und seine Eingriffe lassen sich dort belegen, wo `vendor/` fehlt.
 *
 * Deshalb steht oben auch kein `use App\…`: Ein Verweis in einem
 * Dokumentationsblock zieht durch Pints `fully_qualified_strict_types` einen
 * Import nach sich, und der liest sich dann wie eine Abhängigkeit, die es
 * nicht gibt.
 */
final class AdminAbilityTest extends TestCase
{
    /** Die Rolle, der eine Einstellungsseite ohne Eintrag gehört. */
    private const DEFAULT_ABILITY = 'operate-server';

    /**
     * Jede Adminroute gehört dem Betreiber — oder steht mit Begründung da.
     *
     * **Gefragt wird nach der Fähigkeit und nicht nach dem Pfad.** Die erste
     * Fassung dieser Regel las nur Routen unter `/settings/`; beim Bau der
     * Seite „Logs" fiel auf, dass `/logs` eine Adminseite ist und dort nicht
     * liegt. Sie wäre durchgekommen, und der Wächter wäre grün geblieben.
     *
     * > **Eine Regel, die an einem Pfad hängt, gilt für die nächste Seite
     * > nicht mehr — und niemand merkt es, weil sie grün bleibt.**
     */
    public function test_an_admin_route_belongs_to_the_operator_unless_declared(): void
    {
        $declared = $this->administratorRoutes();
        $strays = [];
        $checked = 0;

        foreach ($this->adminRoutes() as [$method, $path, $ability]) {
            $checked++;

            if ($ability === self::DEFAULT_ABILITY) {
                continue;
            }

            if (array_key_exists($path, $declared)) {
                continue;
            }

            $strays[] = sprintf('%s %s trägt can:%s', $method, $path, $ability);
        }

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThan(5, $checked, 'Es werden kaum Adminrouten gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $strays, sprintf(
            "Diese Adminrouten gehören nicht dem Betreiber und sagen nicht, warum:\n\n  %s\n\n"
            .'Kritisch ist, was root auf Dauer verleiht, alle Kunden mitnimmt oder ein Geheimnis '
            .'zeigt (docs/20 §6.1). Wer eine Ausnahme braucht, trägt sie mit ihrer Begründung in '
            .'AdminAbility::administratorRoutes() ein.',
            implode("\n  ", $strays),
        ));
    }

    /**
     * Und die zweite Richtung: Kein Eintrag überlebt seine Route.
     *
     * Ohne sie wächst die Registratur über Jahre und deckt irgendwann eine
     * Seite, an die niemand mehr gedacht hat — der Grund, aus dem `RouteGuard`
     * dieselbe Prüfung trägt.
     */
    public function test_no_declaration_outlives_its_route(): void
    {
        $paths = [];

        foreach ($this->adminRoutes() as [, $path]) {
            $paths[$path] = true;
        }

        foreach (array_keys($this->administratorRoutes()) as $declared) {
            $this->assertArrayHasKey($declared, $paths, sprintf(
                '%s steht in AdminAbility::administratorRoutes(), und eine Einstellungsroute mit '
                .'Fähigkeit gibt es dafür nicht mehr.',
                $declared,
            ));
        }
    }

    /** Jede Ausnahme trägt eine Begründung und keine leere Zeichenkette. */
    public function test_every_declaration_carries_a_reason(): void
    {
        $declarations = $this->administratorRoutes();

        $this->assertNotSame([], $declarations, 'Ohne einen Eintrag misst der Test darunter nichts.');

        foreach ($declarations as $path => $reason) {
            $this->assertGreaterThan(40, mb_strlen($reason), sprintf(
                'Die Begründung für %s ist zu kurz, um eine zu sein.',
                $path,
            ));
        }
    }

    /**
     * Keine Fähigkeit in `routes/web.php` zeigt ins Leere — auch keine im
     * Fliesstext.
     *
     * **Ein Kommentar, der eine Fähigkeit nennt, ist derselbe Verweis wie ein
     * `can:` im Code** — nur prüft ihn nichts. Genau daran ist dieses Projekt
     * mehrfach hängengeblieben: eine Zeichenkette, die auf etwas zeigt, ohne
     * dass ein Typ, ein Test oder ein Werkzeug den Bezug hält. Beim Umzug der
     * elf Routen am 24. August standen vier solcher Kommentare da, und drei
     * nannten danach die falsche Fähigkeit.
     */
    public function test_no_ability_named_in_the_routes_points_nowhere(): void
    {
        $known = array_keys($this->abilities());
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        preg_match_all('/can:([a-z][a-z-]*)/', $source, $matches);

        $this->assertNotSame([], $matches[1], 'Es wird keine Fähigkeit gefunden — dann prüft dieser Test nichts.');

        foreach (array_unique($matches[1]) as $ability) {
            // Modell-Policies (`can:view,domain`) sind keine Gates; sie stehen
            // in den Policies und nicht in der Registratur.
            if (! str_contains($ability, '-')) {
                continue;
            }

            $this->assertContains($ability, $known, sprintf(
                'routes/web.php nennt can:%s, und AdminAbility kennt die Fähigkeit nicht.',
                $ability,
            ));
        }
    }

    /** Beide Fähigkeiten gehören einer der zwei Rollen aus `docs/20 §6.1`. */
    public function test_every_ability_belongs_to_one_of_the_two_roles(): void
    {
        $abilities = $this->abilities();

        $this->assertArrayHasKey('manage-settings', $abilities);
        $this->assertArrayHasKey(self::DEFAULT_ABILITY, $abilities);

        foreach ($abilities as $ability => $declaration) {
            $this->assertContains($declaration['role'], ['operator', 'administrator'], sprintf(
                '%s gehört der Rolle „%s", und die gibt es in docs/20 §6.1 nicht.',
                $ability,
                $declaration['role'],
            ));

            $this->assertGreaterThan(40, mb_strlen($declaration['reason']), sprintf(
                'Die Begründung für %s ist zu kurz, um eine zu sein.',
                $ability,
            ));
        }
    }

    /**
     * Die Gates entstehen aus der Registratur und nicht daneben.
     *
     * Eine Fähigkeit, die mit einem eigenen `Gate::define` danebensteht, hat
     * keine Rolle und keine Begründung — und niemand merkt es, weil sie
     * funktioniert.
     */
    public function test_no_gate_is_defined_beside_the_registry(): void
    {
        $root = dirname(__DIR__, 2);
        $strays = [];
        $found = 0;

        foreach ($this->phpFiles($root.'/app') as $path => $source) {
            preg_match_all('/Gate::define\(\s*([^,]+),/', $source, $matches);

            foreach ($matches[1] as $argument) {
                $found++;

                // Aus der Registratur gebaut ist in Ordnung; eine wörtliche
                // Zeichenkette daneben nicht.
                if (! str_contains($argument, "'") && ! str_contains($argument, '"')) {
                    continue;
                }

                $strays[] = substr($path, strlen($root) + 1).': '.trim($argument);
            }
        }

        $this->assertGreaterThan(0, $found, 'Es wird kein Gate::define gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $strays, sprintf(
            "Diese Gates stehen neben der Registratur:\n\n  %s\n\n"
            .'Eine Fähigkeit ohne Eintrag in AdminAbility hat keine Rolle und keine Begründung — '
            .'und mit A9 keine Seite, auf der sie liegt.',
            implode("\n  ", $strays),
        ));
    }

    /**
     * Die Registratur, aus dem Quelltext gelesen.
     *
     * **Ohne `App\` zu laden**, damit dieser Wächter ohne Framework läuft:
     * `AdminAbility` selbst hätte keine Abhängigkeit, aber ein `use App\…` in
     * einer Testdatei zieht in diesem Repo das ganze Gestell nach.
     *
     * @return array<string, array{role: string, reason: string}>
     */
    private function abilities(): array
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Support/Authorization/AdminAbility.php',
        );

        preg_match_all(
            "/self::(\w+) => \[\s*'role' => self::(\w+),\s*'reason' => (.*?),\s*\],/s",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $constants = $this->constants($source);
        $abilities = [];

        foreach ($matches as $match) {
            $abilities[$constants[$match[1]] ?? $match[1]] = [
                'role' => $constants[$match[2]] ?? $match[2],
                'reason' => $this->joined($match[3]),
            ];
        }

        return $abilities;
    }

    /**
     * Die erklärten Ausnahmen, aus dem Quelltext gelesen.
     *
     * @return array<string, string>
     */
    private function administratorRoutes(): array
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Support/Authorization/AdminAbility.php',
        );

        $start = strpos($source, 'function administratorRoutes');

        if ($start === false) {
            return [];
        }

        preg_match_all(
            "/'([a-z0-9\/-]+)' => ((?:'(?:[^'\\\\]|\\\\.)*'\s*\.?\s*)+),/",
            substr($source, $start),
            $matches,
            PREG_SET_ORDER,
        );

        $declared = [];

        foreach ($matches as $match) {
            $declared[$match[1]] = $this->joined($match[2]);
        }

        return $declared;
    }

    /**
     * Die Konstanten der Registratur — Name => Wert.
     *
     * @return array<string, string>
     */
    private function constants(string $source): array
    {
        preg_match_all("/public const (\w+) = '([^']+)';/", $source, $matches, PREG_SET_ORDER);

        $constants = [];

        foreach ($matches as $match) {
            $constants[$match[1]] = $match[2];
        }

        return $constants;
    }

    /** Eine über mehrere Zeilen verkettete Zeichenkette zu einer machen. */
    private function joined(string $literal): string
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $literal, $parts);

        return implode('', $parts[1]);
    }

    /**
     * Die Routen, die eine Adminfähigkeit tragen — mit ihr.
     *
     * Eine Route ohne `can:` oder mit einer Modell-Policy (`can:view,domain`)
     * ist keine Adminroute: `/settings/profile` und die Zwei-Faktor-Einrichtung
     * gehören jedem Konto selbst.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function adminRoutes(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');
        $routes = [];

        foreach (preg_split('/(?=Route::(?:get|post|put|patch|delete)\s*\()/', $source) ?: [] as $block) {
            if (preg_match("/^Route::(get|post|put|patch|delete)\s*\(\s*'([^']+)'/", $block, $match) !== 1) {
                continue;
            }

            if (preg_match("/->middleware\('can:([a-z][a-z-]*)'\)/", $block, $can) !== 1) {
                continue;
            }

            // Nur die Fähigkeiten der Registratur; alles ohne Bindestrich ist
            // eine Modell-Policy und gehört zu einem Modell statt zu einer Rolle.
            if (! str_contains($can[1], '-')) {
                continue;
            }

            $routes[] = [strtoupper($match[1]), ltrim($match[2], '/'), $can[1]];
        }

        return $routes;
    }

    /**
     * @return array<string,string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
