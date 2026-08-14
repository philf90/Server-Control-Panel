<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Eine Datei des Abonnements lesen — für den Editor.
 *
 * **Die Kodierung wird geprüft, bevor irgendetwas zurückgeht.** `docs/46 §8`
 * hat gemessen, was eine ungültige UTF-8-Folge anrichtet: Sie macht über
 * `json_decode()` nicht die eine Zelle unlesbar, sondern die **ganze Antwort**.
 * Eine Datei, die kein gültiges UTF-8 ist, wird deshalb als binär gemeldet und
 * gar nicht erst übertragen — der Editor bekommt eine Auskunft statt eines
 * Vorgangs, der ohne erkennbaren Grund scheitert.
 *
 * > **Eine Gültigkeitsprüfung des einen Systems sagt nichts über den Leser im
 * > anderen.**
 *
 * Die Obergrenze gilt aus demselben Grund wie bei {@see FilesList}: Der Rückweg
 * der Sandbox ist begrenzt, und eine Datei, die ihn sprengt, soll das sagen und
 * nicht den Vorgang abwürgen.
 */
final class FilesRead implements Op
{
    /** Was der Editor höchstens öffnet. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public static function name(): string
    {
        return 'files.read';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? null);

        return $workspace->run(static function () use ($path): array {
            $entry = Entry::of($path);

            if ($entry === null) {
                throw new AgentException(AgentException::NOT_FOUND, 'Die Datei gibt es nicht.', ['path' => $path]);
            }

            if ($entry['type'] !== 'file') {
                throw AgentException::badRequest('Nur eine Datei lässt sich öffnen.', ['path' => $path, 'type' => $entry['type']]);
            }

            if ($entry['size'] > self::MAX_BYTES) {
                return ['entry' => $entry, 'content' => null, 'binary' => false, 'too_large' => true];
            }

            $content = @file_get_contents($path);

            if ($content === false) {
                throw AgentException::denied('Die Datei lässt sich nicht lesen.');
            }

            // Der Grund steht im Klassenkopf: Ungültiges UTF-8 nimmt die ganze
            // Antwort mit und nicht nur diese eine Datei.
            if (! mb_check_encoding($content, 'UTF-8')) {
                return ['entry' => $entry, 'content' => null, 'binary' => true, 'too_large' => false];
            }

            return ['entry' => $entry, 'content' => $content, 'binary' => false, 'too_large' => false];
        });
    }
}
