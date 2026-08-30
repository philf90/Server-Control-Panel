<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Antwort von `systemctl show` in eine Zeile, die eine Oberfläche zeigen kann.
 *
 * Herausgezogen aus {@see Ops\ServiceStatus}, weil das Lesen die eigentliche
 * Arbeit ist und weil ein reiner Leser ohne laufendes systemd geprüft werden
 * kann. Die Formen, an denen er bricht, stehen auf der messenden Maschine
 * gerade nicht da — der Wächter `UnitStateTest` baut sie deshalb selbst.
 *
 * Der Name steht hier bewusst ohne `{@see}` und ohne führenden Rückstrich:
 * Pint macht aus einem voll qualifizierten Namen im Dokumentblock einen
 * `use`-Eintrag, und damit hinge diese framework-freie Klasse an einer
 * Testklasse.
 *
 * ## Was die Messrunde ergeben hat (`docs/89`, 30. August 2026)
 *
 * Gemessen gegen echtes systemd 255 in einer eigenen Namespace, mit je einem
 * eigenen Dienst je Prüfkörper.
 *
 * **Ein Timer beantwortet nur sechs der neun Felder, die es bis heute gab.**
 * `MainPID`, `ExecMainStartTimestamp` und `NRestarts` stehen bei einem `.timer`
 * nicht als leerer Wert in der Ausgabe, sondern gar nicht. Der alte Leser hat
 * sie mit `?? 0` und `?? ''` genommen und daraus wortlos „PID 0, nie
 * neugestartet, nie gestartet" gemacht.
 *
 * > **Ein Timer sah durch `service.status` aus wie ein Dienst, der nie lief —
 * > und nichts an der Antwort sagte, dass die Frage nicht passte.**
 *
 * Deshalb ist `null` hier nicht dasselbe wie `0`: `null` heisst „diese Unit
 * kennt das Feld nicht", `0` heisst „gemessen, und der Wert ist null".
 *
 * ## Der nächste Termin steht in zwei Feldern, und keines sagt allein etwas
 *
 * | Fall | `NextElapseUSecRealtime` | `NextElapseUSecMonotonic` |
 * |---|---|---|
 * | gesund, `OnCalendar` | ein Zeitstempel | `0` |
 * | gesund, `OnBootSec` | **leer** | eine Dauer |
 * | kein Termin | leer | `infinity` |
 *
 * Die zweite Zeile ist die, die eine naheliegende Regel umwirft: „die
 * Realtime-Spalte ist leer" heisst **nicht** „kein Termin" — es ist die Bauart
 * der Panel-Timer unmittelbar nach einem Neustart, wenn `OnBootSec` vor der
 * nächsten Kalenderzeit liegt.
 *
 * > **Zwei Felder, von denen jedes im gesunden Fall leer oder null sein darf,
 * > sagen einzeln nichts — erst das Paar sagt, ob ein Termin existiert.**
 *
 * `ActiveState` taugt dafür nicht: Es steht beim gesunden wie beim kaputten
 * Timer auf `active`. Genau das ist der Satz, der seit dem 19. August in
 * `CLAUDE.md` steht, und er ist seit dieser Messrunde belegt statt behauptet.
 *
 * ## Warum hier kein Datum entsteht
 *
 * Die drei Werte, aus denen {@see self::hasNext()} entscheidet — leer, `0`,
 * `infinity`, eine Dauer —, sind von der Zeitzone unabhängig. Der **Zeitstempel**
 * in der Realtime-Spalte ist es nicht: Gemessen druckt `systemctl` ihn in der
 * Zone des Servers (`TZ=Europe/Berlin` ergab `CEST`), und der Agent setzt
 * `TZ` nicht. `--timestamp=unix` hilft nur zur Hälfte — es erreicht
 * `ExecMainStartTimestamp` und nicht `NextElapseUSecRealtime`.
 *
 * > **Ein Schalter, der das Format der Zeitstempel umstellt, erreicht nicht
 * > jede Eigenschaft, die einen Zeitstempel druckt.** Die Trennlinie ist der
 * > Name: `*Timestamp` hört auf ihn, `*USec*` nicht.
 *
 * Die Frage, an der das Abnahmekriterium von A2 hängt, ist „hat dieser Timer
 * einen nächsten Termin" — und die ist ohne Rechnung und ohne Zeitzone zu
 * beantworten. Das Datum dazu holt der Listenschritt aus
 * `systemctl list-timers --output=json`, wo systemd es selbst als rohe
 * Mikrosekunden ausrechnet.
 *
 * > **Eine Frage, die ohne Rechnung zu beantworten ist, wird nicht an eine
 * > Rechnung gehängt** — sonst nimmt deren Fehlschlag die Antwort mit.
 *
 * **Und `--timestamp=unix` steht bewusst nicht im Aufruf.** Es würde
 * `ExecMainStartTimestamp` als `@1788114934` liefern und damit eindeutig
 * machen — gemessen ist das aber nur gegen systemd 255, und die Zielplattformen
 * fahren 249 bis 257. Lehnt eine ältere Fassung die Option ab, bricht
 * `systemctl` ab, es kommt keine einzige Zeile zurück, und dann meldet **jede**
 * Unit „nicht installiert".
 *
 * > **Eine ungemessene Option, deren Ablehnung den ganzen Aufruf mitnimmt, ist
 * > kein kleines Risiko — sie tauscht ein unscharfes Feld gegen einen
 * > Totalausfall.**
 */
