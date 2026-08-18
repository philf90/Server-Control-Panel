<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Nach Namen und nach Inhalt suchen.
 *
 * **Kein `grep` und kein `find`** — beide stünden auf der Positivliste, und
 * beide bekämen dort einen Suchbegriff des Kunden als Argument. Die Suche läuft
 * in PHP innerhalb der Sandbox, und damit ist der Begriff eine Zeichenkette,
 * die verglichen wird, und niemals ein Teil einer Kommandozeile.
 *
 * **Gesucht wird wörtlich und nicht als Muster.** Ein regulärer Ausdruck vom
 * Kunden wäre ein Weg, den Vorgang zum Stillstand zu bringen (`(a+)+b` gegen
 * eine lange Zeile), und dafür gibt es kein Zeitlimit, das den Prozess
 * rechtzeitig einholt. `str_contains` kann das nicht.
 *
 * **Drei Grenzen, und alle drei melden sich.** Ein Suchlauf, der bei Grenze
 * eins abbricht, muss das sagen — sonst liest der Kunde „nichts gefunden", wo
 * „nicht zu Ende gesucht" richtig wäre.
 *
 * > Eine leere Ergebnisliste, die einen Abbruch verschweigt, behauptet etwas,
 * > das sie nicht weiss.
 */
final class FilesSearch implements Op
{
    /** Wie viele Einträge angesehen werden. */
    public const MAX_VISITED = 50000;

    /** Wie viele Treffer zurückgehen. */
    public const MAX_HITS = 500;

    /** Bis zu welcher Grösse eine Datei nach Inhalt durchsucht wird. */
    public const MAX_CONTENT_BYTES = 2 * 1024 * 1024;

    public static function name(): string
    {
        return 'files.search';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? '/');
        $needle = Guard::string($args['query'] ?? null, 'query');
        $inContent = ($args['content'] ?? false) === true;

        if (trim($needle) === '') {
            throw AgentException::badRequest('Ohne Suchbegriff gibt es nichts zu suchen.');
        }

        return $workspace->run($context, static function () use ($path, $needle, $inContent): array {
            if (! is_dir($path)) {
                throw new AgentException(AgentException::NOT_FOUND, 'Das Verzeichnis gibt es nicht.', ['path' => $path]);
            }

            $hits = [];
            $visited = 0;
            $abgebrochen = false;

            $walk = static function (string $directory, int $depth) use (
                &$walk, &$hits, &$visited, &$abgebrochen, $needle, $inContent
            ): void {
                if ($abgebrochen || $depth > Workspace::MAX_DEPTH) {
                    return;
                }

                foreach (@scandir($directory) ?: [] as $name) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }

                    if ($visited >= self::MAX_VISITED || count($hits) >= self::MAX_HITS) {
                        $abgebrochen = true;

                        return;
                    }

                    $visited++;
                    $child = rtrim($directory, '/').'/'.$name;
                    $entry = Entry::of($child);

                    if ($entry === null) {
                        continue;
                    }

                    // Einem Verweis wird nicht gefolgt. Im Chroot führte er
                    // höchstens im Kreis — und ein Suchlauf, der zweimal
                    // dasselbe Verzeichnis absucht, meldet Treffer doppelt.
                    if ($entry['type'] === 'link') {
                        continue;
                    }

                    if (stripos($name, $needle) !== false) {
                        $hits[] = ['entry' => $entry, 'match' => 'name', 'line' => null];

                        continue;
                    }

                    if ($entry['type'] === 'directory') {
                        $walk($child, $depth + 1);

                        continue;
                    }

                    if (! $inContent || $entry['size'] > self::MAX_CONTENT_BYTES || ! $entry['readable']) {
                        continue;
                    }

                    $content = @file_get_contents($child);

                    // Ungültiges UTF-8 nähme über `json_decode()` die ganze
                    // Antwort mit und nicht nur diesen Treffer (`docs/46 §8`).
                    if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
                        continue;
                    }

                    if (! str_contains($content, $needle)) {
                        continue;
                    }

                    $zeile = null;

                    foreach (explode("\n", $content) as $nummer => $inhalt) {
                        if (str_contains($inhalt, $needle)) {
                            $zeile = ['number' => $nummer + 1, 'text' => mb_substr(trim($inhalt), 0, 200)];

                            break;
                        }
                    }

                    $hits[] = ['entry' => $entry, 'match' => 'content', 'line' => $zeile];
                }
            };

            $walk($path, 0);

            return [
                'hits' => $hits,
                'visited' => $visited,

                // **Die Angabe, ohne die eine leere Liste lügt.**
                'truncated' => $abgebrochen,
            ];
        });
    }
}
