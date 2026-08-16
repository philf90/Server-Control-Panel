<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Ein verwalteter Bereich in einer Datei, die jemand anderem gehört.
 *
 * ## Warum das eine eigene Klasse ist
 *
 * Bis zum 16. August 2026 stand diese Maschinerie in {@see Pg\Hba} und war dort
 * die Hälfte einer Klasse über `pg_hba.conf`. Mit dem SFTP-Zugang bekommt sie
 * einen zweiten Benutzer ({@see Ssh\SshdConfig}), und eine zweite Fassung wäre
 * die, die veraltet — dieselbe Überlegung wie bei {@see Names::fqdn()}, die in
 * diesem Projekt viermal neu erfunden worden ist, bevor es einen Wächter dafür
 * gab.
 *
 * Was hier steht, ist teuer erkauft: Jede der fünf Regeln unten kommt aus einem
 * Fehler, der schon passiert ist.
 *
 * ## Die fünf Regeln
 *
 * **1. Wer schreibt, nimmt die Sperre — und nur einmal.** Der Agent gabelt je
 * Verbindung ({@see Daemon}); zwei Operationen sind zwei Prozesse, und alles,
 * was nur im Speicher steht, sieht der andere nicht. {@see self::locked()} ist
 * die einzige Klammer.
 *
 * **2. Die Sperre liegt neben der Datei, nie auf ihr.** Ein `flock` auf die
 * verwaltete Datei müsste sie zum Schreiben öffnen — und ein `fopen` mit `w`
 * kürzt, bevor irgendjemand das Schloss geprüft hat.
 *
 * **3. Geschrieben wird ganz oder gar nicht.** Über eine Nachbardatei und
 * `rename`, nicht über `file_put_contents`: Das kürzt zuerst und schreibt dann.
 * Eine so entstandene leere `pg_hba.conf` ist syntaktisch **fehlerfrei** und
 * weist jeden ab; eine leere `sshd_config` sperrt aus.
 *
 * **4. Alles ausserhalb der Marken bleibt Byte für Byte stehen.** Was der
 * Betreiber eingetragen hat und was die Distribution mitbringt, geht
 * unverändert durch. Der Bestand ist Gesetz (Leitbild 1).
 *
 * **5. `BEGIN` ohne `END` ist ein Abbruch und keine Reparatur.** Wer die
 * Endmarke von Hand entfernt hat, hat einen Zustand hinterlassen, in dem „bis
 * wohin gehört uns das" keine Antwort hat. Weiterschreiben hiesse raten.
 *
 * ## Und die Marken sind für uns, nicht für den Leser der Datei
 *
 * **Gemessen am 16. August 2026** (`docs/57 §6`): In `sshd_config` gehört eine
 * Zeile hinter einem `Match`-Block noch zu ihm — auch wenn zwischen ihr und dem
 * Block ein `# END srvpanel` steht, denn das ist für sshd ein Kommentar wie
 * jeder andere. Wer hier einen Bereich mit Wirkung auf das Folgende schreibt,
 * beendet ihn mit den Mitteln der *Datei* und nicht mit unserer Marke.
 *
 * > **Eine Endmarke sagt, wo unser Text aufhört. Sie sagt nicht, wo seine
 * > Wirkung aufhört.**
 *
 * ## Was diese Klasse nicht kennt
 *
 * **Den Inhalt.** Sie weiss nicht, was eine Zeile bedeutet, ob sie geprüft
 * werden muss und wie der Dienst sie nachlädt. Das steht bei dem, der die Datei
 * kennt — und es ist je Datei verschieden: PostgreSQL bedient nach einem
 * Neuladen mit kaputter Datei weiter, der sshd terminiert (`docs/57 §5`).
 */
final class ManagedBlock
{
    /**
     * Der Anfang des verwalteten Bereichs.
     *
     * **Zwei Marken und nicht eine**: Der Bereich hat mehrere Zeilen, und ohne
     * Ende wüsste niemand, wo der Bestand wieder anfängt. Die Formulierung wird
     * von `PgHbaReachTest` gegen `docs/38 §14.1` gehalten; wer sie ändert,
     * ändert sie an beiden Stellen.
     */
    public const BEGIN = '# BEGIN srvpanel — verwaltet, nicht von Hand ändern';

