<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Läuft gerade ein apt-Lauf? — die eine Stelle, die das beantwortet.
 *
 * ## Warum es diese Klasse gibt
 *
 * `docs/81 §7` führt es als Falle 2: **Zwei apt-Läufe enden in der
 * dpkg-Sperre**, und die Meldung darüber versteht niemand. Gefragt hat das bis
 * zum 24. August 2026 genau eine Operation — {@see Ops\PanelUpdate} —, obwohl
 * drei weitere apt rufen: `php.version.install`, `php.version.remove` und
 * `pg.server.install`.
 *
 * **Und die eine Frage war die falsche.** Sie sah `systemctl list-units
 * srvpanel-update-*` an, also ausschliesslich die eigenen abgesetzten Läufe.
 * Ein `php.version.install` in der Warteschlange kam darin nicht vor — und
 * umgekehrt sah keine der drei Operationen ein laufendes Update.
 *
 * Die Warteschlange rettet dabei nur die halbe Strecke: `queue:work` läuft
 * einspurig, zwei **eingereihte** Vorgänge überholen sich also nicht.
 * `panel.update` setzt seinen Lauf aber über `systemd-run` **ausserhalb** der
 * Warteschlange ab und kehrt sofort zurück; das Panel bedient weiter, während
 * apt lädt. In diesem Fenster ist die Kollision in beiden Richtungen offen.
 *
 * ## Gefragt wird die Sperre selbst und nicht ein Stellvertreter
 *
 * Eine Liste eigener Units beantwortet „läuft einer **von uns**". Gefragt ist
 * aber „ist die Sperre gerade weg" — und die hält auch ein Betreiber, der über
 * SSH `apt-get install` tippt. Deshalb liest diese Klasse `/proc/locks`.
 *
 * **Nicht `flock()`, und das ist gemessen** (24. August 2026): dpkg nimmt eine
 * POSIX-Sperre über `fcntl`, PHPs `flock()` spricht `flock(2)` — auf Linux
 * zwei verschiedene Familien, die einander nicht sehen. Ein Wächter über
 * `flock()` meldete „frei", während apt läuft:
 *
 *     Halter (fcntl F_SETLK auf lock-frontend) aktiv
 *     php -r 'flock(…, LOCK_EX|LOCK_NB)'  ->  gelingt
 *     /proc/locks                          ->  POSIX ADVISORY WRITE 8580 fe:00:242
 *
 * > **Eine Sperre, die man mit dem falschen Werkzeug abfragt, meldet immer
 * > frei.**
 *
 * `/proc/locks` hat drei weitere Vorzüge: Es nimmt selbst keine Sperre (ein
 * Fühler, der sperrt, ist der Fehler, den er sucht), es braucht **kein
 * zusätzliches Programm auf der Positivliste**, und es kann nicht blockieren.
 *
 * ## Zwei Fragen, nicht eine
 *
 * Beide werden gestellt, und sie sind nicht zwei Fassungen derselben:
 *
 * 1. **Hält jemand die Sperre?** (`/proc/locks`) — sieht auch die
 *    Kommandozeile, sieht aber nichts, bevor apt zugegriffen hat.
 * 2. **Ist ein eigener abgesetzter Lauf unterwegs?** (`systemctl`) — sieht auch
 *    das Fenster zwischen `systemd-run` und dem ersten Zugriff von apt, in dem
 *    die Sperre noch frei ist.
 *
 * Wer nur die erste stellt, lässt genau dieses Fenster offen; wer nur die
 * zweite stellt, übersieht den Betreiber auf der Kommandozeile.
 */
final class AptLock
{
    /**
     * Die Sperrdateien von apt und dpkg.
     *
     * Alle vier sind auf allen Zielplattformen vorhanden (`docs/81 §2.1`, M10),
     * und **welche davon gehalten wird, hängt am Unterbefehl**: Gemessen bei
     * `apt-get install` sind es `lock-frontend`, `lock` und `archives/lock`,
     * bei `apt-get update` dagegen `lists/lock`. Eine einzelne Datei zu fragen
     * hiesse, die Hälfte der Läufe nicht zu sehen.
     *
     * @var list<string>
     */
    public const FILES = [
        '/var/lib/dpkg/lock-frontend',
        '/var/lib/dpkg/lock',
        '/var/lib/apt/lists/lock',
        '/var/cache/apt/archives/lock',
    ];

