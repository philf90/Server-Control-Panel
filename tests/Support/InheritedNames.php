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

        foreach (self::declarations($source) as $name => $visibility) {
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
     * Die Methoden, die auf der Klasse dieser Datei landen.
     *
     * **Über die Token und nicht über einen Ausdruck.** Ein regulärer Ausdruck
     * fände auch eine Erklärung, die in einer Zeichenkette steht — und dieses
     * Projekt erzeugt an mehreren Stellen Text, der wie Quelltext aussieht.
     * `token_get_all()` weiss, was Zeichenkette ist; derselbe Grund wie bei
     * {@see WithoutPhpComments}.
     *
     * **Und über die Klasse und nicht über die Datei — das ist die zweite
     * Hälfte einer Berichtigung, die schon einmal halb gemacht wurde.**
     * `BaseMethodClashTest` hat beim ersten Wurf alles unter `tests/Support`
     * eingesammelt und drei Attrappen gemeldet, die gar nicht von `TestCase`
     * erben; die Behebung war, den **Dateisatz** einzugrenzen. Der Fehler sass
     * eine Ebene tiefer weiter: *In* einer Datei wurde jede Funktion der
     * Testklasse zugeschlagen. Am 22. August 2026 hat das drei Fehlbefunde
     * erzeugt — ein Doppel neben seinem Testfall in derselben Datei und eine
     * anonyme Klasse in einer Methode, jedes mit einem eigenen
     * `__construct()`.
     *
     * > **Ein Wächter, der seinen Geltungsbereich an der Datei festmacht,
     * > prüft die Datei und nicht die Klasse.**
     *
     * Gesammelt wird deshalb nur, was wirklich auf der Klasse landet: die
     * Methoden einer **benannten Klasse mit `extends`** und die eines
     * **Traits** — der wird hineingezogen und verdrängt dort die geerbte
     * Methode. Eine zweite Klasse ohne `extends`, eine anonyme Klasse, ein
     * Interface und ein Enum stehen für sich.
     *
     * @return array<string, string> Name auf Sichtbarkeit
     */
    public static function declarations(string $source): array
    {
        $declared = [];
        $visibility = 'public';
        $tokens = token_get_all($source);

        /** @var list<array{collect: bool, depth: int}> $frames */
        $frames = [];

        /** @var array{collect: bool, at: int}|null $pending */
        $pending = null;
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                if ($token === '{') {
                    $depth++;

                    if ($pending !== null && $pending['at'] === $index) {
                        $frames[] = ['collect' => $pending['collect'], 'depth' => $depth];
                        $pending = null;
                    }
                } elseif ($token === '}') {
                    if ($frames !== [] && $frames[count($frames) - 1]['depth'] === $depth) {
                        array_pop($frames);
                    }

                    $depth--;
                }

                // Ein `;` oder eine Klammer beendet, was an Angaben davorstand.
                $visibility = 'public';

                continue;
            }

            // Die geschweifte Klammer einer Einsetzung in einer Zeichenkette
            // zählt mit — ihre schliessende ist ein gewöhnliches `}`, und ohne
            // sie liefe die Tiefe auseinander.
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

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

            if (in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                $body = self::bodyAfter($tokens, $index, $token[0]);

                if ($body !== null) {
                    $pending = $body;
                }

                continue;
            }

            if ($token[0] !== T_FUNCTION) {
                continue;
            }

            $name = self::nameAfter($tokens, $index);

            if ($name !== null && $frames !== [] && $frames[count($frames) - 1]['collect']) {
                $declared[$name] = $visibility;
            }

            $visibility = 'public';
        }

        return $declared;
    }

    /**
     * Wo der Rumpf dieser Struktur anfängt — und ob seine Methoden zählen.
     *
     * `null` heisst „das ist gar keine Erklärung": `Foo::class` ist ein
     * `T_CLASS` wie jede Klassenerklärung, und wer das übersieht, schiebt beim
     * nächsten `{` einen Rahmen auf den Stapel, der dort nicht hingehört.
     *
     * **Für diese eine Zeile gibt es keinen erreichbaren Bruch, und das steht
     * hier statt einer Zusage.** Sie nimmt ohne Wirkung weg, was zwei andere
     * Prüfungen schon abfangen: Hinter `::class` steht nie ein `T_STRING`,
     * also bleibt `$named` falsch und der Rahmen zählt ohnehin nicht — und
     * folgt bis zur nächsten Klammer doch eine echte Erklärung, überschreibt
     * deren `T_CLASS` das Vorgemerkte. Nachgemessen am 22. August 2026: ohne
     * diese Zeile bleiben alle Fälle grün. Sie bleibt trotzdem, weil sie die
     * Absicht ausspricht.
     *
     * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
     * > nicht als Zusage.**
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{collect: bool, at: int}|null
     */
    private static function bodyAfter(array $tokens, int $index, int $type): ?array
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_DOUBLE_COLON) {
                return null;
            }

            break;
        }

        $named = false;
        $extends = false;
        $first = true;
        $parens = 0;

        for ($i = $index + 1, $ende = count($tokens); $i < $ende; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($first && $token[0] === T_STRING) {
                    $named = true;
                }

                if ($token[0] === T_EXTENDS) {
                    $extends = true;
                }

                $first = false;

                continue;
            }

            $first = false;

            if ($token === '(') {
                $parens++;

                continue;
            }

            if ($token === ')') {
                $parens--;

                continue;
            }

            // Nur die Klammer auf der äussersten Ebene ist der Rumpf. Die
            // Klammern in den Argumenten einer anonymen Klasse sind es nicht.
            if ($token === '{' && $parens === 0) {
                return [
                    'collect' => $type === T_TRAIT || ($type === T_CLASS && $named && $extends),
                    'at' => $i,
                ];
            }
        }

        return null;
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
