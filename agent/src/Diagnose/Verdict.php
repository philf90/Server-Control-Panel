<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Diagnose;

use SrvPanel\Agent\Keys;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Ops\SystemDiagnose;
use SrvPanel\Agent\Result;
use SrvPanel\Agent\Runner;

/**
 * Die Urteile der Bestandsdiagnose — reine Funktionen über das, was ein
 * Werkzeug gesagt hat (A10, `docs/98 §3`).
 *
 * **`FindingLog` und `FindingCheck` stehen hier bewusst ohne `{@see}` und ohne
 * Namensraum.** Pint macht aus einem voll qualifizierten Namen im Dokumentblock
 * einen `use`-Eintrag — und damit hinge diese framework-freie Klasse am Panel.
 * `AgentIndependenceTest` hat genau das am 2. September 2026 gemeldet, beim
 * ersten vollen Lauf nach dem Formatierer. Derselbe Satz wie in `Units.php`:
 *
 * > **Ein Wächter, den man vor dem Formatierer prüft, ist nicht der, der ins
 * > Repo geht.**
 *
 * ## Warum das Urteil vom Aufruf getrennt ist
 *
 * Es gibt in diesem Repo kein Testdoppel für den {@see Runner},
 * und das ist Absicht: `AptResultTest` prüft den **Leser** an einem
 * selbstgebauten {@see Result} mit Rückgabe 0, nicht den Aufruf. Was gemessen
 * werden soll, ist eine Eigenschaft dessen, was zurückkam — derselbe Schnitt
 * wie bei `Units::read()`, `Clusters::parse()` und `Apt::of()`. Die Operation
 * {@see SystemDiagnose} ruft und klebt; hier steht, was
 * die Antwort bedeutet.
 *
 * ## Was die Messrunde vorgibt (`docs/81 §2.3o`)
 *
 * - **M5**: Die drei Prüfer schreiben alles auf `stderr`, auch ihre
 *   Erfolgsmeldung; `sshd -t` schreibt im Erfolgsfall gar nichts. Der Kanal
 *   sagt nichts, es bleibt der Rückgabewert.
 * - **M4**: `syntax is ok` steht bei nginx auch in einem Lauf, der mit `rc=1`
 *   endet — und fehlt im Fall des fehlenden Zertifikats ganz. Diese Zeichenkette
 *   wird hier **nirgends** gelesen; `ValidatorVerdictTest` besteht darauf.
 * - **M10**: `quotaon -p` gibt in jedem gemessenen Zustand `rc=0` zurück, und der
 *   Kanal wechselt mit dem Zustand. Gelesen wird der Wortlaut — ausnahmsweise,
 *   weil er der einzige Träger ist.
 * - **M11**: `repquota` geht grün, sobald die Quotadatei da ist, auch wenn die
 *   Quota aus ist. Das Urteil braucht **beide** Werkzeuge.
 */
final class Verdict
{
    /** Der Grund, der überall „die Prüfung lief nicht" heisst. */
    public const UNREACHABLE = 'unreachable';

    /**
     * Welche Gründe der Agent je Prüfung ausspricht.
     *
     * **Das ist die Naht zum Katalog im Panel** (`FindingCheck` im Panel), und
     * `DiagnoseSeamTest` hält sie: Jeder Grund hier muss dort bekannt sein,
     * sonst wirft {@see FindingLog} beim Schreiben — und
     * zwar nachts, wenn niemand hinsieht. Der Katalog darf mehr kennen als der
     * Agent ausspricht; `block_missing` und `line_missing` entscheidet das
     * Panel, weil nur dort der Sollzustand liegt.
     *
     * @var array<string, list<string>>
     */
    public const REASONS = [
        'web.config' => ['invalid', self::UNREACHABLE],
        'php.config' => ['invalid', self::UNREACHABLE],
        'ssh.config' => ['invalid', self::UNREACHABLE],
        'web.file' => ['missing', 'empty', 'directive_lost', 'guard_missing', self::UNREACHABLE],
        'php.file' => ['missing', 'empty', 'directive_lost', self::UNREACHABLE],
        'block.integrity' => ['begin_without_end', 'end_without_begin', 'duplicate_block', self::UNREACHABLE],
        'quota.state' => ['off', 'not_enforced', self::UNREACHABLE],
        'apt.key' => ['missing', 'expired', 'expiring', self::UNREACHABLE],
    ];

