<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Apt;
use SrvPanel\Agent\AptLock;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Sources;

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

        $context->progress(10, 'Paketlisten auffrischen');

        @unlink(self::LOG);

        /*
         * **Aufgefrischt wird hier und nicht mehr in `apt-run`** — seit dem
         * 1. September 2026, und das ist die zweite Hälfte von Befund 2 aus
         * `docs/94 §4`.
         *
         * Der Befund lautete: Ein Lauf, bei dem gar nichts anstand, meldet
         * einen Fehlschlag. Er liess sich im Skript **nicht** beheben, und der
         * Grund ist der Satz, der bis heute im Kopf dieser Methode stand:
         *
         *   Diese eine Frage ersetzt jede Prüfung am Rückgabewert von
         *   `apt-get update`: Sie fällt gleich aus, ob eine Quelle tot war, ob
         *   die Listen alt waren oder ob es schlicht nichts Neues gibt.
         *
         * **Genau das ist der Preis, den Befund 2 zurückfordert.** „Es gibt
         * nichts Neues" ist kein Fehlschlag, die anderen beiden sind einer —
         * und apt beantwortet alle drei mit derselben Auskunft, in der
         * Simulation wie im Klartext. Wer in `apt-run` „schon aktuell" gelten
         * liesse, machte aus einer toten Quelle eine grüne Meldung: M5 zurück,
         * eine Ebene höher.
         *
         * > **Eine Unterscheidung, die der Gefragte selbst nicht treffen kann,
         * > muss vor der Frage getroffen werden.**
         *
         * Hier ist sie zu treffen und dort nicht: `Apt` liest die Fehlschläge
         * **je Quelle** (`readFailures()`), und `hitting()` sagt, ob die eigene
         * darunter war. In der transienten Unit steht davon nichts zur
         * Verfügung — ein zweiter Leser in der Shell wäre eine zweite Fassung
         * derselben Regel, mitsamt den drei Fallen, die im Kopf von
         * {@see Apt::readFailures()} stehen.
         *
         * Das Vorbild ist `PhpVersionInstall` und der Wortlaut seiner Meldung
         * mit ihm: Der Betreiber soll an der Quelle suchen und nicht am Paket.
         */
        /*
         * **Zuerst: Ist die eigene Quelle überhaupt in Kraft?**
         *
         * Diese Frage steht **vor** der Auffrischung, und das ist tragend. Eine
         * abgeschaltete Quelle holt apt gar nicht erst; sie erzeugt keinen
         * Fehlschlag, {@see Apt::hitting()} findet nichts, und die Simulation
         * danach sieht mangels neuer Listen nichts Anstehendes. Der Betreiber
         * läse „Es stand nichts an".
         *
         * **Hergeleitet und nicht beobachtet** (`docs/96 §4b`, Befund 14): Der
         * Prüfkörper auf `cloudsrv24` hat den Zustand nie hergestellt.
         *
         * > **Eine Quelle, die nicht gefragt wird, antwortet nicht falsch — sie
         * > fehlt, und das sieht aus wie Zustimmung.**
         *
         * **Zwei Zustände, zwei Meldungen.** „Es gibt keine Datei" und „die
         * Datei ist aus" haben verschiedene Abhilfen, und eine Meldung, die
         * beide nennt, schickt den Leser an zwei Orte.
         */
        if (! is_file(Sources::PANEL_SOURCE)) {
            throw AgentException::execFailed(
                sprintf(
                    'Die Paketquelle des Panels ist nicht eingerichtet: %s fehlt. Ohne sie kann apt '
                    .'keine neue Fassung finden — es wurde deshalb nicht begonnen.',
                    Sources::PANEL_SOURCE,
                ),
                ['source' => Sources::PANEL_SOURCE],
            );
        }

        $uris = Sources::enabledUris(Sources::PANEL_SOURCE);

        if ($uris === []) {
            throw AgentException::execFailed(
                sprintf(
                    'Die Paketquelle des Panels ist abgeschaltet: in %s ist keine eingeschaltete Quelle '
                    .'mit Adresse übrig. Ohne sie kann apt keine neue Fassung finden — es wurde deshalb '
                    .'nicht begonnen.',
                    Sources::PANEL_SOURCE,
                ),
                ['source' => Sources::PANEL_SOURCE],
            );
        }

        $refresh = Apt::refresh($context);

        if (! $refresh->result->successful()) {
            throw AgentException::execFailed('apt-get update ist fehlgeschlagen: '.$refresh->result->message());
        }

        /*
         * **Gefragt wird mit den eingeschalteten Adressen und nicht mit allen.**
         * Eine abgeschaltete Stanza kann keinen Fehlschlag erzeugt haben; sie
         * hier mitzuführen hiesse, in den Meldungen nach einer Quelle zu suchen,
         * die apt nie angefasst hat.
         */
        $unreachable = $refresh->hitting($uris);

        if ($unreachable !== null) {
            throw AgentException::execFailed(
                sprintf(
                    'Die Paketquelle des Panels %s ist nicht erreichbar: %s. Ohne sie kennt apt nur die '
                    .'alten Paketlisten, und ein Update fände nichts Neues — es wurde deshalb nicht '
                    .'begonnen.',
                    $unreachable['base'],
                    $unreachable['reason'],
                ),
                ['source' => $unreachable['base'], 'reason' => $unreachable['reason']],
            );
        }

        /*
         * **Der Betreiber liest das Protokoll und nicht diese Antwort.**
         * `srvpanel update` sieht ausschliesslich in {@see self::LOG} nach, und
         * bis heute stand die Auffrischung dort — sie kam aus `apt-run`. Fiele
         * sie ersatzlos fort, verschwände für ihn ein Schritt, der stattgefunden
         * hat.
         *
         * Ohne Präfix, wie die Fortschrittszeilen davor: `apt-run: ` markiert
         * das Urteil und nichts sonst (Befund 5, `docs/94 §8`).
         */
        @file_put_contents(
            self::LOG,
            'Paketlisten aufgefrischt; jede Quelle hat geantwortet.'."\n",
            FILE_APPEND
        );

        $context->progress(30, 'Update wird abgesetzt');

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
         * Sie ist an der einzigen Stelle gestellt, die sie stellen kann — in
         * der transienten Unit, die den Neustart des Agenten überlebt. Wer sie
         * hier stellte, wartete auf ein Update, das genau diesen Prozess
         * beendet.
         *
         * **Hier stand bis zum 1. September 2026 ein Satz zuviel**, und Befund 2
         * aus `docs/94 §4` hat ihn zurückgenommen:
         *
         *   Diese eine Frage ersetzt jede Prüfung am Rückgabewert von
         *   `apt-get update`: Sie fällt gleich aus, ob eine Quelle tot war, ob
         *   die Listen alt waren oder ob es schlicht nichts Neues gibt.
         *
         * Der erste Halbsatz stimmt, der zweite ist der Fehler: Dass die Frage
         * in allen drei Fällen gleich ausfällt, war als Vorzug aufgeschrieben
         * und ist der Mangel. Der dritte Fall ist kein Fehlschlag, und ein
         * Betreiber, dessen Panel aktuell ist, bekam einen roten Vorgang.
         *
         * > **Ein Verlust an Unterscheidung, den man als Einfachheit
         * > aufschreibt, wird erst dann als Fehler sichtbar, wenn jemand die
         * > Unterscheidung braucht.**
         *
         * Getroffen wird sie jetzt oben, vor dem Absetzen.
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
