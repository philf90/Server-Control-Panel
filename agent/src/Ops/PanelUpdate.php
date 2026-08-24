<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\AptLock;
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
        $unit = AptLock::UNIT_PREFIX.bin2hex(random_bytes(4));

        /*
         * **Die Prüfung ist am 24. August 2026 nach {@see AptLock} gezogen.**
         *
         * Hier stand sie seit P0 — und sie war die einzige im ganzen Agenten,
         * obwohl drei weitere Operationen apt rufen. Sie sah ausserdem nur die
         * eigenen abgesetzten Läufe: Ein `php.version.install` in der
         * Warteschlange kam darin nicht vor, und ein Betreiber mit `apt-get`
         * auf der Kommandozeile schon gar nicht.
         *
         * Der Unitname kommt jetzt aus derselben Konstanten, nach der gesucht
         * wird. Vorher standen die beiden Zeichenketten nebeneinander, und die
         * zweite ist die, die beim Umbenennen stehenbleibt.
         */
        AptLock::ensureFree($context);

        $context->progress(10, 'Update wird abgesetzt');

        @unlink(self::LOG);

        /*
         * **Das `&&` hier ist keine Prüfung, und das steht so lange da, bis
         * Schritt 6 es behebt** (M5, `docs/81 §2.1a` und §9).
         *
         * `apt-get update` endet mit 0, auch wenn keine einzige Quelle
         * geantwortet hat — die alten Listen bleiben liegen. Die rechte Seite
         * läuft also immer. Und mit alten Listen findet `--only-upgrade`
         * nichts Neueres, meldet `0 upgraded` und endet ebenfalls mit 0: Das
         * Panel meldet „Update läuft", die Fassung bleibt stehen, und im
         * Protokoll steht ein erfolgreicher Lauf.
         *
         * **Warum das hier nicht wie in `php.version.install` behoben wird.**
         * Dort liest {@see \SrvPanel\Agent\Apt::refresh()} den `stderr` des
         * Laufs, weil die Operation auf ihn wartet. Dieser Lauf läuft
         * ausdrücklich **ohne** jemanden, der wartet: Er liegt in einer eigenen
         * transienten Unit, damit er den Neustart des Agenten überlebt, und
         * seine Ausgabe geht in {@see self::LOG}. Wer sie hier lesen wollte,
         * müsste auf ein Update warten, das genau diesen Prozess beendet.
         *
         * Die Antwort steht deshalb im Plan an anderer Stelle: **nach dem
         * Neustart die eigene Fassung nachlesen** und melden, wenn sie dieselbe
         * geblieben ist. Das ist Teil 3 von M5 und hängt an Schritt 6 —
         * `AptResultTest` führt diese Stelle bis dahin als benannte Ausnahme,
         * damit sie nicht als erledigt durchgeht.
         *
         * > **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist
         * > keine Prüfung — er ist eine Zeile, die aussieht wie eine.**
         */
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
