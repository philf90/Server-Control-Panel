<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Connection;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Op;

/**
 * Eine Datei des Abonnements schreiben.
 *
 * **Erst daneben, dann umbenennen.** Ein `file_put_contents` direkt auf die
 * Zieldatei lässt sie bei einem Abbruch halb beschrieben zurück — und die halbe
 * Datei ist hier eine halbe `.htaccess` oder eine halbe `wp-config.php`.
 * Geschrieben wird in eine Nachbardatei im selben Verzeichnis (damit `rename`
 * nicht über Dateisystemgrenzen geht) und dann umbenannt; `rename` ist auf
 * demselben Dateisystem atomar.
 *
 * **Die volle Quota ist ein Fehler und keine Erfolgsmeldung.** Das ist Punkt 12
 * des Abnahmekriteriums (`docs/51 §4`), und es steht hier, weil die Sandbox als
 * der Kunde läuft: Sein Kontingent greift von selbst, und der einzige Weg, es
 * zu verschweigen, wäre ein ungeprüfter Rückgabewert.
 *
 * **Und welcher Rückgabewert das ist, stand hier bis zum 18. August falsch.**
 * Gemessen auf `cloudsrv24`: `file_put_contents` gibt bei voller Quota `false`
 * zurück — PHP wandelt den kurzen Schreibvorgang selbst in einen Fehlschlag um
 * und wirft die Zahl der geschriebenen Bytes weg. Der Vergleich unten steht
 * deshalb gegen die *erwartete Länge* und nicht gegen `false`: Er fängt beide
 * Fälle, und welcher davon eintritt, muss er nicht wissen.
 */
final class FilesWrite implements Op
{
    /**
     * Was über diesen Weg höchstens hineingeht. Grösseres kommt als Upload.
     *
     * **Sie ist an den Weg gebunden und keine eigene Zahl mehr.** Bis zum
     * 19. August 2026 standen hier 2 MiB — hinter einer Anfragegrenze von
     * 1 MiB. Eine Datei dazwischen öffnete sich im Editor und liess sich nie
     * speichern; die Meldung sprach vom Protokoll und nicht von der Datei.
     *
     * > **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**
     */
    public const MAX_BYTES = Connection::CONTENT_MAX;

    public static function name(): string
    {
        return 'files.write';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? null);
        $content = (string) ($args['content'] ?? '');

        if (strlen($content) > self::MAX_BYTES) {
            throw AgentException::badRequest('Der Inhalt ist zu gross für diesen Weg.', [
                'max_bytes' => self::MAX_BYTES,
            ]);
        }

        return $workspace->run($context, static function () use ($path, $content): array {
            $existing = Entry::of($path);

            if ($existing !== null && $existing['type'] === 'directory') {
                throw AgentException::badRequest('Dort steht ein Verzeichnis.', ['path' => $path]);
            }

            $directory = dirname($path);

            if (! is_dir($directory)) {
                throw new AgentException(AgentException::NOT_FOUND, 'Das Verzeichnis gibt es nicht.', ['path' => $directory]);
            }

            // **Vorher fragen, damit die Meldung stimmt.** Ohne diese Zeile
            // scheitert erst `file_put_contents`, und der Kunde liest „Die
            // Datei liess sich nicht schreiben" — was nach einem Defekt klingt,
            // wo es eine Rechtefrage ist. `conf/` gehört root, und das ist
            // Absicht (docs/51 §3, Entscheidung 5).
            if (! is_writable($directory)) {
                throw AgentException::denied('In dieses Verzeichnis darf das Abonnement nicht schreiben.');
            }

            $temporary = $directory.'/.srvpanel-'.bin2hex(random_bytes(8));
            $written = @file_put_contents($temporary, $content);

            // **Der Vergleich mit der erwarteten Länge und nicht mit `false`.**
            // Ohne diese Zeile hiesse „Kontingent erschöpft" hier
            // „gespeichert" (docs/51 §4, Punkt 12).
            //
            // **Und eine Meldung und nicht zwei.** Hier standen zwei, verzweigt
            // nach `$written === false`, mit der Begründung, PHP melde bei
            // voller Quota die Zahl der geschriebenen Bytes. Am 18. August 2026
            // auf `cloudsrv24` gemessen: Es meldet **`false`**. PHP wandelt
            // einen kurzen Schreibvorgang selbst in einen Fehlschlag um — es
            // warnt „Only X of Y bytes written, possibly out of free disk
            // space" und gibt `false` zurück. Damit war die Meldung, die das
            // Kontingent nennt, für diesen Weg **unerreichbar**, und der Kunde
            // las „Die Datei liess sich nicht schreiben" — was nach einem
            // Defekt des Servers klingt, wo er Platz schaffen müsste.
            //
            // > **Zwei Meldungen für denselben Fall laufen auseinander — und
            // > die falsche ist die, die man bekommt.**
            if ($written !== strlen($content)) {
                @unlink($temporary);

                throw AgentException::execFailed(
                    'Die Datei wurde nicht vollständig geschrieben — vermutlich ist das Kontingent erschöpft.',
                    // **`null` und nicht `0`.** Bei `false` weiss dieser Weg
                    // nicht, wie viel ankam — PHP kennt die Zahl und gibt sie
                    // nicht heraus. Eine `0` behauptete „nichts geschrieben",
                    // und das ist eine Auskunft, die niemand hat.
                    ['written' => $written === false ? null : $written, 'expected' => strlen($content)],
                );
            }

            // Die Rechte der bestehenden Datei überleben das Ersetzen. Ohne
            // das bekäme jede bearbeitete Datei die Standardrechte, und ein
            // ausführbares Skript wäre nach dem ersten Speichern keines mehr.
            if ($existing !== null) {
                @chmod($temporary, $existing['mode']);
            }

            if (! @rename($temporary, $path)) {
                @unlink($temporary);

                throw AgentException::denied('Die Datei liess sich nicht ersetzen.');
            }

            clearstatcache(true, $path);

            return ['entry' => Entry::of($path), 'created' => $existing === null];
        });
    }
}
