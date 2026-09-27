<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\FindingCheck;
use App\Models\Finding;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Wie voll sind die Dateisysteme? — `disk.space` (`docs/136`).
 *
 * ## Warum es diese Prüfung gibt
 *
 * `docs/129 §4` führte „Platte voll" seit P9 als Auslöser von B1, und gebaut
 * war nichts. Die Übersicht färbte einen Balken ab 85 %, und wer nicht
 * hinsah, erfuhr nichts. Gemessen ist, was dann geschieht (`docs/136 §3` M5):
 * MariaDB stürzt beim nächsten Wachsen einer Tabelle ab — und mit ihr das Panel
 * und jede Kundenseite mit Datenbank.
 *
 * > **Ein Alarm, der erst bei voller Platte anschlägt, kann ihn nicht mehr
 * > ablegen.** Die Befunde stehen in derselben Datenbank.
 *
 * ## Die Zahlen sind Entscheidungen des Betreibers (27. September 2026)
 *
 * Warnung ab 85 % — dieselbe Zahl, ab der die Übersicht `tight` färbt, und sie
 * liest sie von hier —, Störung ab 95 %, zurück erst {@see self::RELEASE_POINTS}
 * Punkte darunter. Für Inodes gelten dieselben Zahlen: Sind sie aufgebraucht,
 * scheitert jede neue Datei mit „No space left on device", während der Platz
 * 7,5 % zeigt (M3).
 *
 * ## Der Rückweg steht an den eigenen Befunden
 *
 * Ohne ihn verschwände ein Befund, sobald die Platte einmal unter die Schwelle
 * fällt, und `first_seen_at` begänne beim nächsten Überschreiten neu. Eine
 * Platte, die um 85 % pendelt, stünde dann nie zehn Minuten am Stück da — und
 * meldete gar nicht, obwohl sie dauernd an der Grenze steht.
 *
 * > **Eine Haltezeit ohne Rückweg macht aus einer Platte, die an der Grenze
 * > pendelt, eine, die nie meldet.**
 *
 * Gelesen werden dafür die Befunde **dieses** Schlüssels vom vorigen Lauf.
 * Keine Prüfung liest, was eine andere geschrieben hat (`Catalog`); die eigenen
 * Zeilen vom letzten Mal sind das Gedächtnis dieser hier.
 *
 * ## Was sie nicht kann
 *
 * Sie sagt nicht, **was** die Platte füllt, und nicht, **wann** sie voll sein
 * wird — dafür bräuchte es eine Kurve der Belegung, und der Ringpuffer führt
 * keine. Sie misst den Augenblick, alle fünf Minuten.
 */
final class DiskSpace implements Check
{
    /** Ab hier ist ein Dateisystem eng — für die Prüfung und für die Übersicht. */
    public const WARN_PERCENT = 85;

    /** Ab hier ist es fast voll. */
    public const FAIL_PERCENT = 95;

    /** So weit muss die Belegung unter eine Schwelle fallen, bis ihr Befund geht. */
    public const RELEASE_POINTS = 5;

    /**
     * Die Gründe, die diese Prüfung ausspricht — je Schlüssel.
     *
     * `DiagnoseSeamTest` hält sie gegen `FindingCheck` in beide Richtungen.
     */
    public const REASONS = [
        'disk.space' => ['space_tight', 'space_full', 'inodes_tight', 'inodes_full', FindingCheck::UNREACHABLE],
    ];

    public function __construct(
        private readonly Client $agent,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::DiskSpace];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $vorher = Finding::query()
            ->where('check', FindingCheck::DiskSpace->value)
            ->get()
            ->map(static fn (Finding $f): array => ['subject' => $f->subject, 'reason' => $f->reason])
            ->values()
            ->all();

        try {
            $antwort = $this->agent->call('system.filesystems');
        } catch (AgentException $e) {
            $log->unreachable(FindingCheck::DiskSpace, self::asked($vorher), $measuredAt, $e->getMessage());

            return;
        }

        $platten = $antwort['filesystems'] ?? null;

        if (! is_array($platten)) {
            // Eine Antwort ohne Liste ist keine leere Liste. Sie wie „keine
            // Platte voll" zu lesen, wäre aus „nicht gemessen" ein „alles in
            // Ordnung" gemacht (`docs/44`).
            $log->unreachable(
                FindingCheck::DiskSpace,
                self::asked($vorher),
                $measuredAt,
                'Die Antwort des Agenten trägt keine Liste der Dateisysteme.',
            );

            return;
        }

