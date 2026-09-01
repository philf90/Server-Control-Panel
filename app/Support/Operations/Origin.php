<?php

declare(strict_types=1);

namespace App\Support\Operations;

/**
 * Von welcher Seite aus der Vorgang ausgelöst wurde — als Pfad.
 *
 * ## Warum es diese Klasse gibt und nicht bloss eine Methode
 *
 * **Weil sie von genau einer Stelle gerufen wird, und die ist das Modell.**
 * Vorgänge entstehen an **sechzehn** Stellen; fünfzehn davon legen ihre Zeile
 * unmittelbar mit `Operation::query()->create()` an. Der erste Wurf setzte die
 * Herkunft in `Operations::dispatch()` — also an einer von sechzehn.
 *
 * Gemessen auf `cloudsrv24` am 31. August 2026 (`docs/94 §6`): Vorgang 727
 * (`system.packages.upgrade`, über `Operations::dispatch()`) trug
 * `← /updates`; Vorgang 729 (`db.dump.create`, über `Dumps::dispatch()`) trug
 * nichts. Beide waren von einer Seite aus ausgelöst worden.
 *
 * > **Ein Wächter, der prüft, dass *eine* Stelle es tut, hat nicht geprüft,
 * > dass es *nur eine* Stelle gibt.**
 *
 * ## Warum am Modell und nicht am Aufrufer
 *
 * Der Unterschied zu `subject_type` ist der Punkt:
 *
 * - `subject_type` weiss **jede Stelle anders** — nur der Aufrufer kennt den
 *   Gegenstand seines Vorgangs. Es gehört an die Stelle.
 * - `origin` ist **überall dasselbe** — die Sitzung weiss es, unabhängig davon,
 *   wer gerade anlegt.
 *
 * > **Was jede Stelle anders weiss, gehört an die Stelle. Was überall dasselbe
 * > ist, gehört an eine — und die muss eine sein, an der niemand vorbeikommt.**
 *
 * Das Modell ist diese Stelle, und es gibt dafür einen Präzedenzfall im selben
 * `booted()`: `subscription_name` wird dort abgeschrieben, mit derselben
 * Begründung. Eine siebzehnte anlegende Stelle bekommt die Herkunft, ohne dass
 * jemand daran denken muss.
 *
 * ## Warum ein Pfad und keine volle Adresse
 *
 * Das Panel ist unter mehreren Namen erreichbar, und eine gespeicherte Adresse
 * mit Rechnernamen wäre unter dem zweiten falsch.
 *
 * ## Warum die Seite sie schickt und nicht die Sitzung sie führt
 *
 * **Bis zum 1. September 2026 stand hier `previousUrl()`, und das war Befund 3
 * aus `docs/94 §5`.** Die Sitzung steht auf der letzten Seite, die das Panel
 * *gerendert* hat. Geht jemand mit dem Zurück-Knopf des Browsers, stellt
 * Inertia aus dem History-Zustand her, es kommt keine Anfrage — und die
 * Herkunft veraltet.
 *
 * Gemessen auf `cloudsrv24`: Vorgang 728 trug `← /operations/727`, obwohl sein
 * Knopf auf `/updates` steht.
 *
 * > **Eine Herkunft, die der Server führt, veraltet bei jeder Navigation, die
 * > der Server nicht sieht.**
 *
 * Geschickt wird sie jetzt von der Seite, über {@see self::HEADER}, aus einem
 * Abfangpunkt in `resources/js/app.ts`. Eine Stelle bleibt eine Stelle — nur
 * steht sie am anderen Ende der Leitung.
 *
 * ## Und deshalb ist die Prüfung strenger geworden
 *
 * Ein Wert aus fremder Hand ist kein selbst gesetzter. Was vorher genügte —
 * `parse_url` und „fängt mit einem Schrägstrich an" — genügt jetzt nicht:
 *
 * > **Eine Prüfung, die für einen selbst gesetzten Wert genügt, genügt nicht
 * > für denselben Wert aus fremder Hand.**
 *
 * Gemessen am 1. September 2026 mit dem URL-Parser, den auch der Browser
 * benutzt, gegen `https://panel.example/`:
 *
 *     /updates                -> panel.example      harmlos
 *     //evil.example/x        -> evil.example       FREMD
 *     /\evil.example/x        -> evil.example       FREMD
 *     /<TAB>/evil.example/x   -> evil.example       FREMD
 *     / /evil.example/x       -> panel.example      harmlos
 *     /%2fevil.example/x      -> panel.example      harmlos
 *
 * Drei Mechanismen, drei Regeln: Der Browser liest `//` als Anfang eines
 * Rechnernamens, er normalisiert `\` zu `/`, und er **entfernt** Tab, LF und
 * CR vor dem Parsen — aus `/<TAB>/x` wird damit `//x`.
 *
 * **`parse_url` hat zwei der drei zufällig entschärft** (es streicht den Host
 * und ersetzt Steuerzeichen durch `_`) und den mittleren durchgelassen. Auf
 * einen solchen Zufall wird hier nichts mehr gebaut: Geprüft wird die
 * Zeichenkette selbst, ohne Umweg.
 *
 * > **Eine Prüfung, die aus einem Nebeneffekt folgt, ist keine — sie ist ein
 * > Zustand, der sich mit der nächsten Fassung ändern darf.**
 */
