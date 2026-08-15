<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Scheme;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Op;

/**
 * Eine Datei, einen Verweis oder einen Baum im Abonnement entfernen.
 *
 * **Die Wurzel des Abonnements steht nicht zur Verfügung.** Sie ist im Chroot
 * das `/`, und ein Kunde, der sein eigenes Abonnement über den Dateimanager
 * abräumt, hat nicht gelöscht, was er wollte, sondern das Verzeichnisschema aus
 * §4.5 zerlegt — inklusive `logs/` und `conf/`, an denen nginx und PHP-FPM
 * hängen. Der Rückbau eines Abonnements ist ein eigener Vorgang mit einer
 * eigenen Berechtigung.
 *
 * **Ein Verzeichnis nur mit ausdrücklicher Zustimmung.** `recursive` ist kein
 * Komfort, sondern die Stelle, an der die Oberfläche fragen muss: Ein
 * versehentlich angeklicktes `httpdocs` ist die teuerste Bewegung, die dieser
 * Dateimanager kennt.
 */
final class FilesRemove implements Op
{
    public static function name(): string
    {
        return 'files.remove';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? null);
        $recursive = ($args['recursive'] ?? false) === true;

        if ($path === '/') {
            throw AgentException::denied('Die Wurzel des Abonnements wird über den Dateimanager nicht entfernt.');
        }

        /*
         * **Vor dem Eintritt in die Sandbox und nicht darin.** Der Kernel weist
         * denselben Vorgang auch ab — aber erst beim abschliessenden `rmdir`,
         * also nachdem `removeTree()` das Verzeichnis leergeräumt hat. Der
         * Kunde bekäme „liess sich nicht vollständig entfernen" und hätte seine
         * Webseite verloren. Die Begründung steht in {@see Scheme}.
         */
        Scheme::protect($path, 'entfernt');

        return $workspace->run(static function () use ($path, $recursive): array {
            $entry = Entry::of($path);

            if ($entry === null) {
                throw new AgentException(AgentException::NOT_FOUND, 'Den Eintrag gibt es nicht.', ['path' => $path]);
            }

            // Ein Verweis wird entfernt und nicht verfolgt — auch dann, wenn er
            // auf ein Verzeichnis zeigt. Sonst nähme das Löschen einer
            // Abkürzung das mit, worauf sie zeigt.
            if ($entry['type'] === 'link' || $entry['type'] === 'file') {
                if (! @unlink($path)) {
                    throw AgentException::denied('Der Eintrag liess sich nicht entfernen.');
                }

                return ['removed' => $entry];
            }

            if (! $recursive) {
                if (! @rmdir($path)) {
                    throw AgentException::badRequest(
                        'Das Verzeichnis ist nicht leer.',
                        ['path' => $path],
                    );
                }

                return ['removed' => $entry];
            }

            // Derselbe Baumlauf wie beim Rückbau — hier aber innerhalb der
            // Sandbox, und deshalb ohne das Zeitfenster aus `docs/50 §3`.
            Filesystem::removeTree($path);

            clearstatcache(true, $path);

            if (Entry::of($path) !== null) {
                throw AgentException::denied('Das Verzeichnis liess sich nicht vollständig entfernen.');
            }

            return ['removed' => $entry];
        });
    }
}