    /**
     * Der Namensanfang der eigenen abgesetzten Läufe.
     *
     * **Als Konstante, weil der Name und die Suche danach auseinanderlaufen
     * können.** In {@see Ops\PanelUpdate} standen sie bis zum 24. August 2026
     * als zwei Zeichenketten nebeneinander — einmal `'srvpanel-update-'.…` beim
     * Bauen, einmal `'srvpanel-update-*'` beim Suchen. Zwei Fassungen
     * derselben Regel, und die zweite ist erfahrungsgemäss die, die beim
     * Umbenennen stehenbleibt.
     */
    public const UNIT_PREFIX = 'srvpanel-update-';

    /** Woran `/proc/locks` gelesen wird. */
    private const LOCK_LINE = '/^\s*\d+:\s+(?:->\s+)?(\S+)\s+\S+\s+\S+\s+(\d+)\s+[0-9a-f]+:[0-9a-f]+:(\d+)\s/';

    /**
     * Die Sperrarten, die mit dem in Konflikt stehen, was apt nimmt.
     *
     * **`FLOCK` steht bewusst nicht dabei.** Es ist die andere Familie und
     * blockiert apt nicht — dieselbe Messung, die oben `flock()` als Fühler
     * ausschliesst, sagt das von der anderen Seite. Eine `FLOCK`-Zeile
     * mitzuzählen ergäbe eine Ablehnung für einen Lauf, der durchgekommen
     * wäre.
     *
     * @var list<string>
     */
    private const CONFLICTING = ['POSIX', 'OFDLCK'];

    /**
     * Abbrechen, wenn gerade ein apt-Lauf läuft.
     *
     * **Der Satz nennt den laufenden Vorgang und nicht die Meldung von dpkg** —
     * das ist Punkt 8 des Abnahmekriteriums (`docs/81 §4`).
     */
    public static function ensureFree(Context $context): void
    {
        $own = self::ownRun($context);

        if ($own !== null) {
            throw AgentException::execFailed(
                'Es läuft bereits ein Lauf des Panels, der Pakete anfasst ('.$own.'). '
                .'Der nächste Versuch geht erst, wenn er fertig ist.',
                ['unit' => $own],
            );
        }

        $holder = self::holder(@file_get_contents('/proc/locks') ?: '', self::inodes());

        if ($holder !== null) {
            throw AgentException::execFailed(
                sprintf(
                    'Ein anderer Vorgang hält gerade die Paketsperre %s (%s, PID %d) — '
                    .'das kann ein Lauf des Panels sein oder ein apt auf der Kommandozeile. '
                    .'Der nächste Versuch geht erst, wenn er fertig ist.',
                    $holder['file'],
                    $holder['program'],
                    $holder['pid'],
                ),
                ['file' => $holder['file'], 'pid' => $holder['pid'], 'program' => $holder['program']],
            );
        }
    }

    /**
     * Wer hält eine der Sperrdateien? — die reine Naht.
     *
     * **Getrennt vom Lesen, damit die Regel ohne laufendes apt prüfbar ist**;
     * derselbe Schnitt wie bei {@see Apt::readFailures()} und
     * `PhpVersions::missing()`.
     *
     * **Zugeordnet wird über den Inode und nicht über Gerät und Inode.** Das
     * Feld in `/proc/locks` ist `major:minor` in hexadezimaler Schreibweise,
     * und die Umrechnung aus dem, was `stat()` liefert, gilt nicht für jede
     * Bauart von `dev_t`. Läge sie daneben, entstünde ein **falsches Negativ**
     * — die Operation liefe los, während apt die Sperre hält. Über den Inode
     * allein kann höchstens das Gegenteil passieren: Eine gleiche Inode-Nummer
     * auf einem anderen Dateisystem ergäbe eine Ablehnung zuviel.
     *
     * > **Wenn eine Zuordnung schiefgehen kann, entscheidet die Richtung, in
     * > die sie schiefgeht.**
     *
     * @param  array<int,string>  $inodes  Inode => Pfad
     * @return null|array{file: string, pid: int, program: string}
     */
    public static function holder(string $procLocks, array $inodes): ?array
    {
        foreach (explode("\n", $procLocks) as $line) {
            if (preg_match(self::LOCK_LINE, $line, $match) !== 1) {
                continue;
            }

            if (! in_array($match[1], self::CONFLICTING, true)) {
                continue;
            }

            $inode = (int) $match[3];

            if (! isset($inodes[$inode])) {
                continue;
            }

            $pid = (int) $match[2];

            return [
                'file' => $inodes[$inode],
                'pid' => $pid,
                'program' => self::program($pid),
            ];
        }

        return null;
    }

