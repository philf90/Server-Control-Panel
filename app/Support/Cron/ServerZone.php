<?php

declare(strict_types=1);

namespace App\Support\Cron;

use App\Support\Time\Clock;
use DateTimeZone;
use Throwable;

/**
 * In welcher Zeit rechnet cron — die Zone der **Maschine**.
 *
 * ## Die dritte Zeitzone dieses Panels, und sie war keine Absicht
 *
 * Es gibt hier jetzt drei, und jede beantwortet eine andere Frage:
 *
 * | Zone | Frage | Quelle |
 * |---|---|---|
 * | UTC | Wie wird gespeichert? | überall, unverändert |
 * | Anzeigezone | Was liest der Betreiber? | `settings`, {@see Clock} |
 * | **Zone der Maschine** | Wann feuert cron? | `/etc/localtime` |
 *
 * **`date_default_timezone_get()` beantwortet die dritte Frage nicht.** Es
 * liefert `config('app.timezone')`, und das steht in diesem Projekt fest auf
 * `UTC` — eine Angabe über PHP und nicht über den Rechner. Gemessen am
 * 17. August 2026: Beide sagen hier `UTC`, und genau deshalb fiele der Fehler
 * hier nie auf. Auf einem Server mit `Europe/Berlin` in `/etc/localtime` laufen
 * sie zwei Stunden auseinander, und cron folgt der Datei.
 *
 * > **Eine Zeitzone aus der Konfiguration der Anwendung ist eine Angabe über die
 * > Anwendung und keine über die Uhr, nach der der Server handelt.**
 *
 * Das ist derselbe Fehler wie der aus `docs/60 §11` eine Ebene weiter: Dort
 * verschöbe `CRON_TZ` die Uhr des Jobs und nicht seinen Zeitplan; hier verschöbe
 * eine falsche Zone die gerechnete Fälligkeit und nicht den Lauf.
 *
 * ## Warum eine eigene Klasse und nicht ein Aufruf in `Occurrence`
 *
 * Dieselbe Bauart wie {@see Clock} und `SrvPanel\Agent\Names::fqdn()`: **eine**
 * Stelle beantwortet die Frage. `Names` ist viermal neu erfunden worden, bevor
 * es einen Wächter dafür gab, und die Anzeigezone hat aus demselben Grund
 * `Clock` bekommen. Die Oberfläche wird diese Zone gleich noch einmal brauchen —
 * sie muss an den Zeitplan schreiben, in welcher Zeit er gilt.
 */
final class ServerZone
{
    /** Woran die Zone der Maschine abzulesen ist. */
    private const LINK = '/etc/localtime';

    /** Wo die Zonendateien liegen — der Teil dahinter ist der Name. */
    private const DATABASE = '/usr/share/zoneinfo/';

    /**
     * Einmal je Anfrage genügt.
     *
     * Die Zone eines laufenden Servers ändert sich nicht zwischen zwei Zeilen,
     * und ein `readlink` je Job einer Liste wäre ein Systemaufruf für eine
     * Antwort, die schon dasteht.
     */
    private static ?DateTimeZone $cached = null;

    /**
     * Die Zone, in der cron rechnet.
     *
     * **Der Rückfall ist UTC und nicht die Anzeigezone.** Wenn `/etc/localtime`
     * nicht zu deuten ist, ist die richtige Antwort „ich weiss es nicht" — und
     * die harmloseste Vertretung dafür ist die, in der auch gespeichert wird.
     * Die Anzeigezone zu nehmen hiesse, eine Einstellung des Betreibers für eine
     * Eigenschaft des Servers auszugeben.
     */
    public static function current(): DateTimeZone
    {
        if (self::$cached instanceof DateTimeZone) {
            return self::$cached;
        }

        return self::$cached = self::read() ?? new DateTimeZone('UTC');
    }

    /** Der Name, wie ihn die Oberfläche an den Zeitplan schreibt. */
    public static function name(): string
    {
        return self::current()->getName();
    }

    /** Für Tests, die eine andere Zone unterstellen wollen. */
    public static function forget(): void
    {
        self::$cached = null;
    }

    /**
     * `/etc/localtime` lesen — oder `null`, wenn daraus kein Name wird.
     *
     * Es ist auf allen vier Zielplattformen ein Symlink nach
     * `/usr/share/zoneinfo/…`. Ist es stattdessen eine Kopie — das kommt in
     * Abbildern vor —, steht dort kein Name, und dann ist `null` die ehrliche
     * Antwort.
     */
    private static function read(): ?DateTimeZone
    {
        try {
            $target = @readlink(self::LINK);

            if (! is_string($target)) {
                return null;
            }

            $position = strpos($target, self::DATABASE);

            if ($position === false) {
                return null;
            }

            $name = substr($target, $position + strlen(self::DATABASE));

            return $name === '' ? null : new DateTimeZone($name);
        } catch (Throwable) {
            // Ein Name, den PHP nicht kennt, ist kein Grund, eine Seite nicht
            // zu zeigen — er ist ein Grund, UTC zu nehmen und es dabei zu sagen.
            return null;
        }
    }
}