    /**
     * Was ein Prüfer (`nginx -t`, `php-fpm -t`, `sshd -t`) gesagt hat.
     *
     * **Allein der Rückgabewert.** Nicht der Kanal (M5), nicht die Zeichenkette
     * `syntax is ok` (M4). Was der Prüfer sonst noch sagt, gehört ungekürzt in
     * `detail` — für den, der nachsieht — und entscheidet hier nichts.
     *
     * @return null|'invalid' `null` heisst: der Prüfer hat nichts einzuwenden
     */
    public static function validator(Result $result): ?string
    {
        return $result->code === 0 ? null : 'invalid';
    }

    /**
     * Ob die Quota erzwungen wird — aus **beiden** Werkzeugen.
     *
     * | `quotaon -p` sagt | `repquota` | Urteil |
     * |---|---|---|
     * | `is on` | rc 0 | in Ordnung |
     * | `is on` | rc ≠ 0 | `unreachable` — der Leseversuch blieb ohne Antwort |
     * | `is off` / keine Quota | rc ≠ 0 | `off` |
     * | `is off` | **rc 0** | **`not_enforced`** — die Datei liegt, erzwungen wird nichts (M11) |
     * | nichts davon | — | `unreachable` |
     *
     * **Der Rückgabewert von `quotaon -p` wird nicht angesehen.** Er ist in
     * jedem gemessenen Zustand `0` (M10); wer ihn läse, läse eine Zahl ohne
     * Bedeutung. Gelesen werden **beide Kanäle**, weil die Antwort bei
     * gesetzter Mount-Option auf stdout kommt und ohne sie auf stderr.
     *
     * @return null|'off'|'not_enforced'|'unreachable'
     */
    public static function quota(Result $quotaon, Result $repquota): ?string
    {
        $text = $quotaon->stdout."\n".$quotaon->stderr;

        // `user quota on /pfad (/dev/x) is on` — die Benutzerquota, nicht die
        // Gruppen- oder Projektquota daneben; die drei stehen in drei Zeilen.
        if (preg_match('/\buser quota on .+ is (on|off)\b/', $text, $treffer) === 1) {
            $an = $treffer[1] === 'on';
        } elseif (str_contains($text, 'no quota enabled')) {
            // Der Einhängepunkt trägt die Option gar nicht (M10, erste Zeile).
            $an = false;
        } else {
            return self::UNREACHABLE;
        }

        if ($an) {
            return $repquota->successful() ? null : self::UNREACHABLE;
        }

        return $repquota->successful() ? 'not_enforced' : 'off';
    }

    /**
     * Was eine Datei des Panels über sich sagt — da, leer, oder ohne ihre Zusagen.
     *
     * `$content` ist `null`, wenn die Datei nicht zu lesen war; ob das „fehlt"
     * oder „nicht lesbar" heisst, entscheidet der Aufrufer, denn nur er weiss,
     * ob sie da sein müsste. `$lost` kommt aus {@see Statements}: die
     * zugesagten Anweisungen, die nicht mehr als Anweisung dastehen.
     *
     * **`empty` vor `directive_lost`.** Eine leere Datei verliert auch jede
     * Anweisung; gemeldet wird der Zustand, den der Betreiber sieht, wenn er
     * die Datei öffnet — und nicht acht Zeilen darüber, was alles fehlt.
     *
     * @param  list<string>  $lost
     * @return null|array{reason: string, detail: null|string}
     */
    public static function file(?string $content, array $lost): ?array
    {
        if ($content === null) {
            return ['reason' => 'missing', 'detail' => null];
        }

        if (trim($content) === '') {
            return ['reason' => 'empty', 'detail' => null];
        }

        if ($lost !== []) {
            return ['reason' => 'directive_lost', 'detail' => implode("\n", $lost)];
        }

        return null;
    }

