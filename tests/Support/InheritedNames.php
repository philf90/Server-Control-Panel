<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Namen, die schon der Basisklasse gehören — gefunden ohne zu laden.
 *
 * **Der Grund, aus dem hier nichts geladen wird.** Genau der Fehler, um den es
 * geht, bricht beim *Laden* der Klasse: `Cannot override final method`. Ein
 * Wächter, der die Klasse zur Prüfung lädt, bringt damit den ganzen Lauf zum
 * Absturz statt eine Meldung auszugeben — und stünde als fataler Fehler ohne
 * Zusammenhang da, genau wie der Fall, den er melden soll.
 *
 * Gelesen wird deshalb der Text der Datei, und geladen wird nur die
 * **Basisklasse**: PHPUnits `TestCase`, Laravels `Command`. Die sind in Ordnung
 * — sonst liefe gar nichts.
 */
final class InheritedNames
{
    /**
     * Alle Verstösse unterhalb dieser Verzeichnisse.
     *
     * @param  list<string>  $directories
     * @return list<string>
     */
    public static function conflicts(string $root, array $directories): array
    {
        $found = [];

        foreach (self::files($root, $directories) as $path) {
            foreach (self::inFile($path) as $conflict) {
                $found[] = str_replace($root.'/', '', $path).': '.$conflict;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    public static function files(string $root, array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Was in einer Datei mit ihrer Basisklasse zusammenstösst.
     *
     * @return list<string>
     */
    public static function inFile(string $path): array
    {
        $source = (string) file_get_contents($path);
        $parent = self::parentOf($source);

        if ($parent === null || ! class_exists($parent)) {
            return [];
        }

        $inherited = self::methodsOf($parent);
        $found = [];

        foreach (self::declaredIn($source) as $name => $visibility) {
            $base = $inherited[strtolower($name)] ?? null;

            if ($base === null) {
                continue;
            }

            if ($base['final']) {
                $found[] = sprintf('%s() ist in %s final', $name, $parent);

                continue;
            }

            // Eine Sichtbarkeit lässt sich erweitern, nicht verengen. Der Fall
            // aus der Praxis: `protected function configure()` in einem
            // Artisan-Kommando, in der abgeleiteten Klasse als `private`
            // eingezogen — und `artisan` stand mit allen Kommandos still.
            if ($visibility === 'private' && $base['visibility'] !== 'private') {
                $found[] = sprintf('%s() ist in %s %s und hier private', $name, $parent, $base['visibility']);
            }
        }

        return $found;
    }

    /**
     * Liess sich die Basisklasse dieser Datei überhaupt auflösen?
     *
     * Der Wächter braucht die Auskunft, um zu merken, wenn er ins Leere läuft:
     * Ohne auflösbare Basisklasse findet er nichts und meldet Grün.
     */
    public static function reachesItsBase(string $path): bool
    {
        $parent = self::parentOf((string) file_get_contents($path));

        return $parent !== null && class_exists($parent);
    }

    /**
     * Die Methoden einer Basisklasse — und was an ihnen bindend ist.
     *
     * @return array<string, array{final: bool, visibility: string}>
     */
    private static function methodsOf(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $methods[strtolower($method->getName())] = [
                'final' => $method->isFinal(),
                'visibility' => self::visibilityOf($method),
            ];
        }

        return $methods;
    }

    private static function visibilityOf(ReflectionMethod $method): string
    {
        return match (true) {
            $method->isPrivate() => 'private',
            $method->isProtected() => 'protected',
            default => 'public',
        };
    }

    /**
     * Die Methoden, die eine Datei selbst erklärt.
     *
     * **Über die Token und nicht über einen Ausdruck.** Ein regulärer Ausdruck
     * fände auch eine Erklärung, die in einer Zeichenkette steht — und dieses
     * Projekt erzeugt an mehreren Stellen Text, der wie Quelltext aussieht.
     * `token_get_all()` weiss, was Zeichenkette ist; derselbe Grund wie bei
     * {@see WithoutPhpComments}.
     *
     * @return array<string, string> Name auf Sichtbarkeit
     */
    private static function declaredIn(string $source): array
    {
        $declared = [];
        $visibility = 'public';
        $tokens = token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                // Ein `;` oder eine Klammer beendet, was an Angaben davorstand.
                $visibility = 'public';

                continue;
            }

            if ($token[0] === T_PRIVATE) {
                $visibility = 'private';

                continue;
            }

            if ($token[0] === T_PROTECTED) {
                $visibility = 'protected';

                continue;
            }

            if ($token[0] !== T_FUNCTION) {
                continue;
            }

            $name = self::nameAfter($tokens, $index);

            if ($name !== null) {
                $declared[$name] = $visibility;
            }

            $visibility = 'public';
        }

        return $declared;
    }

    /**
     * Der Name hinter einem `function` — oder `null` bei einer Schliessung.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nameAfter(array $tokens, int $index): ?string
    {
        for ($i = $index + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            // `function (` ist eine Schliessung, `function &foo(` geht weiter.
            return $token === '&' ? self::nameAfter($tokens, $i) : null;
        }

        return null;
    }

    /**
     * Die Basisklasse einer Datei, vollständig benannt.
     *
     * Aufgelöst über die `use`-Zeilen und den eigenen Namensraum — dieselbe
     * Regel, nach der PHP es tut.
     */
    private static function parentOf(string $source): ?string
    {
        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+\w+\s+extends\s+([\\\\\w]+)/m', $source, $match) !== 1) {
            return null;
        }

        $parent = $match[1];

        if (str_starts_with($parent, '\\')) {
            return ltrim($parent, '\\');
        }

        $first = explode('\\', $parent)[0];

        preg_match_all('/^use\s+([\\\\\w]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $uses, PREG_SET_ORDER);

        foreach ($uses as $use) {
            $alias = $use[2] ?? '';
            $alias = $alias === '' ? (string) array_slice(explode('\\', $use[1]), -1)[0] : $alias;

            if ($alias === $first) {
                return $use[1].substr($parent, strlen($first));
            }
        }

        if (preg_match('/^namespace\s+([\\\\\w]+)\s*;/m', $source, $namespace) === 1) {
            return $namespace[1].'\\'.$parent;
        }

        return $parent;
    }
}