final class Units
{
    /**
     * Was `systemctl show` gefragt wird — für Dienste und Timer zusammen.
     *
     * **Eine Liste und nicht zwei.** Gemessen: Fragt man einen Dienst nach den
     * Timer-Eigenschaften, ist die Rückgabe `0` und die Eigenschaften fehlen
     * einfach in der Ausgabe. Es braucht also weder eine Fallunterscheidung vor
     * dem Aufruf noch eine zweite Operation — die **Form der Antwort** sagt,
     * was für eine Unit da steht.
     */
    public const FIELDS = [
        'Id',
        'Description',
        'LoadState',
        'ActiveState',
        'SubState',
        'UnitFileState',
        'MainPID',
        'ExecMainStartTimestamp',
        'NRestarts',
        'NextElapseUSecRealtime',
        'NextElapseUSecMonotonic',
        'Unit',
    ];

    /**
     * Der Wert, den systemd für „nie" in die monotone Spalte schreibt.
     */
    private const NEVER = 'infinity';

    /**
     * Der Wert, den systemd dort schreibt, wenn der Termin im anderen Feld steht.
     */
    private const OTHER_FIELD = '0';

    /**
     * Eine Zeile aus den Ausgabezeilen von `systemctl show`.
     *
     * @param  string  $unit  Der Name, nach dem gefragt wurde — er bleibt die
     *                        Auskunft, wenn es die Unit nicht gibt und `Id` fehlt.
     * @param  list<string>  $lines
     * @return array<string,mixed>
     */
    public static function read(string $unit, array $lines): array
    {
        $values = self::pairs($lines);
        $kind = self::kind($values['Id'] ?? $unit);
        $timer = $kind === 'timer';

        return [
            'unit' => $unit,
            'kind' => $kind,
            'present' => ($values['LoadState'] ?? 'not-found') !== 'not-found',
            'description' => $values['Description'] ?? '',
            'active_state' => $values['ActiveState'] ?? 'unknown',
            'sub_state' => $values['SubState'] ?? 'unknown',
            'unit_file_state' => $values['UnitFileState'] ?? 'unknown',
            'pid' => self::number($values, 'MainPID'),
            'restarts' => self::number($values, 'NRestarts'),
            'since' => self::text($values, 'ExecMainStartTimestamp'),

            // Nur ein Timer trägt diese drei. Bei allem anderen steht dort
            // `null` und nicht `false` — „hat keinen Termin" und „kann keinen
            // haben" sind zwei Auskünfte, und die Oberfläche muss die zweite
            // nicht als Schaden zeigen.
            'triggers' => $timer ? ($values['Unit'] ?? null) : null,
            'has_next' => $timer ? self::hasNext($values) : null,
        ];
    }

    /**
     * Hat dieser Timer einen nächsten Termin?
     *
     * Beide Felder werden gefragt, weil jedes für sich im gesunden Fall leer
     * oder `0` sein darf — siehe die Tabelle im Kopf dieser Klasse.
     *
     * @param  array<string,string>  $values
     */
    public static function hasNext(array $values): bool
    {
        if (($values['NextElapseUSecRealtime'] ?? '') !== '') {
            return true;
        }

        $monotonic = $values['NextElapseUSecMonotonic'] ?? '';

        return $monotonic !== ''
            && $monotonic !== self::NEVER
            && $monotonic !== self::OTHER_FIELD;
    }

    /**
     * `Schlüssel=Wert` je Zeile.
     *
     * Getrennt wird am **ersten** Gleichheitszeichen: Eine `Description` darf
     * eines enthalten, und `systemctl show` maskiert nichts.
     *
     * @param  list<string>  $lines
     * @return array<string,string>
     */
    private static function pairs(array $lines): array
    {
        $values = [];

        foreach ($lines as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Die Art der Unit — aus der Endung ihres Namens.
     */
    private static function kind(string $id): string
    {
        $punkt = strrpos($id, '.');

        return $punkt === false ? 'other' : substr($id, $punkt + 1);
    }

    /**
     * Eine Zahl, die auch fehlen darf.
     *
     * `null` heisst „diese Unit kennt das Feld nicht" und ist von einer
     * gemessenen Null zu unterscheiden — ein Timer hat keine PID, ein Dienst
     * kann die PID 0 haben, weil er gerade nicht läuft.
     *
     * @param  array<string,string>  $values
     */
    private static function number(array $values, string $key): ?int
    {
        return array_key_exists($key, $values) ? (int) $values[$key] : null;
    }

    /**
     * Eine Zeichenkette, die auch fehlen darf.
     *
     * Der Zeitstempel bleibt, wie systemd ihn druckt — in der Zone des Servers.
     * Ihn hier zu zerlegen hiesse, eine Zone zu raten, die der Agent nicht
     * setzt; wer ihn als Datum braucht, misst vorher auf dem Zielserver, was
     * dort wirklich steht.
     *
     * Eine **leere** Zeichenkette heisst „nie gestartet" und ist eine Auskunft;
     * ein **fehlender** Schlüssel heisst „diese Unit kennt das Feld nicht" und
     * ist die falsche Frage. Beides ergibt `null`, weil die Oberfläche in
     * keinem der beiden Fälle einen Zeitpunkt zeigen kann — was sie
     * unterscheiden muss, steht in `kind`.
     *
     * @param  array<string,string>  $values
     */
    private static function text(array $values, string $key): ?string
    {
        $wert = $values[$key] ?? '';

        return $wert === '' ? null : $wert;
    }
}
