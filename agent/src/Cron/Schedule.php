<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Cron;

use SrvPanel\Agent\AgentException;

/**
 * Die fünf Felder eines Zeitplans — geprüft, bevor sie eine Zeile werden.
 *
 * ## Warum diese Prüfung im Agenten steht und nicht im Formular
 *
 * `docs/60 §9` hat gemessen, was eine kaputte Zeile in `/etc/cron.d` anrichtet,
 * und die Antwort ist schlimmer als erwartet: cron verwirft **die ganze Datei**
 * und sagt es nur seinem Protokoll.
 *
 * ```
 * ERROR (Syntax error, this crontab file will be ignored)
 * ```
 *
 * Bei einer Datei je Abonnement heisst das:
 *
 * > **Eine Datei je Abonnement begrenzt den Schaden auf einen Kunden — und
 * > garantiert ihn dann für alle seine Jobs.**
 *
 * Ein einziger Zeitplan mit einem Fehler schaltet die neun anderen Jobs
 * desselben Kunden mit ab, und im Panel stehen sie weiter als aktiv da. Ein
 * `cron -t` wie `sshd -t` gibt es nicht (gemessen); die Prüfung ist also unsere,
 * und sie gehört an die Stelle, die die Zeile **schreibt** — nicht an die, die
 * sie entgegennimmt. Eine Prüfung im Formular wäre die zweite Fassung, und die
 * zweite ist die, die veraltet.
 *
 * ## Nur Zahlen, kein `JAN`, kein `MON`
 *
 * cron versteht Monats- und Wochentagsnamen. Sie sind hier trotzdem nicht
 * erlaubt, und das ist keine Bequemlichkeit: Das Panel erfasst fünf Felder und
 * baut daraus die Zeile — es gibt also keinen Kunden, der `JAN` tippt, sondern
 * nur einen Weg, auf dem `JAN` hereinkäme, den niemand vorgesehen hat. Was die
 * Oberfläche nicht anbietet, muss die Schranke nicht durchlassen.
 *
 * ## Das Ende ist `\z` und nicht `$`
 *
 * `$` passt in PCRE auch **vor** einem abschliessenden Zeilenumbruch. Für einen
 * Wert, der in eine Zeile einer Konfigurationsdatei wandert, ist das der
 * Unterschied zwischen einer Zeile und zweien — und damit genau die Lücke, gegen
 * die Punkt 9 des Abnahmekriteriums antritt. `AnchoredPatternTest` besteht auf
 * dem Modifikator `D`; hier steht er an jedem Muster.
 */
final class Schedule
{
    /** Die fünf Felder in der Reihenfolge, in der sie in der Zeile stehen. */
    public const FIELDS = ['minute', 'hour', 'day_of_month', 'month', 'day_of_week'];

    /**
     * Die erlaubte Spanne je Feld — untere und obere Grenze, beide gültig.
     *
     * Der Wochentag geht bis **7**, weil cron sowohl `0` als auch `7` für
     * Sonntag nimmt. Wer ihn hier auf 6 begrenzte, wiese eine Eingabe ab, die
     * der Server versteht.
     *
     * @var array<string,array{int,int}>
     */
    private const RANGES = [
        'minute' => [0, 59],
        'hour' => [0, 23],
        'day_of_month' => [1, 31],
        'month' => [1, 12],
        'day_of_week' => [0, 7],
    ];

    /**
     * Wie lang ein einzelnes Feld werden darf.
     *
     * `0,1,2,…,59` sind 168 Zeichen, und mehr braucht kein Feld. Die Grenze
     * steht hier, damit eine Liste aus zehntausend Wiederholungen desselben
     * Werts nicht zu einer Zeile wird, die cron auf andere Art nicht mehr
     * mag — sie ist keine Prüfung der Bedeutung, sondern eine der Grösse.
     */
    private const FIELD_MAX = 192;

    /**
     * Die fünf Felder prüfen und als Zeitplan zurückgeben.
     *
     * @param  array<string,mixed>  $fields
     * @return array{minute: string, hour: string, day_of_month: string, month: string, day_of_week: string}
     *
     * @throws AgentException wenn ein Feld nicht taugt
     */
    public static function parse(array $fields): array
    {
        $schedule = [];

        foreach (self::FIELDS as $name) {
            $value = $fields[$name] ?? null;

            if (! is_string($value) || $value === '') {
                throw AgentException::badRequest(
                    sprintf('Das Feld „%s" des Zeitplans fehlt.', self::label($name)),
                    ['field' => $name],
                );
            }

            $schedule[$name] = self::field($name, $value);
        }

        /** @var array{minute: string, hour: string, day_of_month: string, month: string, day_of_week: string} $schedule */
        return $schedule;
    }

