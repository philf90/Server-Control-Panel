<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Staging;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Sandbox;

/**
 * Eine hochgeladene Datei in das Abonnement übernehmen.
 *
 * **Der Kern dieser Operation ist ein Deskriptor, der ein Chroot überlebt.**
 * Die hochgeladene Datei liegt im Schreibbereich des Panels, also *ausserhalb*
 * der Abo-Wurzel; das Kind der Sandbox kann sie nach dem `chroot` nicht mehr
 * öffnen. Geöffnet wird sie deshalb **vorher**, und die Closure nimmt den
 * offenen Strom mit hinein.
 *
 * > **Ein Deskriptor, der vor dem Chroot geöffnet wurde, gilt danach weiter —
 * > ein Pfad, der vorher galt, nicht.**
 *
 * Das ist derselbe Satz, auf dem der Rückweg der {@see Sandbox}
 * beruht (das Socketpaar entsteht vor dem `fork`), und er ist der Grund, warum
 * hier nichts durch den Arbeitsspeicher des Agenten wandert: Eine 500-MB-Datei
 * würde als Zeichenkette im Ergebnis oder im Argument beides sprengen.
 *
 * **Die Quelle kommt nicht frei vom Aufrufer.** Sie muss unterhalb von
 * {@see Staging::ROOT} liegen — geprüft mit {@see Guard::pathInside()}, also
 * nach Auflösung der Symlinks, dieselbe Bauform wie bei `db.dump.import` seit
 * P5. Ohne diese Schranke wäre das hier eine Operation, die als root einen
 * beliebigen Pfad liest und seinen Inhalt in ein Kundenverzeichnis legt.
 *
 * **Das Ziel dagegen darf frei sein.** Es wird im Chroot gedeutet und kann
 * deshalb nichts ausserhalb bezeichnen — der ganze Unterschied zwischen den
 * beiden Pfaden dieser Operation.
 */
final class FilesUpload implements Op
{
    public static function name(): string
    {
        return 'files.upload';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? null);

        // Die Quelle: nur aus dem Schreibbereich des Panels, und nach
        // Auflösung der Verweise. Ein Symlink dort auf `/etc/shadow` löst
        // sich nach draussen auf und fliegt hier heraus.
        $source = Guard::pathInside($args['source'] ?? null, [Staging::ROOT]);

        if (! is_file($source)) {
            throw new AgentException(AgentException::NOT_FOUND, 'Die hochgeladene Datei ist nicht da.');
        }

        $size = (int) filesize($source);

        // **Vor dem fork geöffnet, und das ist die ganze Mechanik.**
        $handle = @fopen($source, 'rb');

        if ($handle === false) {
            throw AgentException::execFailed('Die hochgeladene Datei liess sich nicht öffnen.');
        }

        try {
            $result = $workspace->run(static function () use ($handle, $path, $size): array {
                $directory = dirname($path);

                if (! is_dir($directory)) {
                    throw new AgentException(AgentException::NOT_FOUND, 'Das Zielverzeichnis gibt es nicht.', [
                        'path' => $directory,
                    ]);
                }

                if (! is_writable($directory)) {
                    throw AgentException::denied('In dieses Verzeichnis darf das Abonnement nicht schreiben.');
                }

                $existing = Entry::of($path);

                if ($existing !== null && $existing['type'] === 'directory') {
                    throw AgentException::badRequest('Dort steht ein Verzeichnis.', ['path' => $path]);
                }

                // Erst daneben, dann umbenennen — wie bei {@see FilesWrite}.
                // Ein Abbruch mitten im Strom hinterlässt sonst eine halbe
                // Datei unter dem richtigen Namen, und die sieht aus wie eine
                // ganze.
                $temporary = $directory.'/.srvpanel-'.bin2hex(random_bytes(8));
                $target = @fopen($temporary, 'wb');

                if ($target === false) {
                    throw AgentException::denied('Die Datei liess sich nicht anlegen.');
                }

                $written = @stream_copy_to_stream($handle, $target);
                @fclose($target);

                // **Verglichen wird mit der erwarteten Länge und nicht mit
                // `false`.** Bei erschöpfter Quota bricht der Strom ab und
                // meldet die Zahl der geschriebenen Bytes; ohne diesen
                // Vergleich hiesse „Kontingent voll" hier „hochgeladen"
                // (`docs/51 §4`, Punkt 12).
                if ($written !== $size) {
                    @unlink($temporary);

                    throw AgentException::execFailed(
                        'Die Datei wurde nur unvollständig übernommen — vermutlich ist das Kontingent erschöpft.',
                        ['written' => $written === false ? 0 : $written, 'expected' => $size],
                    );
                }

                if (! @rename($temporary, $path)) {
                    @unlink($temporary);

                    throw AgentException::denied('Die Datei liess sich nicht an ihren Platz legen.');
                }

                clearstatcache(true, $path);

                return ['entry' => Entry::of($path), 'created' => $existing === null];
            });
        } finally {
            @fclose($handle);
        }

        return $result;
    }
}
