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
     * Der Ton, in dem die Marke gezeichnet wird.
     *
     * Die Farben stehen in `resources/css/app.css` und nicht hier — hier steht
     * nur, welche Bedeutung ein Zustand hat. Ein Hexwert an dieser Stelle wäre
     * derselbe Fehler wie einer in einer Komponente.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Ok => 'good',
            self::Failed, self::Timeout => 'bad',
            self::Skipped => 'muted',
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
