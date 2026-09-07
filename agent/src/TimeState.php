<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Antwort von `timedatectl show` in einen Zustand, den eine Seite zeigen kann.
 *
 * Ein reiner Leser und deshalb eine eigene Klasse: Der Prüfstand hat kein
 * systemd als PID 1, und die Formen, an denen dieser Leser bricht, stehen dort
 * nicht herum — `TimeStateTest` baut sie selbst, aus den **gemessenen**
 * Ausgaben von `docs/81 §2.3r` und nicht aus erfundenen.
 *
 * Der Name steht hier ohne `{@see}` und ohne führenden Rückstrich: Pint macht
 * aus einem voll qualifizierten Namen im Dokumentblock einen `use`-Eintrag, und
 * damit hinge diese framework-freie Klasse an einer Testklasse.
 *
 * ## Gelesen wird nach Schlüssel und nicht nach Position
 *
 * Die Reihenfolge der Zeilen ist nirgends zugesagt. Ein Leser, der die dritte
 * Zeile nimmt, bricht beim nächsten systemd — und zwar still, weil `no` und
 * `yes` beide gültig aussehen.
 *
 * ## Zwei Felder werden nicht gelesen, und beide aus demselben Grund
 *
 * **`TimeUSec` nicht**, weil die Uhr des Servers die Uhr ist, unter der das
 * Panel selbst läuft; `now()` gibt sie.
 *
 * **`Timezone` nicht**, und das ist ein Befund vom 6. September 2026. Die Frage
 * „in welcher Zone steht dieser Server" beantwortet das Panel seit P6 mit
 * `App\Support\Cron\ServerZone` — cron folgt dem Symlink, und `timedatectl`
 * folgt ihm auch (`docs/81 §2.3r` M11). Es sind also nicht zwei Quellen,
 * sondern zwei Leser derselben, und der zweite wäre der, der veraltet.
 * Gefunden hat es `ServerZoneSourceTest`, den es seit P6 genau dafür gibt.
 *
 * > **Eine Messung, die nach dem Werkzeug sucht, findet die Frage nicht — sie
 * > war schon beantwortet, nur mit einem anderen Werkzeug.**
 *
 * Was hier bleibt, ist das, was **nur** `timedatectl` beantwortet: ob ein
 * Zeitdienst da ist, ob er läuft, ob die Uhr stimmt und wie die Hardware-Uhr
 * gestellt ist.
 *
 * ## Warum vier Wahrheitswerte und nicht zwei
 *
 * Gemessen (`docs/81 §2.3r` M8/M10), in drei Zuständen ohne Neustart:
 *
 * | Zustand | `CanNTP` | `NTP` |
 * |---|---|---|
 * | kein Zeitdienst installiert | `no` | `no` |
 * | installiert, nicht eingeschaltet | `yes` | `no` |
 * | eingeschaltet | `yes` | `yes` |
 *
 * > **Zwei Wahrheitswerte, die vier Zustände tragen, verlieren beim
 * > Zusammenziehen genau den Fall, der eine Meldung verdient.**
 *
 * `NTPSynchronized` ist davon unabhängig: „läuft, aber die Uhr stimmt nicht"
 * ist etwas anderes als „läuft nicht".
 *
 * ## Warum ein unbekannter Wert nicht `false` ergibt
 *
 * Gemessen sind ausschliesslich `yes` und `no`. Stünde dort eines Tages
 * `true`, machte ein Leser mit `=== 'yes'` daraus wortlos „ausgeschaltet" —
 * und meldete einen laufenden Zeitdienst als nicht laufend.
 *
 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
 * > tun".**
 *
 * Deshalb ist ein unbekannter Wert kein Wert, sondern der Grund `incomplete`.
 */
final class TimeState
{
    /**
     * Die geschlossene Menge der Gründe, aus denen der Zustand unlesbar ist.
     *
     * Sie ist geschlossen, weil das Panel sie kennen muss — dieselbe Naht, die
     * `DiagnoseSeamTest` für A10 hält. Der **Wortlaut** von `timedatectl`
     * gehört daneben ins Protokoll des Agenten und nicht in die Seite: Ein
     * Serverzustand, der als Meldung beim Leser ankommt, schickt ihn dorthin,
     * wo nichts zu ändern ist (`docs/59`).
     */
    public const REASONS = [
        // `rc != 0` — ohne systemd als PID 1 ist das der Normalfall, und die
        // Auskunft steht auf stderr (`docs/81 §2.3r` M2/M3).
        'unreadable',

        // `rc = 0`, aber eine der vier Fahnen fehlt oder trägt einen Wert, den
        // dieser Leser nicht kennt. Nie beobachtet — herstellen liess sich der
        // Fall in der Messrunde nicht; ihn zu raten wäre die gefährliche
        // Richtung.
        'incomplete',
    ];

    /**
     * Die Wahrheitswerte — Schlüssel von `timedatectl` => Feld der Antwort.
     *
     * @var array<string,string>
     */
    private const FLAGS = [
        'CanNTP' => 'can_ntp',
        'NTP' => 'ntp',
        'NTPSynchronized' => 'synchronized',
        'LocalRTC' => 'local_rtc',
    ];

    /**
     * Der Zustand der Serverzeit — oder der Grund, warum es keinen gibt.
     *
     * @return array<string,mixed>
     */
    public static function read(Result $result): array
    {
        if (! $result->successful()) {
            return ['readable' => false, 'reason' => 'unreadable'];
        }

        $werte = self::pairs($result->lines());
        $zustand = ['readable' => true];

        foreach (self::FLAGS as $schluessel => $feld) {
            $wert = self::flag($werte, $schluessel);

            if ($wert === null) {
                return ['readable' => false, 'reason' => 'incomplete'];
            }

            $zustand[$feld] = $wert;
        }

        return $zustand;
    }

    /**
     * Schlüssel-Wert-Zeilen in ein Feld.
     *
     * Am **ersten** `=` getrennt: Ein Wert darf eines enthalten, ein Schlüssel
     * nicht.
     *
     * @param  list<string>  $lines
     * @return array<string,string>
     */
    private static function pairs(array $lines): array
    {
        $werte = [];

        foreach ($lines as $zeile) {
            if (! str_contains($zeile, '=')) {
                continue;
            }

            [$schluessel, $wert] = explode('=', $zeile, 2);
            $werte[$schluessel] = $wert;
        }

        return $werte;
    }

    /**
     * `yes` und `no` — und `null` für alles andere, auch für „fehlt".
     *
     * @param  array<string,string>  $values
     */
    private static function flag(array $values, string $key): ?bool
    {
        return match ($values[$key] ?? null) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }
}