    /** Das Ende des Bereichs. */
    public const END = '# END srvpanel';

    /**
     * Diese Datei gehört für die Dauer des Aufrufs genau einem Prozess.
     *
     * **Gehalten wird sie über den ganzen Vorgang**, also über Schreiben,
     * Nachladen, Nachsehen und den Rückweg. Nur so kann der Rückweg den Stand
     * zurücklegen, den er vorgefunden hat: Dazwischen darf sich nichts geändert
     * haben, sonst legt er einen Stand zurück, in dem die Zeile eines anderen
     * Schreibers fehlt.
     *
     * **Und sie ist wiedereintrittsfähig, weil sie es sein muss.** `flock`
     * sperrt je *offener Datei* und nicht je Prozess: Ein zweites `fopen` mit
     * `LOCK_EX` im selben Prozess wartet auf das erste und damit auf sich
     * selbst. Gefunden am 11. August 2026 dadurch, dass die Operation gegen
     * einen echten Cluster lief und nach zwei Minuten noch stand — die
     * Verschachtelung war eine Zeile, die beim Lesen richtig aussah.
     *
     * > **Eine Sperre, die man zweimal nimmt, ist ein Stillstand ohne
     * > Fehlermeldung.**
     *
     * Der Zähler unten macht aus dem inneren Aufruf einen Durchreicher.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function locked(string $path, callable $work): mixed
    {
        if ((self::$held[$path] ?? 0) > 0) {
            self::$held[$path]++;

            try {
                return $work();
            } finally {
                self::$held[$path]--;
            }
        }

        $lock = $path.self::LOCK_SUFFIX;
        $handle = @fopen($lock, 'c');

        if ($handle === false) {
            throw AgentException::execFailed(
                sprintf('Die Sperre für %s liess sich nicht anlegen.', basename($path)),
                ['path' => $lock],
            );
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw AgentException::execFailed(
                    sprintf('Die Sperre für %s liess sich nicht nehmen.', basename($path)),
                    ['path' => $lock],
                );
            }

            self::$held[$path] = 1;

            try {
                return $work();
            } finally {
                unset(self::$held[$path]);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** Die Datei lesen — mit einer Meldung, die den Pfad nennt. */
    public static function read(string $path): string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw AgentException::execFailed(
                sprintf('%s liess sich nicht lesen.', basename($path)),
                ['path' => $path],
            );
        }

        return $content;
    }

    /**
     * Die Datei schreiben — ganz oder gar nicht (Regel 3).
     *
     * **Rechte und Eigentümer werden von der vorhandenen Datei abgenommen** und
     * nicht gesetzt. `pg_hba.conf` gehört auf Debian `postgres:postgres` mit
     * `0640`, `sshd_config` gehört `root:root` mit `0644`; eine Zahl hier wäre
     * eine Behauptung über fremde Paketierung. Dieselbe Überlegung wie bei
     * {@see Pg\Server::hbaFile()}, wo der Pfad erfragt und nicht zusammengesetzt
     * wird.
     */
    public static function put(string $path, string $content): void
    {
        $temporary = $path.'.srvpanel.'.getmypid();
        $stat = @stat($path);

        if (@file_put_contents($temporary, $content) === false) {
            throw AgentException::execFailed(
                sprintf('%s liess sich nicht schreiben.', basename($path)),
                ['path' => $temporary],
            );
        }

        try {
            if (is_array($stat)) {
                @chmod($temporary, $stat['mode'] & 0o7777);
                @chown($temporary, $stat['uid']);
                @chgrp($temporary, $stat['gid']);
            }

            if (! @rename($temporary, $path)) {
                throw AgentException::execFailed(
                    sprintf('%s liess sich nicht ersetzen.', basename($path)),
                    ['path' => $path],
                );
            }
        } catch (AgentException $error) {
            @unlink($temporary);

            throw $error;
        }
    }

    /**
     * Den Bereich setzen — und alles ausserhalb Byte für Byte stehenlassen (Regel 4).
     *
     * **Der Bereich wandert ans Ende, immer.** Auch dann, wenn er vorher weiter
     * oben stand: Dort hätte ihn jemand von Hand hingeschoben. Für
     * `pg_hba.conf` hiesse das, ein `reject` des Betreibers darunter wäre
     * wirkungslos; für `sshd_config` gewänne unser `Match`-Block über seinen,
     * denn dort entscheidet der **erste** passende (`docs/57 §7`).
     *
     * Eine leere Liste entfernt den Bereich — kein leerer Rumpf, der beim
     * nächsten Lesen wie ein Bereich ohne Regeln aussieht.
     *
     * **Der Pfad kommt mit, obwohl hier nichts geschrieben wird.** Er steht in
     * genau einer Meldung: der über ein `BEGIN` ohne `END`. Ohne ihn läse sich
     * die einzige Auskunft, die den Betreiber zur Hand greifen lässt, als „in
     * irgendeiner Datei" — und dieselbe Klasse bedient inzwischen zwei.
     *
     * @param  list<string>  $lines
     */
    public static function render(string $content, array $lines, string $path): string
    {
        $rest = self::without($content, $path);

        if ($lines === []) {
            return $rest;
        }

        if ($rest !== '' && ! str_ends_with($rest, "\n")) {
            $rest .= "\n";
        }

        return $rest."\n".self::BEGIN."\n".implode("\n", $lines)."\n".self::END."\n";
    }

    /**
     * Die Zeilen des Bereichs, so wie sie dastehen.
     *
     * Für den Abgleich gegen den Bestand: Was in der Datei steht, ist der
     * Zustand; eine Liste im Panel daneben wäre die zweite Fassung, und die
     * zweite ist die, die veraltet. **Melden und nicht löschen** —
     * `docs/36 §5`.
     *
     * @return list<string>
     */
    public static function managed(string $content): array
    {
        $inside = false;
        $lines = [];

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if ($trimmed === self::BEGIN) {
                $inside = true;

                continue;
            }

            if ($trimmed === self::END) {
                break;
            }

            if ($inside && $trimmed !== '' && ! str_starts_with($trimmed, '#')) {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    /** Die Endung der Sperrdatei — neben der Datei, nie darauf (Regel 2). */
    private const LOCK_SUFFIX = '.srvpanel.lock';

    /**
     * Wie tief dieser Prozess gerade in {@see self::locked()} steckt, je Pfad.
     *
     * **Nur gegen sich selbst und nie gegen einen anderen Prozess.** Was hier
     * steht, ersetzt kein `flock` — es zählt nur mit, damit der Prozess, der
     * die Sperre schon hat, nicht ein zweites Mal darauf wartet.
     *
     * @var array<string,int>
     */
    private static array $held = [];

    /**
     * Den Bereich herausnehmen — samt der Leerzeile, die ihn abgesetzt hat.
     *
     * **Die Leerzeile gehört dazu, sonst wächst die Datei.** {@see self::render()}
     * setzt vor `BEGIN` genau eine; bliebe sie beim Herausnehmen liegen, käme
     * bei jedem Lauf eine dazu, und nach fünfzig Änderungen stünde der Bereich
     * fünfzig Zeilen unter dem Bestand.
     *
     * **Ein `BEGIN` ohne `END` ist ein Abbruch und keine Reparatur** (Regel 5).
     * Geraten wird an einer Datei nicht, deren Fehler beim nächsten Neustart
     * zündet.
     */
    private static function without(string $content, string $path): string
    {
        $lines = explode("\n", $content);
        $begin = null;
        $end = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($begin === null && $trimmed === self::BEGIN) {
                $begin = $index;

                continue;
            }

            if ($begin !== null && $trimmed === self::END) {
                $end = $index;

                break;
            }
        }

        if ($begin === null) {
            return $content;
        }

        if ($end === null) {
            throw AgentException::execFailed(sprintf(
                'In %s steht ab Zeile %d ein "%s" ohne "%s". Wo der verwaltete Bereich aufhört, '
                .'ist damit nicht zu erkennen — hier schreibt srvpanel nichts, bevor das von Hand '
                .'geklärt ist.',
                basename($path),
                $begin + 1,
                self::BEGIN,
                self::END,
            ));
        }

        $from = $begin > 0 && trim($lines[$begin - 1]) === '' ? $begin - 1 : $begin;

        array_splice($lines, $from, $end - $from + 1);

        return implode("\n", $lines);
    }
}
