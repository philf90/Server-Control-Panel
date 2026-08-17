<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Die aufgezeichneten Läufe einsammeln — und die Ablage dabei leeren.
 *
 * ## Was `cron-run` hinterlässt
 *
 * Je Lauf eine Datei `<nummer>.<pid>.run` unter
 * `/var/spool/srvpanel/cron/<benutzer>/`. Ihr Aufbau ist so gewählt, dass diese
 * Operation sie lesen kann, ohne an der Ausgabe zu scheitern: erst eine Zeile
 * JSON, dann eine Leerzeile, dann die **rohen Bytes** der Ausgabe.
 *
 * ```
 * {"job":1234,"started":"…","duration_ms":8,"exit":0,"status":"ok",…}
 *
 * <alles, was der Befehl geschrieben hat>
 * ```
 *
 * ## Die Kodierungsfalle, und warum sie hier tödlich wäre
 *
 * Die Ausgabe eines Cronjobs sind **beliebige Bytes** — ein Programm, das eine
 * Binärdatei ausgibt, eine kaputte Locale, eine halbe UTF-8-Folge am Schnitt bei
 * 64 KB. `Connection::send()` kodiert die Antwort des Agenten mit
 * `json_encode(… JSON_UNESCAPED_UNICODE)` und **ohne**
 * `JSON_INVALID_UTF8_SUBSTITUTE`.
 *
 * Gemessen am 17. August 2026: `json_encode` gibt bei einem einzigen ungültigen
 * Byte `false` zurück. Damit ist nicht das Feld unlesbar, sondern **die ganze
 * Antwort** — und der Vorgang meldete einen Fehler, dessen Ursache in der
 * Ausgabe eines fremden Programms läge.
 *
 * > **Eine ungültige Folge in einem Feld nimmt die ganze Antwort mit, nicht nur
 * > sich selbst.**
 *
 * Das ist wörtlich der Fund aus `docs/46 §8`, wo ein `BLOB` mit ungültigem UTF-8
 * über `json_decode()` eine ganze Datenbankzeile unlesbar machte. Deshalb wird
 * hier **vor** der Rückgabe geprüft und ersetzt — und das Ersetzen wird gemeldet
 * (`output_lossy`), statt es zu verschweigen:
 *
 * > **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt, behauptet
 * > etwas, das sie nicht weiss.**
 *
 * ## Alles hier ist Kundeneingabe
 *
 * Die Ablage gehört dem Abonnement, damit `cron-run` als der Kunde darin
 * schreiben kann — und **damit kann der Kunde darin auch alles andere
 * schreiben.** Ein Cronjob ist die Erlaubnis, einen Befehl auszuführen; er kann
 * eine `.run`-Datei mit erfundenem Inhalt anlegen, mit einer fremden Jobnummer,
 * mit einer Ausgabe von einem Gigabyte.
 *
 * Deshalb ist jedes Feld hier geprüft und jede Grösse gedeckelt. Und deshalb
 * prüft die **Anwendung** zusätzlich, dass eine gemeldete Jobnummer wirklich zu
 * dem Abonnement gehört, unter dessen Namen sie ankam: Diese Operation kennt den
 * Bestand des Panels nicht und kann das nicht entscheiden.
 *
 * > **Ein Verzeichnis, in das der Geprüfte schreiben darf, liefert keine
 * > Auskunft — es liefert eine Behauptung.**
 *
 * ## Gelesen heisst gelöscht
 *
 * Eine eingesammelte Datei wird entfernt. Das ist „höchstens einmal": Geht die
 * Antwort auf dem Weg zur Datenbank verloren, sind diese Läufe fort. Die
 * Gegenrichtung — erst bestätigen, dann löschen — verlangte einen zweiten
 * Aufruf und eine Erkennung von Doppelungen im Panel, und der Gegenwert wäre
 * eine Protokollzeile. Von zwei Fehlern ist der fehlende Eintrag der bessere;
 * eine Ablage, die nie geleert wird, füllt den Datenträger.
 */
final class CronRuns implements Op
{
    /** Wo `cron-run` ablegt. */
    public const SPOOL_DIR = CronApply::SPOOL_DIR;

    /** Wie viel Ausgabe je Lauf zurückgeht — dieselbe Zahl wie in `cron-run`. */
    public const OUTPUT_MAX = 65536;

    /**
     * Wie viele Läufe ein Aufruf höchstens mitbringt.
     *
     * Ein Minutenjob, der eine Woche lang nicht eingesammelt wurde, hat 10080
     * Dateien. Sie alle in eine Antwort zu legen hiesse, ein halbes Gigabyte
     * durch einen Unix-Socket zu schieben. Der Rest bleibt liegen und kommt beim
     * nächsten Lauf — die Zahl der übrigen steht in der Antwort.
     */
    public const MAX_RUNS = 500;

    /** Wie viele Zustände `cron-run` kennt. */
    private const STATUS = ['ok', 'failed', 'timeout', 'skipped'];