final class Origin
{
    /**
     * Die längste Herkunft, die gespeichert wird.
     *
     * Sie entspricht der Spaltenbreite. Was länger ist, wird **verworfen und
     * nicht abgeschnitten**: Ein halber Pfad führt irgendwohin, und irgendwohin
     * ist schlechter als nirgendwohin.
     */
    public const MAX = 255;

    /**
     * Die Kopfzeile, in der die Seite ihre eigene Adresse mitschickt.
     *
     * **Die Naht zu `resources/js/app.ts`.** Liefen die beiden auseinander,
     * käme nie eine Herkunft an — und ein Vorgang ohne `←` sieht aus wie einer
     * der Automatik, nicht wie ein Fehler. `OperationOriginTest` hält sie.
     */
    public const HEADER = 'X-Srvpanel-Origin';

    /**
     * Der Pfad, von dem die laufende Anfrage kam — oder `null`.
     *
     * `null` heisst „von keiner Seite" und ist die Wahrheit für die Konsole,
     * die Warteschlange und jeden Lauf der Automatik. Ein Wert, den man dort
     * erfände, sähe aus wie eine Auskunft.
     */
    public static function current(): ?string
    {
        return self::pfad(request()->header(self::HEADER));
    }

    /**
     * Ein mitgeschickter Wert — oder `null`, wenn er keiner ist.
     *
     * **Rein und öffentlich, damit ein Wächter sie mit eigenen Prüfkörpern
     * messen kann.** Die Zeichenketten, an denen sie hängt, stammen aus einer
     * Messung mit dem URL-Parser und nicht aus einer Vorstellung davon, was ein
     * Angreifer schicken könnte.
     */
    public static function pfad(?string $roh): ?string
    {
        if (! is_string($roh) || $roh === '') {
            return null;
        }

        // Zuerst die Länge: Eine Kopfzeile darf beliebig gross sein, und was
        // nicht in die Spalte passt, wird **verworfen und nicht abgeschnitten**
        // — ein halber Pfad führt irgendwohin, und irgendwohin ist schlechter
        // als nirgendwohin.
        if (mb_strlen($roh) > self::MAX) {
            return null;
        }

        // Ein Pfad und keine Adresse: `https://…` und `evil.example/x` fallen
        // hier, ohne dass jemand nach einem Schema suchen muss.
        if (! str_starts_with($roh, '/')) {
            return null;
        }

        // `//host/x` — der Browser liest das zweite Zeichen als Anfang eines
        // Rechnernamens.
        if (str_starts_with($roh, '//')) {
            return null;
        }

        // `\` normalisiert der Browser zu `/`; `/\host/x` ist damit dasselbe
        // wie `//host/x`. In einem Pfad dieses Panels kommt kein einziger vor.
        if (str_contains($roh, '\\')) {
            return null;
        }

        // Tab, LF und CR **entfernt** der Parser vor dem Parsen — aus `/<TAB>/x`
        // wird `//x`. Die übrigen Steuerzeichen gehören ohnehin nicht in eine
        // Kopfzeile.
        if (preg_match('/[\x00-\x1F\x7F]/', $roh) === 1) {
            return null;
        }

        return $roh;
    }
}
