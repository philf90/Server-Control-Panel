<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Ein Verzeichnis des Abonnements auflisten.
 *
 * Läuft in der Sandbox, also als der Kunde und im Chroot. Was er nicht lesen
 * darf, sieht er auch hier nicht — die Rechte des Dateisystems sind die
 * Berechtigung, und es gibt keine zweite Liste im Panel, die sie nachbildet.
 * `conf/` gehört `root:root 0755`: sichtbar, lesbar, nicht änderbar
 * (`docs/51 §3`, Entscheidung 5).
 *
 * **Die Obergrenze ist keine Bequemlichkeit.** Ein Verzeichnis mit einer
 * Million Einträgen würde die Antwort über den Rückweg der Sandbox sprengen
 * und den Vorgang mit einer Meldung beenden, die nach einem Fehler des Panels
 * aussieht. Gemeldet wird stattdessen, dass gekürzt wurde.
 */
final class FilesList implements Op
{
    /** Wie viele Einträge höchstens zurückgehen. */
    public const MAX_ENTRIES = 5000;

    public static function name(): string
    {
        return 'files.list';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? '/');

        $result = $workspace->run(static function () use ($path): array {
            if (! is_dir($path)) {
                throw is_file($path) || is_link($path)
                    ? AgentException::badRequest('Das ist kein Verzeichnis.', ['path' => $path])
                    : new AgentException(AgentException::NOT_FOUND, 'Das Verzeichnis gibt es nicht.', ['path' => $path]);
            }

            $names = @scandir($path);

            if ($names === false) {
                throw AgentException::denied('Das Verzeichnis lässt sich nicht lesen.');
            }

            $entries = [];
            $truncated = false;

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if (count($entries) >= self::MAX_ENTRIES) {
                    $truncated = true;

                    break;
                }

                $entry = Entry::of(rtrim($path, '/').'/'.$name);

                // Ein Eintrag, der zwischen scandir und lstat verschwindet, ist
                // kein Fehler — er ist weg, und die Liste zeigt ihn nicht mehr.
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            return ['entries' => $entries, 'truncated' => $truncated];
        });

        return [
            'path' => $path,
            'entries' => $result['entries'],
            'truncated' => $result['truncated'],
            'max_entries' => self::MAX_ENTRIES,
        ];
    }
}
