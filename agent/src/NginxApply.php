<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Konfiguration schreiben, prüfen, übernehmen — und bei Fehlschlag zurück.
 *
 * **Diesen Ablauf gab es schon einmal, in `panel.vhost.apply`.** Dort steht er
 * seit P0 und ist erprobt: schreiben, `nginx -t`, erst dann neu laden, und bei
 * jedem Fehlschlag den vorigen Stand wiederherstellen. Ihn für jede
 * Kundendomain ein zweites Mal hinzuschreiben wäre genau das Muster, das
 * CLAUDE.md als das wiederkehrende beschreibt — mit dem Unterschied, dass die
 * zweite Abschrift dann irgendwann eine Zeile weniger hätte, und die fehlende
 * wäre die mit dem Zurück.
 *
 * **Alle Dateien einer Änderung gehen gemeinsam.** Ein Server-Block und seine
 * Include-Datei sind eine Einheit: Wird nur einer von beiden geschrieben und
 * die Prüfung schlägt fehl, bliebe eine halbe Änderung liegen. Deshalb nimmt
 * `commit()` eine Zuordnung und keine einzelne Datei — und stellt im
 * Fehlerfall **alle** wieder her, auch die gelöschten.
 *
 * **Warum nicht in eine Nebendatei schreiben und erst nach der Prüfung
 * umbenennen.** `nginx -t` prüft die Konfiguration, die auf der Platte liegt,
 * und die bindet das Verzeichnis mit `include` ein. Eine Nebendatei mit der
 * Endung `.conf` wäre damit selbst Teil der Prüfung — geprüft würde die
 * Konfiguration mit beiden Fassungen. Der laufende Server liest von alldem
 * nichts, bis er neu lädt; das ist der Grund, warum an dieser Stelle
 * überhaupt gefahrlos geschrieben werden kann.
 */
final class NginxApply
{
    /**
     * @param  array<string, string>  $writes  Pfad => Inhalt
     * @param  list<string>  $deletes
     */
    public static function commit(Context $context, array $writes, array $deletes = []): void
    {
        $before = [];

        foreach (array_merge(array_keys($writes), $deletes) as $path) {
            $before[$path] = is_file($path) ? (string) file_get_contents($path) : null;
        }

        foreach ($writes as $path => $content) {
            self::write($path, $content);
        }

        foreach ($deletes as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $context->progress(60, 'nginx -t');
        $check = $context->runner->run('nginx', ['-t'], 30);

        if (! $check->successful()) {
            self::restore($before);

            throw AgentException::execFailed('nginx hat die Konfiguration abgelehnt: '.$check->message());
        }

        $context->progress(80, 'nginx neu laden');
        $reload = $context->runner->run('systemctl', ['reload-or-restart', 'nginx.service'], 60);

        if (! $reload->successful()) {
            self::restore($before);

            // Der zweite Versuch gilt dem vorigen Stand: Ohne ihn liefe nginx
            // weiter mit der Konfiguration, die gerade nicht startete.
            $context->runner->run('systemctl', ['reload-or-restart', 'nginx.service'], 60);

            throw AgentException::execFailed('nginx ließ sich nicht neu laden: '.$reload->message());
        }
    }

    /**
     * Eine Datei schreiben, samt Verzeichnis darüber.
     *
     * `0644` und `root:root`: Der Kunde darf seine erzeugte Konfiguration
     * lesen — sie steht ohnehin in seinem Verzeichnis — und niemand ausser
     * root darf sie ändern. Wäre sie für ihn schreibbar, wäre jede Prüfung in
     * {@see Directives} umsonst.
     */
    public static function write(string $path, string $content): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                sprintf('Verzeichnis %s liess sich nicht anlegen.', $directory),
            );
        }

        if (file_put_contents($path, $content) === false) {
            throw AgentException::execFailed(sprintf('%s liess sich nicht schreiben.', $path));
        }

        chmod($path, 0o644);
    }

    /**
     * Die eine Zeile, die das Verzeichnis der Kundendomains einbindet.
     *
     * Sie wird bei jedem Lauf geschrieben, wenn sie fehlt. Ohne sie liegen die
     * Server-Blöcke da und niemand liest sie — die Website antwortet nicht und
     * nichts meldet einen Fehler, weil die Dateien ja alle korrekt sind.
     */
    public static function ensureInclude(): bool
    {
        if (is_file(Site::INCLUDE_FILE)) {
            return false;
        }

        self::write(
            Site::INCLUDE_FILE,
            "# Von srvpanel-agentd erzeugt.\n".
            "# Bindet die Server-Blöcke der Kundenwebsites ein.\n".
            'include '.Site::CONF_DIR."/*.conf;\n",
        );

        return true;
    }

    /** @param array<string, string|null> $before */
    private static function restore(array $before): void
    {
        foreach ($before as $path => $content) {
            if ($content === null) {
                @unlink($path);

                continue;
            }

            @file_put_contents($path, $content);
        }
    }
}
