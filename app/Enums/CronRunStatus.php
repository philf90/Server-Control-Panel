<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie ein Lauf ausgegangen ist.
 *
 * **Vier Fälle, und keiner davon lässt sich aus dem Rückgabewert allein
 * ablesen.** Genau deshalb ist das eine Aufzählung und keine Rechnung über
 * `exit_code`:
 *
 * - `Skipped` hat **gar keinen** Rückgabewert. Der Lauf hat nie stattgefunden,
 *   weil der vorige noch lief (Entscheidung 1 des Betreibers, `docs/60 §12`).
 *   Ein `0` an dieser Stelle hiesse „erfolgreich beendet" und wäre das Gegenteil.
 * - `Timeout` kommt als 124 von `timeout(1)` — oder als 137, wenn erst das
 *   `KILL` zehn Sekunden später gewirkt hat. Beide dem Kunden als Zahl
 *   hinzustellen, wo „er lief zu lange" gemeint ist, wäre eine Auskunft, die in
 *   die Irre führt.
 *
 * Die Zuordnung trifft `cron-run` und nicht das Panel: Dort ist bekannt, ob
 * gesperrt war und ob die Frist ablief; hier käme sie nur aus einer Zahl, die
 * mehrere Bedeutungen hat.
 *
 * > **Ein Rückgabewert beantwortet „wie endete es", nicht „was ist passiert".**
 */
enum CronRunStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case Skipped = 'skipped';

    /** Was in der Liste steht. */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'erfolgreich',
            self::Failed => 'fehlgeschlagen',
            self::Timeout => 'Zeit überschritten',
            self::Skipped => 'übersprungen',
        };
    }

    /**
     * Die Spielart der Marke, in der ein Zustand gezeichnet wird.
     *
     * **Die Werte sind die von `.badge` in `resources/css/app.css`** und keine
     * eigene Sprache: `ok`, `warn`, `critical`, `neutral`. Der erste Entwurf
     * hier hiess `good`/`bad`/`muted` — lesbar, und es gab keine einzige
     * CSS-Regel dazu. Die Marken wären farblos geblieben, und nichts hätte es
     * gemeldet ausser einem Blick auf die Seite.
     *
     * > **Ein Name, den man sich ausdenkt, weil er treffender klingt, zeigt auf
     * > nichts — und sieht im Quelltext genauso aus wie einer, der trifft.**
     *
     * Die Farben selbst stehen ausschliesslich in `app.css`; hier steht nur,
     * welche Bedeutung ein Zustand hat.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Failed, self::Timeout => 'critical',
            self::Skipped => 'neutral',
        };
    }

    /**
     * Aus dem, was `cron-run` aufgeschrieben hat — oder `null`.
     *
     * Unbekannte Werte werden nicht zu einem Fehler: In einer Aufzeichnung kann
     * ein Zustand stehen, den eine neuere Fassung des Wrappers kennt und diese
     * nicht. Dieselbe Richtung wie bei {@see Permission::fromStored()} — was
     * nicht zu deuten ist, wird verworfen und bringt nicht den Einlesevorgang
     * zu Fall.
     */
    public static function tryFromStored(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