    public static function name(): string
    {
        return 'cron.runs';
    }

    /** Verändert den Zustand: Was eingesammelt ist, wird entfernt. */
    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $users = $this->users($args['users'] ?? null);

        $context->progress(10, 'Ablagen durchsehen');

        $runs = [];
        $remaining = 0;

        foreach ($users as $user) {
            $files = glob(self::SPOOL_DIR.'/'.$user.'/*.run');

            if ($files === false || $files === []) {
                continue;
            }

            /*
             * Älteste zuerst: Wird gedeckelt, sollen die Läufe stehenbleiben,
             * die ohnehin als nächste beschnitten würden — und nicht die, die
             * gerade erst entstanden sind.
             */
            sort($files);

            foreach ($files as $path) {
                if (count($runs) >= self::MAX_RUNS) {
                    $remaining++;

                    continue;
                }

                $run = $this->read($path, $user);

                if ($run !== null) {
                    $runs[] = $run;
                }

                /*
                 * Auch eine Datei, die sich nicht deuten liess, geht weg. Sonst
                 * bliebe sie bei jedem Lauf liegen und würde bei jedem Lauf neu
                 * verworfen — eine Ablage, die sich nicht mehr leert, weil eine
                 * einzige Datei kaputt ist.
                 */
                @unlink($path);
            }
        }

        $context->progress(100, 'fertig');

        return ['runs' => $runs, 'taken' => count($runs), 'remaining' => $remaining];
    }

    /**
     * Eine Aufzeichnung lesen — oder `null`, wenn sie nicht zu deuten ist.
     *
     * @return array<string,mixed>|null
     */
    private function read(string $path, string $user): ?array
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return null;
        }

        /*
         * Gedeckelt gelesen und nicht ganz. `cron-run` kappt bei 64 KB, aber
         * diese Datei liegt in einem Verzeichnis, in das der Kunde schreiben
         * darf — die Zusage des Wrappers ist hier keine.
         */
        $raw = @file_get_contents($path, false, null, 0, self::OUTPUT_MAX + 4096);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $break = strpos($raw, "\n\n");

        if ($break === false) {
            return null;
        }

        /** @var mixed $header */
        $header = json_decode(substr($raw, 0, $break), true);

        if (! is_array($header)) {
            return null;
        }

        $job = $header['job'] ?? null;
        $status = $header['status'] ?? null;

        if (! is_int($job) || $job < 1 || ! is_string($status) || ! in_array($status, self::STATUS, true)) {
            return null;
        }

        $started = $header['started'] ?? null;

        if (! is_string($started) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $started) !== 1) {
            return null;
        }

        [$output, $lossy] = self::encodable(substr($raw, $break + 2));

        return [
            'user' => $user,
            'job' => $job,
            'started' => $started,
            'duration_ms' => max(0, (int) ($header['duration_ms'] ?? 0)),
            'exit' => $status === 'skipped' ? null : (int) ($header['exit'] ?? 0),
            'status' => $status,
            'truncated' => ($header['truncated'] ?? false) === true || strlen($output) >= self::OUTPUT_MAX,
            'output' => $output,
            'output_lossy' => $lossy,
        ];
    }

    /**
     * Aus rohen Bytes ein Feld, das durch `json_encode` passt.
     *
     * **Gekappt wird vor der Prüfung**, denn der Schnitt selbst kann eine
     * gültige Folge in der Mitte zerteilen — und dann wäre die Ausgabe erst
     * durch das Kappen ungültig geworden.
     *
     * **Als reine Funktion und `public`, damit ein Wächter sie ohne Agenten und
     * ohne Dateisystem liest** — dieselbe Bauart wie `Sftp::spokenNote()`. Die
     * Regel, um die es geht, ist an einer Zeichenkette zu prüfen und nicht an
     * einem Lauf; ein Wächter, der dafür einen cron und ein Spool-Verzeichnis
     * bräuchte, liefe nie.
     *
     * @return array{string,bool} der Text und ob dabei etwas ersetzt wurde
     */
    public static function encodable(string $bytes): array
    {
        if (strlen($bytes) > self::OUTPUT_MAX) {
            $bytes = substr($bytes, 0, self::OUTPUT_MAX);
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [$bytes, false];
        }

        return [mb_convert_encoding($bytes, 'UTF-8', 'UTF-8'), true];
    }

    /**
     * Die Systembenutzer, deren Ablagen gelesen werden sollen.
     *
     * Jeder einzeln geprüft — er wird gleich zu einem Pfad, und die Prüfung ist
     * dieselbe wie überall sonst im Agenten.
     *
     * @return list<string>
     */
    private function users(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw AgentException::badRequest('Es ist kein Abonnement genannt.', []);
        }

        $users = [];

        foreach ($value as $entry) {
            $users[] = SubscriptionProvision::systemUser(Guard::string($entry, 'user'));
        }

        return array_values(array_unique($users));
    }
}