    /**
     * Die Inodes der Sperrdateien.
     *
     * Fehlt eine, wird sie ausgelassen und nicht als Fehler behandelt: Auf
     * einem System ohne apt gibt es sie nicht, und diese Klasse ist keine
     * Auskunft darüber, ob apt installiert ist.
     *
     * @return array<int,string>
     */
    private static function inodes(): array
    {
        $inodes = [];

        foreach (self::FILES as $file) {
            $stat = @stat($file);

            if ($stat !== false) {
                $inodes[$stat['ino']] = $file;
            }
        }

        return $inodes;
    }

    /**
     * Wie das Programm hinter einer PID heisst.
     *
     * `/proc/<pid>/comm` und kein `ps`: Es ist eine Datei, kein Programm, und
     * damit kommt die Positivliste des {@see Runner} nicht ins Spiel. Ist der
     * Vorgang zwischen dem Lesen von `/proc/locks` und dieser Zeile fertig
     * geworden, steht dort nichts mehr — dann ist die Sperre vermutlich auch
     * fort, und der nächste Versuch kommt durch.
     */
    private static function program(int $pid): string
    {
        $comm = @file_get_contents('/proc/'.$pid.'/comm');

        return $comm === false || trim($comm) === '' ? 'unbekannt' : trim($comm);
    }

    /**
     * Läuft einer der eigenen abgesetzten Läufe? — der Name der Unit, oder null.
     *
     * Diese Frage kann `/proc/locks` nicht beantworten: `systemd-run` kehrt
     * zurück, bevor apt gestartet ist, und in diesem Fenster ist die Sperre
     * noch frei.
     */
    private static function ownRun(Context $context): ?string
    {
        return self::runningUnit($context->runner->run(
            'systemctl',
            ['list-units', '--plain', '--no-legend', self::UNIT_PREFIX.'*'],
            15,
        ));
    }

    /**
     * Die laufende Unit aus der Ausgabe von `systemctl` — die reine Naht.
     *
     * ## Warum ein fehlgeschlagenes `systemctl` hier abbricht
     *
     * **Derselbe Befund wie M5, nur andersherum, und er stand in genau dem
     * Code, der hierher gezogen ist.** `PanelUpdate` las seit P0 nur
     * {@see Result::lines()}, also `stdout`, und schloss aus einer leeren
     * Ausgabe „es läuft keiner". Gemessen am 24. August 2026 in einem
     * Container ohne systemd:
     *
     *     rc=1 · stdout 0 Bytes
     *     stderr: System has not been booted with systemd as init system
     *
     * Die Frage war damit **nicht beantwortet**, und die alte Fassung
     * antwortete trotzdem — mit „nein", also in die Richtung, die einen
     * kollidierenden Lauf losgehen lässt.
     *
     * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
     * > tun".**
     *
     * Ein `systemctl`, das nicht antwortet, ist auf einer Zielplattform kein
     * Sonderfall, den man überbrücken sollte: Der Agent läuft selbst als
     * systemd-Unit. Wenn diese Frage nicht zu stellen ist, ist die Lage
     * schlimmer als eine belegte Paketsperre.
     *
     * Ein leeres `stdout` bei Rückgabe 0 heisst dagegen wirklich „keine Unit
     * passt auf das Muster" — so meldet `systemctl` einen Treffer ohne
     * Ergebnis.
     */
    public static function runningUnit(Result $units): ?string
    {
        if (! $units->successful()) {
            throw AgentException::execFailed(
                'Ob gerade ein Paketlauf des Panels läuft, liess sich nicht feststellen: '
                .$units->message(),
            );
        }

        foreach ($units->lines() as $line) {
            if (str_contains($line, 'running')) {
                return trim(explode(' ', trim($line))[0]);
            }
        }

        return null;
    }
}