        $log->replace(FindingCheck::DiskSpace, self::judge(array_values($platten), $vorher), $measuredAt);
    }

    /**
     * Das Urteil — ohne Agent und ohne Datenbank, damit es messbar ist.
     *
     * **Warnung und Störung werden getrennt entschieden**, jede mit ihrer
     * Schwelle und ihrem Rückweg. Bei 96 % stehen deshalb beide da; fällt die
     * Platte auf 89 %, geht die Störung und die Warnung bleibt.
     *
     * **Fehlt die Zahl, fehlt das Urteil.** Eine Platte ohne Inodezahl (btrfs)
     * oder mit einer Antwort, die keine Zahl trägt, bekommt über diesen Teil
     * keinen Befund — „nicht gemessen" ist nicht „0 %".
     *
     * @param  list<mixed>  $platten  die Liste aus `system.filesystems`
     * @param  list<array{subject: string, reason: string}>  $vorher  die eigenen Befunde vom vorigen Lauf
     * @return list<array{subject: string, reason: string, detail: string}>
     */
    public static function judge(array $platten, array $vorher): array
    {
        $stand = [];

        foreach ($vorher as $befund) {
            $stand[$befund['subject']."\0".$befund['reason']] = true;
        }

        $befunde = [];

        foreach ($platten as $platte) {
            if (! is_array($platte) || ! is_string($platte['mount'] ?? null) || $platte['mount'] === '') {
                continue;
            }

            $mount = $platte['mount'];
            $platz = self::percent($platte['percent'] ?? null);

            if ($platz !== null) {
                foreach (self::levels($mount, 'space', $platz, $stand) as $grund) {
                    $befunde[] = [
                        'subject' => $mount,
                        'reason' => $grund,
                        'detail' => sprintf('%s %% belegt.', self::decimal($platz)),
                    ];
                }
            }

            $inodes = $platte['inodes'] ?? null;
            $vergeben = is_array($inodes) ? self::percent($inodes['percent'] ?? null) : null;

            if (is_array($inodes) && $vergeben !== null) {
                foreach (self::levels($mount, 'inodes', $vergeben, $stand) as $grund) {
                    $befunde[] = [
                        'subject' => $mount,
                        'reason' => $grund,
                        'detail' => sprintf(
                            '%s %% der Inodes vergeben, %s frei.',
                            self::decimal($vergeben),
                            number_format(is_int($inodes['free'] ?? null) ? $inodes['free'] : 0, 0, ',', '.'),
                        ),
                    ];
                }
            }
        }

        return $befunde;
    }

    /**
     * Welche Stufen für einen Wert gelten — mit dem Rückweg.
     *
     * @param  array<string, true>  $stand
     * @return list<string>
     */
    private static function levels(string $mount, string $was, float $prozent, array $stand): array
    {
        $gruende = [];

        foreach (['tight' => self::WARN_PERCENT, 'full' => self::FAIL_PERCENT] as $stufe => $schwelle) {
            $grund = $was.'_'.$stufe;
            $standVorher = isset($stand[$mount."\0".$grund]);

            if ($prozent >= $schwelle || ($standVorher && $prozent >= $schwelle - self::RELEASE_POINTS)) {
                $gruende[] = $grund;
            }
        }

        return $gruende;
    }

    /**
     * Die Gegenstände eines Laufs, der nicht messen konnte.
     *
     * **Ein `unreachable` braucht einen Ort** ({@see FindingLog::unreachable()}).
     * Gefragt worden wären alle Platten, und welche das sind, sagt erst die
     * Antwort. Es stehen deshalb die Wurzel da, die es immer gibt, und die
     * Einhängepunkte, zu denen schon ein Befund steht — über genau die weiss der
     * Betreiber gerade nichts Neues.
     *
     * @param  list<array{subject: string, reason: string}>  $vorher
     * @return list<string>
     */
    private static function asked(array $vorher): array
    {
        return array_values(array_unique(['/', ...array_column($vorher, 'subject')]));
    }

    private static function percent(mixed $wert): ?float
    {
        return is_int($wert) || is_float($wert) ? (float) $wert : null;
    }

    private static function decimal(float $wert): string
    {
        return number_format($wert, 1, ',', '.');
    }
}
