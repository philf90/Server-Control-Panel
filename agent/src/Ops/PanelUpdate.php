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
         * **Hier stand ein `apt-get update -qq && apt-get install …`, und das
         * `&&` war keine Prüfung** (M5, `docs/81 §2.1a`, vierte Zeile).
         *
         * `apt-get update` endet mit 0, auch wenn keine einzige Quelle
         * geantwortet hat — die alten Listen bleiben liegen, die rechte Seite
         * lief also immer. Und mit alten Listen findet `--only-upgrade` nichts
         * Neueres, meldet `0 upgraded` und endet ebenfalls mit 0: Das Panel
         * meldete „Update läuft", die Fassung blieb stehen, und im Protokoll
         * stand ein erfolgreicher Lauf.
         *
         * **Behoben mit Teil 3 von M5, und nicht durch einen besseren
         * Rückgabewert.** {@see SystemPackagesUpgrade::RUNNER} liest im Modus
         * `panel` die installierte Fassung **vor und nach** dem Lauf und endet
         * ungleich 0, wenn sie dieselbe geblieben ist.
         *
         * > **Wenn die Fassung danach dieselbe ist, ist es gleichgültig,
         * > warum.**
         *
         * Diese eine Frage ersetzt jede Prüfung am Rückgabewert von `apt-get
         * update`: Sie fällt gleich aus, ob eine Quelle tot war, ob die Listen
         * alt waren oder ob es schlicht nichts Neues gibt. Und sie ist an der
         * einzigen Stelle gestellt, die sie stellen kann — in der transienten
         * Unit, die den Neustart des Agenten überlebt. Wer sie hier stellte,
         * wartete auf ein Update, das genau diesen Prozess beendet.
         */
        $result = $context->runner->run('systemd-run', [
            '--unit='.$unit,
            '--collect',
            '--description=Update des Panels',
            '--property=StandardOutput=append:'.self::LOG,
            '--property=StandardError=append:'.self::LOG,
            '--setenv=DEBIAN_FRONTEND=noninteractive',
            SystemPackagesUpgrade::RUNNER,
            'panel',
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