    /**
     * Steht die Wache des Wartungsmodus in **jedem** Server-Block der Datei?
     *
     * ## Warum das die Zusage je Anweisungsname nicht kann
     *
     * Gemessen am 5. September 2026: Wird die **ganze** Wache aus einem Block
     * entfernt, meldet `directive_lost` vier fehlende Anweisungen — das reicht.
     * Wird **nur die Zeile mit der ACME-Ausnahme** entfernt, meldet die Zusage
     * **nichts**: `if` steht ja weiterhin dreimal in der Datei.
     *
     * > **Eine Zusage über Anweisungsnamen sieht eine fehlende Zeile nicht,
     * > wenn ihr Name noch anderswo vorkommt.**
     *
     * Und genau diese Zeile ist die teuerste: Ohne sie antwortet die
     * Prüfadresse von ACME während jeder Wartung mit 503, `nginx -t` gibt
     * `rc=0`, und die Zertifikatserneuerung stirbt lautlos (M24).
     *
     * ## Wie verglichen wird
     *
     * Zeile für Zeile gegen {@see Maintenance::guardLines()} — den Sollzustand
     * aus der Vorlage und nicht aus einer zweiten Liste. Verglichen wird ohne
     * Einrückung, weil sie im Block von der Verschachtelung abhängt, und
     * **gezählt** wird gegen die Zahl der Server-Blöcke: Eine Domain mit
     * Zertifikat hat zwei, und eine Wache, die nur in einem steht, ist der
     * Fall, den `MaintenanceGuardTest` am Rendern schon einmal gefunden hat.
     *
     * **Ohne Server-Block kein Urteil.** Eine Datei ohne `server {` ist kaputt
     * oder leer — das sagt {@see self::file()}, und zwar in der Sprache, in der
     * der Betreiber sie sieht.
     *
     * @return null|array{reason: 'guard_missing', detail: string}
     */
    public static function guard(string $content): ?array
    {
        $blocks = preg_match_all('/^\s*server\s*\{/m', $content);

        if ($blocks === false || $blocks === 0) {
            return null;
        }

        /** @var array<string, int> $seen */
        $seen = [];

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                $seen[$trimmed] = ($seen[$trimmed] ?? 0) + 1;
            }
        }

        $missing = [];

        foreach (Maintenance::guardLines() as $expected) {
            if (($seen[$expected] ?? 0) < $blocks) {
                $missing[] = $expected;
            }
        }

        if ($missing === []) {
            return null;
        }

        return [
            'reason' => 'guard_missing',
            'detail' => sprintf(
                "%d Server-Block(e), und diese Zeile(n) der Wache fehlen in mindestens einem:\n%s",
                $blocks,
                implode("\n", $missing),
            ),
        ];
    }

    /**
     * Was die Form eines verwalteten Bereichs bedeutet.
     *
     * Der Zustand kommt aus {@see ManagedBlock::inspect()}.
     * `absent` ist hier **kein** Befund: Ob ein fehlender Bereich fehlen darf,
     * weiss nur, wer den Sollzustand kennt — und der liegt im Panel.
     *
     * @return null|'begin_without_end'|'end_without_begin'|'duplicate_block'
     */
    public static function block(string $state): ?string
    {
        return match ($state) {
            'begin_without_end' => 'begin_without_end',
            'end_without_begin' => 'end_without_begin',
            'duplicate' => 'duplicate_block',
            default => null,
        };
    }

    /**
     * Wie es um die Schlüssel einer Quelle steht — der schlechteste zählt.
     *
     * Eine Quelle kann mehrere Hauptschlüssel führen; ein abgelaufener darunter
     * macht das Update nicht unmöglich, solange ein anderer gilt — aber er
     * gehört gemeldet, bevor es der letzte ist.
     *
     * @param  list<array{expires: null|int}>  $keys  wie {@see Keys::read()} sie liefert
     * @return null|'missing'|'expired'|'expiring'
     */
    public static function key(array $keys, int $now): ?string
    {
        if ($keys === []) {
            return 'missing';
        }

        $worst = null;

        foreach ($keys as $key) {
            $state = Keys::state($key['expires'], $now);

            if ($state === 'expired') {
                return 'expired';
            }

            if ($state === 'soon') {
                $worst = 'expiring';
            }
        }

        return $worst;
    }
}
