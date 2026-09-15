<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Packer;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Einen Baum im Abonnement zu einem Zip packen.
 *
 * **Zip und nicht Tar.** Hier stand bis zum 15. September 2026 als Grund,
 * `phar.readonly` erlaube `PharData` nur das Lesen. **Das stimmt nicht**, und
 * die Messrunde vor P8 hat es mit `Phar` als Gegenprobe nachgemessen
 * (`docs/116` M1b): `phar.readonly` sperrt `Phar` — die ausführbare Bauart —
 * und nicht `PharData`. Ein Tar aus PHP zu schreiben ginge.
 *
 * > **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch
 * > dann falsch, wenn der Handgriff daneben richtig ist — und er hält länger
 * > als der Handgriff, weil ihn der Nächste liest und glaubt.**
 *
 * **Die Entscheidung trägt trotzdem, aus einem gemessenen Grund.** Für das,
 * was dieser Vorgang tut — eine Auswahl des Kunden herunterladbar machen —,
 * ist Zip das Format, das jedes Betriebssystem ohne Zutat öffnet. Und
 * `PharData` wäre hier sogar das schlechtere: Es lässt **leere Verzeichnisse
 * ganz fallen** und trägt so wenig Eigentümer und Verweis wie `ZipArchive`
 * (`docs/116` M1b, 1 von 5 Eigenschaften bei beiden, 5 von 5 nur bei `tar(1)`).
 *
 * Wer für P8 ein Archiv sucht, das ein **Abonnement** zurückbringt, findet die
 * Frage dort beantwortet und nicht hier: Dieser Vorgang packt eine Auswahl,
 * keine Sicherung.
 *
 * Entpacken kann beides ({@see FilesExtract}).
 *
 * ## Warum `paths` und nicht `path`
 *
 * Seit der Mehrfachauswahl (P6 Schritt 5h) packt dieser Vorgang **eine Auswahl**
 * und nicht einen Baum. Die naheliegende Alternative wäre gewesen, ihn je Eintrag
 * einmal aufzurufen — das ergäbe aber je Eintrag ein Archiv, und gemeint war
 * eines.
 *
 * Ein einzelner Eintrag ist damit eine Auswahl aus einem. **Ein Weg und nicht
 * zwei**: Zwei Fassungen desselben Vorgangs unterscheiden sich beim nächsten
 * Umbau, und die seltener benutzte ist die, die stehenbleibt.
 */
final class FilesCompress implements Op
{
    public static function name(): string
    {
        return 'files.compress';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $sources = Workspace::paths($args['paths'] ?? null);
        $target = Workspace::path($args['target'] ?? null, 'target');

        foreach ($sources as $source) {
            if ($source === '/') {
                throw AgentException::badRequest('Die Wurzel des Abonnements wird nicht gepackt.');
            }

            /*
             * **Das Archiv darf nicht in dem liegen, was es enthalten soll.**
             *
             * Vor der Mehrfachauswahl war das ein Sonderfall, den man von Hand
             * herbeiführen musste. Jetzt ist es der Normalfall: Wer in einem
             * Verzeichnis alles anhakt und „Als Zip packen" drückt, legt das
             * Archiv genau dort ab — und der Packer läuft dann über eine Datei,
             * die während des Laufs wächst.
             */
            if ($target === $source || str_starts_with($target, rtrim($source, '/').'/')) {
                throw AgentException::badRequest(
                    'Das Archiv kann nicht in einem Verzeichnis liegen, das es selbst enthalten soll.',
                    ['target' => $target, 'path' => $source],
                );
            }
        }

        $context->progress(10, 'packen');

        $result = $workspace->run($context, static function () use ($sources, $target): array {
            foreach ($sources as $source) {
                if (Entry::of($source) === null) {
                    throw new AgentException(AgentException::NOT_FOUND, 'Die Quelle gibt es nicht.', ['path' => $source]);
                }
            }

            if (Entry::of($target) !== null) {
                throw AgentException::badRequest('Am Ziel steht schon etwas.', ['target' => $target]);
            }

            return Packer::zip($sources, $target);
        });

        $context->progress(100, 'fertig');

        return ['paths' => $sources, 'target' => $target] + $result;
    }
}
