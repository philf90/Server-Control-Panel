<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Das Update des Panels — außerhalb der eigenen Kontrollgruppe.
 *
 * **Der Grund ist eine Lehre aus dem Vorgänger.** Das Update ist die einzige
 * Operation, die den eigenen Prozess beendet. systemd beendet beim Neustart
 * einer Unit deren gesamte Kontrollgruppe — ein apt-Lauf, der darin liefe,
 * würde genau zwischen dem Austausch der Dateien und der Bereitschaftsprüfung
 * abgeschnitten. Zurück bliebe eine halb installierte Fassung ohne jemanden,
 * der sie im Zweifel zurücknimmt.
 *
 * Deshalb setzt der Agent den Lauf über `systemd-run` als eigene transiente
 * Unit ab. Sie überlebt den Neustart von Agent, Web und Worker; die Ausgabe
 * geht in eine Datei, weil eine offene Verbindung ihn nicht überlebt.
 *
 * Die Bereitschaftsprüfung und das Zurücknehmen stecken danach im
 * postinstall-Skript des Pakets — dort, wo der Symlink umgelegt wird.
 */
final class PanelUpdate implements Op
{
    public const LOG = '/var/log/srvpanel/update.log';

    public static function name(): string
    {
        return 'panel.update';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $unit = 'srvpanel-update-'.bin2hex(random_bytes(4));

        // Läuft bereits eines? Zwei apt-Läufe gleichzeitig enden in der
        // dpkg-Sperre, und die Meldung darüber versteht niemand.
        $running = $context->runner->run('systemctl', ['list-units', '--plain', '--no-legend', 'srvpanel-update-*'], 15);

        foreach ($running->lines() as $line) {
            if (str_contains($line, 'running')) {
                throw AgentException::execFailed('Es läuft bereits ein Update.');
            }
        }

        $context->progress(10, 'Update wird abgesetzt');

        @unlink(self::LOG);

        $result = $context->runner->run('systemd-run', [
            '--unit='.$unit,
            '--collect',
            '--property=StandardOutput=append:'.self::LOG,
            '--property=StandardError=append:'.self::LOG,
            '--setenv=DEBIAN_FRONTEND=noninteractive',
            '/bin/sh',
            '-c',
            'apt-get update -qq && apt-get install -y --only-upgrade srvpanel',
        ], 30);

        if (! $result->successful()) {
            throw AgentException::execFailed('Der Update-Lauf ließ sich nicht starten: '.$result->message());
        }

        $context->progress(100, 'läuft');

        return [
            'unit' => $unit,
            'log' => self::LOG,
            // Die Anwendung wird gleich neu gestartet. Wer den Fortschritt
            // sehen will, liest das Protokoll — nicht diese Verbindung.
            'hint' => 'Das Panel startet während des Updates neu.',
        ];
    }
}
