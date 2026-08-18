<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Eine Datei oder einen Baum kopieren.
 *
 * **Ein Verweis wird als Verweis kopiert und nicht als sein Ziel.** Andernfalls
 * verwandelte das Kopieren eines Verzeichnisses jede Abkürzung darin in eine
 * vollständige Kopie — und aus einem Baum mit einem Verweis auf sich selbst
 * würde ein Kopiervorgang, der nie endet.
 *
 * **Die Tiefe ist begrenzt, und zwar an derselben Zahl wie der Pfad.** Der
 * Abstieg ist rekursiv und läuft im Kind; ein Stapelüberlauf dort ist kein
 * Fehler mit Meldung, sondern ein Signal — und ein Kind, das durch ein Signal
 * stirbt, sieht von aussen aus wie eine Zeitüberschreitung.
 */
final class FilesCopy implements Op
{
    public static function name(): string
    {
        return 'files.copy';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $from = Workspace::path($args['from'] ?? null, 'from');
        $to = Workspace::path($args['to'] ?? null, 'to');

        if ($from === $to) {
            throw AgentException::badRequest('Quelle und Ziel sind dasselbe.', ['path' => $from]);
        }

        if (str_starts_with($to.'/', $from.'/')) {
            throw AgentException::badRequest('Ein Verzeichnis lässt sich nicht in sich selbst kopieren.', [
                'from' => $from,
                'to' => $to,
            ]);
        }

        $result = $workspace->run($context, static function () use ($from, $to): array {
            if (Entry::of($from) === null) {
                throw new AgentException(AgentException::NOT_FOUND, 'Die Quelle gibt es nicht.', ['from' => $from]);
            }

            if (Entry::of($to) !== null) {
                throw AgentException::badRequest('Am Ziel steht schon etwas.', ['to' => $to]);
            }

            $count = self::duplicate($from, $to, 0);

            clearstatcache(true, $to);

            return ['entry' => Entry::of($to), 'copied' => $count];
        });

        return $result;
    }

    /**
     * Der rekursive Abstieg — läuft ausschliesslich im Kind.
     *
     * @return int Wie viele Einträge kopiert wurden.
     */
    private static function duplicate(string $from, string $to, int $depth): int
    {
        if ($depth > Workspace::MAX_DEPTH) {
            throw AgentException::badRequest('Der Baum ist tiefer verschachtelt als erlaubt.', [
                'max_depth' => Workspace::MAX_DEPTH,
            ]);
        }

        $stat = @lstat($from);

        if ($stat === false) {
            return 0;
        }

        $type = $stat['mode'] & 0o170000;

        if ($type === 0o120000) {
            $target = @readlink($from);

            if ($target === false || ! @symlink($target, $to)) {
                throw AgentException::denied('Ein Verweis liess sich nicht kopieren.');
            }

            return 1;
        }

        if ($type !== 0o040000) {
            if (! @copy($from, $to)) {
                throw AgentException::denied('Eine Datei liess sich nicht kopieren — möglicherweise ist das Kontingent erschöpft.');
            }

            // Nach dem Kopieren nachsehen: `copy()` meldet bei erschöpfter
            // Quota nicht immer `false`, und eine halbe Datei sieht wie eine
            // ganze aus, solange niemand die Grösse vergleicht.
            clearstatcache(true, $to);

            if (@filesize($to) !== $stat['size']) {
                @unlink($to);

                throw AgentException::execFailed('Eine Datei wurde nur unvollständig kopiert — vermutlich ist das Kontingent erschöpft.');
            }

            @chmod($to, $stat['mode'] & 0o7777);

            return 1;
        }

        if (! @mkdir($to, $stat['mode'] & 0o7777)) {
            throw AgentException::denied('Ein Verzeichnis liess sich nicht anlegen.');
        }

        $count = 1;

        foreach (@scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $count += self::duplicate($from.'/'.$entry, $to.'/'.$entry, $depth + 1);
        }

        return $count;
    }
}
