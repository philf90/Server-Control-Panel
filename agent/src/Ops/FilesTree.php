<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Die Unterverzeichnisse eines Verzeichnisses — für den Baum.
 *
 * ## Warum das nicht `files.list` ist
 *
 * `files.list` gibt jeden Eintrag mit Grösse, Rechten, Zeitstempel und
 * Verweisziel zurück. Ein Baum braucht davon **nichts** ausser dem Namen. Bei
 * einem `httpdocs` mit fünftausend Bildern wäre der Unterschied zwischen den
 * beiden Antworten mehrere hundert Kilobyte — je aufgeklapptem Ast, und ein
 * Baum klappt viele auf.
 *
 * > **Eine Antwort, die zehnmal mehr trägt als die Frage verlangt, wird
 * > zehnmal geschickt.**
 *
 * ## Und warum `children` mitkommt
 *
 * Ohne diese Angabe müsste die Oberfläche an **jeden** Ast einen Aufklapper
 * malen und erst beim Klick merken, dass nichts darunter liegt. Ein Aufklapper,
 * der sich öffnet und nichts zeigt, ist eine Zusage, die der Baum nicht halten
 * kann.
 *
 * Sie kostet je Kind ein `scandir`, und das ist vertretbar: Alles läuft in
 * **einem** Eintritt in die Sandbox, und der Fork davor ist teurer als die
 * Verzeichnisse dahinter.
 */
final class FilesTree implements Op
{
    /**
     * Wie viele Unterverzeichnisse höchstens zurückgehen.
     *
     * Enger als bei `files.list`: Ein Baum mit tausend Geschwistern ist keine
     * Navigation mehr, sondern eine Liste — und für die gibt es die Liste.
     */
    public const MAX_DIRECTORIES = 1000;

    public static function name(): string
    {
        return 'files.tree';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? '/');

        return $workspace->run(static function () use ($path): array {
            if (! is_dir($path)) {
                throw is_file($path) || is_link($path)
                    ? AgentException::badRequest('Das ist kein Verzeichnis.', ['path' => $path])
                    : new AgentException(AgentException::NOT_FOUND, 'Das Verzeichnis gibt es nicht.', ['path' => $path]);
            }

            $names = @scandir($path);

            if ($names === false) {
                throw AgentException::denied('Das Verzeichnis lässt sich nicht lesen.');
            }

            $wurzel = rtrim($path, '/');
            $verzeichnisse = [];
            $gekuerzt = false;

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if (count($verzeichnisse) >= self::MAX_DIRECTORIES) {
                    $gekuerzt = true;

                    break;
                }

                $voll = $wurzel.'/'.$name;

                /*
                 * **`lstat` und nicht `is_dir`.** Ein Verweis auf ein
                 * Verzeichnis ist im Baum kein Ast: Er führt woandershin, und
                 * ein Baum, der ihm folgt, zeigt denselben Inhalt an zwei
                 * Stellen — oder läuft im Kreis, wenn der Verweis nach oben
                 * zeigt. Im Chroot kann er ausserdem ins Leere gehen.
                 */
                $stat = @lstat($voll);

                if ($stat === false || ($stat['mode'] & 0o170000) !== 0o040000) {
                    continue;
                }

                $verzeichnisse[] = [
                    'name' => $name,
                    'path' => ($wurzel === '' ? '' : $wurzel).'/'.$name,
                    'children' => self::hasChildren($voll),
                ];
            }

            usort($verzeichnisse, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

            return [
                'path' => $path,
                'directories' => $verzeichnisse,
                'truncated' => $gekuerzt,
            ];
        });
    }

    /**
     * Liegt darunter noch ein Verzeichnis?
     *
     * **Beim ersten Treffer wird abgebrochen.** Die Frage lautet „gibt es
     * eines", nicht „wie viele" — und ein `scandir`, das bei einem Ordner mit
     * zwanzigtausend Bildern alle Namen einliest, um dann eines zu melden,
     * beantwortet dieselbe Frage teurer.
     *
     * Ein Verzeichnis, das sich nicht lesen lässt, gilt als leer. Das ist die
     * ehrlichere von zwei Auskünften: Ein Aufklapper, der beim Öffnen „darf ich
     * nicht" sagt, ist eine Einladung, die zurückgenommen wird.
     */
    private static function hasChildren(string $path): bool
    {
        $griff = @opendir($path);

        if ($griff === false) {
            return false;
        }

        try {
            while (($name = readdir($griff)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $stat = @lstat(rtrim($path, '/').'/'.$name);

                if ($stat !== false && ($stat['mode'] & 0o170000) === 0o040000) {
                    return true;
                }
            }
        } finally {
            closedir($griff);
        }

        return false;
    }
}