    /**
     * Aus fünf geprüften Feldern die Zeitangabe einer Cron-Zeile.
     *
     * Getrennt wird mit einem einzelnen Leerzeichen. cron nimmt auch
     * Tabulatoren, aber eine Zeile mit zwei Trennzeichenarten liest sich beim
     * nächsten Fehlersuchen wie zwei verschiedene Zeilen.
     *
     * @param  array{minute: string, hour: string, day_of_month: string, month: string, day_of_week: string}  $schedule
     */
    public static function line(array $schedule): string
    {
        return implode(' ', [
            $schedule['minute'],
            $schedule['hour'],
            $schedule['day_of_month'],
            $schedule['month'],
            $schedule['day_of_week'],
        ]);
    }

    /**
     * Ein einzelnes Feld: `*`, eine Zahl, eine Spanne, eine Liste — mit Schritt.
     *
     * Geprüft wird jedes Listenglied einzeln und **gegen die Spanne des Feldes**.
     * Ein `70` in der Minute ist syntaktisch eine Zahl und für cron ein
     * `bad minute`, also derselbe Totalausfall wie ein Buchstabe.
     *
     * @throws AgentException
     */
    private static function field(string $name, string $value): string
    {
        if (strlen($value) > self::FIELD_MAX) {
            throw AgentException::badRequest(
                sprintf('Das Feld „%s" des Zeitplans ist zu lang.', self::label($name)),
                ['field' => $name],
            );
        }

        /*
         * Erst die grobe Form, und zwar über das **ganze** Feld. Sie lässt nur
         * Ziffern, `*`, `,`, `-` und `/` durch — also kein Leerzeichen, keinen
         * Tabulator, keinen Zeilenumbruch und kein `%`. Damit kann aus diesem
         * Wert keine zweite Zeile und keine abgeschnittene werden, ganz gleich,
         * was die Prüfung der Bedeutung darunter noch findet.
         */
        if (preg_match('/\A[0-9*,\/-]+$/D', $value) !== 1) {
            throw AgentException::badRequest(
                sprintf('Das Feld „%s" des Zeitplans enthält unerlaubte Zeichen.', self::label($name)),
                ['field' => $name],
            );
        }

        foreach (explode(',', $value) as $part) {
            self::part($name, $part);
        }

        return $value;
    }

    /**
     * Ein Listenglied: `*`, `n`, `a-b` — jeweils mit `/schritt`.
     *
     * @throws AgentException
     */
    private static function part(string $name, string $part): void
    {
        $step = null;
        $slash = strpos($part, '/');

        if ($slash !== false) {
            $step = substr($part, $slash + 1);
            $part = substr($part, 0, $slash);

            if (preg_match('/\A[0-9]{1,3}$/D', $step) !== 1 || (int) $step < 1) {
                throw AgentException::badRequest(
                    sprintf('Das Feld „%s" des Zeitplans hat einen unbrauchbaren Schritt.', self::label($name)),
                    ['field' => $name],
                );
            }
        }

        if ($part === '*') {
            return;
        }

        [$low, $high] = self::RANGES[$name];

        /*
         * Eine Spanne, und ihre Enden werden einzeln geprüft. `strpos` statt
         * `explode` mit Grenze, damit `1-2-3` nicht als `1` bis `2` durchgeht:
         * Was cron nicht versteht, soll auch hier nicht durchgehen.
         */
        $dash = strpos($part, '-');

        if ($dash !== false) {
            $from = substr($part, 0, $dash);
            $to = substr($part, $dash + 1);

            self::number($name, $from, $low, $high);
            self::number($name, $to, $low, $high);

            if ((int) $from > (int) $to) {
                throw AgentException::badRequest(
                    sprintf('Das Feld „%s" des Zeitplans nennt eine Spanne, die rückwärts läuft.', self::label($name)),
                    ['field' => $name],
                );
            }

            return;
        }

        self::number($name, $part, $low, $high);
    }

    /**
     * Eine einzelne Zahl in der Spanne ihres Feldes.
     *
     * @throws AgentException
     */
    private static function number(string $name, string $value, int $low, int $high): void
    {
        if (preg_match('/\A[0-9]{1,2}$/D', $value) !== 1) {
            throw AgentException::badRequest(
                sprintf('Das Feld „%s" des Zeitplans erwartet eine Zahl.', self::label($name)),
                ['field' => $name],
            );
        }

        $number = (int) $value;

        if ($number < $low || $number > $high) {
            throw AgentException::badRequest(
                sprintf(
                    'Das Feld „%s" des Zeitplans nimmt Werte von %d bis %d.',
                    self::label($name),
                    $low,
                    $high,
                ),
                ['field' => $name],
            );
        }
    }

    /** Der deutsche Name eines Feldes — für die Meldung, die der Kunde liest. */
    private static function label(string $name): string
    {
        return match ($name) {
            'minute' => 'Minute',
            'hour' => 'Stunde',
            'day_of_month' => 'Tag des Monats',
            'month' => 'Monat',
            'day_of_week' => 'Wochentag',
            default => $name,
        };
    }
}
