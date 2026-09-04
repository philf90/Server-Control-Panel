<?php

declare(strict_types=1);

namespace App\Support\Time;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Aus UTC wird eine Uhrzeit, die jemand lesen kann.
 *
 * **Der Anlass steht in `docs/40 §1`.** Am 10. August 2026 hat der Betreiber im
 * Protokoll einen Eintrag um `12:31:26` gesehen und gefragt, ob das deutsche
 * Zeit sei. Es war UTC — in der Sommerzeit zwei Stunden Unterschied zu der Uhr,
 * auf die er dabei sah.
 *
 * > **Ein Zeitstempel, den man falsch liest, ist schlimmer als keiner — er
 * > sieht aus wie eine Auskunft.**
 *
 * **Gespeichert wird weiter in UTC, und das ist richtig.** Die Frage war nie,
 * wo die Zeit herkommt, sondern was auf der Seite steht.
 *
 * ## Die einzige Stelle
 *
 * Dieselbe Bauform wie `SrvPanel\Agent\Names::fqdn()`: **eine** Klasse
 * beantwortet die Frage, und ein Wächter wird darauf bestehen — `Names` ist
 * viermal neu erfunden worden, bevor es ihn gab.
 *
 * ## Die Zone kommt vom Server, nicht vom Konto
 *
 * Entscheidung des Betreibers (`docs/40 §3.1`): ein Wert in `settings`, gesetzt
 * vom Admin, für alle Betrachter gleich. Eine Zone je Konto verlangte eine
 * Antwort auf „was sieht ein Konto ohne eigene Wahl" — und die Antwort wäre
 * wieder eine serverweite Einstellung, also dieselbe Sache mit einer zweiten
 * Ebene darüber. Wer sie später will, baut sie auf diese auf.
 *
 * ## Warum sie ihren Wert selbst liest und nicht `Settings` fragt
 *
 * `Settings` liest über den Behälter und wird in Controllern injiziert; `Clock`
 * wird aus achtzehn Stellen statisch gerufen, auch aus Klassen ohne
 * Behälterzugang. Der Wert liegt in derselben Tabelle und wird je Anfrage
 * einmal gelesen — mehr Kopplung wäre hier Aufwand ohne Gegenwert.
 */
final class Clock
{
    /** Der Schlüssel in `settings`. */
    public const KEY = 'display.timezone';

    /**
     * Die Vorgabe, und sie ist UTC.
     *
     * **Nicht die Zone des Servers.** Ein Panel, das seine Anzeige stillschweigend
     * an `date_default_timezone_get()` hängt, ändert sie beim nächsten Umzug —
     * und niemand hat etwas eingestellt, das sich geändert hätte. Wer eine
     * andere Zone will, wählt sie; bis dahin steht auf der Seite, was in der
     * Datenbank steht.
     */
    public const FALLBACK = 'UTC';

    private static ?string $zone = null;

    /**
     * Die eingestellte Zone.
     *
     * **Ein unbekannter Name fällt auf die Vorgabe zurück und wirft nicht.**
     * `setTimezone()` würde bei einem Tippfehler eine Ausnahme werfen — mitten
     * im Aufbau einer Seite, an achtzehn Stellen. Eine Anzeige in UTC ist ein
     * kleiner Schaden; eine Seite, die nicht mehr aufgeht, ist ein grosser.
     * Verhindert wird der Tippfehler beim **Setzen** (siehe
     * {@see self::isValid()}), nicht beim Lesen.
     */
    public static function zone(): string
    {
        if (self::$zone !== null) {
            return self::$zone;
        }

        $stored = Setting::query()->where('key', self::KEY)->value('value');
        $zone = is_array($stored) ? ($stored['zone'] ?? null) : null;

        return self::$zone = is_string($zone) && self::isValid($zone) ? $zone : self::FALLBACK;
    }

    /** Kennt PHP diese Zone? Die Prüfung fürs Formular. */
    public static function isValid(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Was neben einer Zeit steht, damit sie eindeutig ist.
     *
     * `MESZ (UTC+2)` statt `Europe/Berlin`: Der Kürzel wechselt mit der
     * Sommerzeit, und genau das ist die Auskunft, die fehlt, wenn jemand einen
     * Zeitstempel von gestern mit einem von heute vergleicht.
     *
     * **`UTC` steht allein**, und der erste Anlauf hat daraus `UTC (UTC)`
     * gemacht: Die Klammern standen im Formatstring, die Bedingung nur um den
     * Versatz herum. Ein Kürzel, das seinen eigenen Versatz benennt, braucht
     * keinen zweiten daneben — `GMT` bekommt ihn, weil es ihn nicht nennt.
     */
    public static function label(): string
    {
        return self::describe(CarbonImmutable::now(self::zone()));
    }

    /**
     * Dieselbe Angabe, aber für **einen bestimmten Zeitpunkt**.
     *
     * **Der Unterschied ist die Sommerzeit, und er ist kein Randfall.** Berlin
     * heisst im Juli `CEST (UTC+02:00)` und im Januar `CET (UTC+01:00)`. Wer
     * eine Endzeit im Januar setzt und dabei {@see self::label()} danebenlegt,
     * schreibt eine Uhrzeit aus dem Winter mit der Abkürzung des Sommers.
     *
     * > **Eine Zonenangabe, die für „jetzt" gilt, gehört nicht neben einen
     * > Zeitpunkt, der woanders liegt.**
     *
     * `null` bleibt `null`: Ohne Zeitpunkt gibt es nichts zu beschriften.
     */
    public static function labelAt(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        try {
            return self::describe(CarbonImmutable::parse($utc, 'UTC')->setTimezone(self::zone()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Die eine Fassung der Beschriftung — beide Wege gehen hier durch.
     *
     * Stünde sie zweimal da, liefe die zweite irgendwann auseinander; das ist
     * in diesem Repo oft genug bezahlt worden.
     */
    private static function describe(CarbonImmutable $at): string
    {
        $abbreviation = $at->format('T');

        return $abbreviation === 'UTC'
            ? $abbreviation
            : sprintf('%s (UTC%s)', $abbreviation, $at->format('P'));
    }

    /**
     * Eine gespeicherte Zeit als Text für die Oberfläche.
     *
     * `null` bleibt `null` — „noch nie" ist etwas anderes als „vor langer
     * Zeit", und diese Unterscheidung tragen die Seiten selbst.
     */
    public static function display(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    /**
     * Dasselbe für eine Zeit, die schon als Text vorliegt.
     *
     * **Für die Werte in `settings`**, die dort seit jeher als
     * `toDateTimeString()` liegen — sie sind UTC ohne Zonenangabe, und ohne
     * diesen Weg müsste jede Lesestelle selbst parsen. Ein unlesbarer Wert
     * kommt unverändert zurück: Er stammt dann nicht von uns, und ihn zu
     * verwerfen wäre schlimmer, als ihn roh zu zeigen.
     */
    public static function displayText(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        try {
            return self::display(CarbonImmutable::parse($utc, 'UTC'));
        } catch (Throwable) {
            return $utc;
        }
    }

    /**
     * Eine Filtergrenze aus der Anzeigezone nach UTC.
     *
     * **Das ist die Stelle, die ohne Umrechnung still bricht** (`docs/40 §3.2`).
     * Wer abends nach 22:00 Uhr deutscher Zeit „heute" filtert, bekäme sonst
     * einen Tag, der zwei Stunden vorher zu Ende ging — die Seite zeigt eine
     * Zeile, die ihr eigener Filter nicht findet.
     *
     * `$end` unterscheidet die beiden Enden: „Von" meint den Tagesanfang,
     * „Bis" das Tagesende. Beide werden in der **Anzeigezone** gebildet und
     * danach gedreht; anders herum ergäbe sich derselbe Fehler eine Ebene
     * später.
     *
     * Ein unlesbares Datum kommt als `null` zurück — der Aufrufer lässt den
     * Filter dann weg, statt gegen eine erfundene Grenze zu suchen.
     */
    public static function boundaryToUtc(string $date, bool $end): ?string
    {
        try {
            $at = CarbonImmutable::parse($date, self::zone());
        } catch (Throwable) {
            return null;
        }

        return ($end ? $at->endOfDay() : $at->startOfDay())
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Ein Zeitpunkt auf die Minute genau, für ein Eingabefeld.
     *
     * **Dieselbe Richtung wie {@see self::displayText()}, nur ohne Sekunden.**
     * Ein Feld, in das jemand `2026-09-04 16:00` tippt, bekommt seinen eigenen
     * Wert zurück und nicht `16:00:00` — sonst schiene bei jedem Aufruf etwas
     * geändert, was niemand geändert hat.
     *
     * Nicht durch Abschneiden von {@see self::displayText()}: Das wäre eine
     * zweite Regel über dasselbe Format, und die zweite ist die, die veraltet.
     */
    public static function minute(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($utc, 'UTC')->setTimezone(self::zone())->format('Y-m-d H:i');
        } catch (Throwable) {
            return $utc;
        }
    }

    /**
     * Und zurück: ein eingegebener Zeitpunkt aus der Anzeigezone nach UTC.
     *
     * **Die Gegenrichtung zu {@see self::minute()} und aus demselben Grund wie
     * {@see self::boundaryToUtc()}.** Wer „16:00" eingibt, meint 16:00 auf
     * seiner Uhr; ohne diese Drehung stünde der Wert als UTC in der Tabelle und
     * die Anzeige machte daraus 18:00. Das ist die Hälfte, die still bricht —
     * `docs/40 §3.2` hat sie einmal bezahlt.
     *
     * Ein unlesbarer Wert kommt als `null` zurück; der Aufrufer lässt die
     * Angabe dann weg, statt eine erfundene abzulegen.
     */
    public static function minuteToUtc(string $local): ?string
    {
        try {
            return CarbonImmutable::parse($local, self::zone())->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Die Zone setzen.
     *
     * **Geprüft wird hier und nicht beim Lesen** (siehe {@see self::zone()}):
     * Ein unbekannter Name kommt gar nicht erst in die Tabelle, und die
     * Anzeige muss ihn nie behandeln.
     */
    public static function store(string $zone): void
    {
        if (! self::isValid($zone)) {
            throw new InvalidArgumentException('Unbekannte Zeitzone: '.$zone);
        }

        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => ['zone' => $zone]]);

        self::forget();
    }

    /**
     * Den gemerkten Wert vergessen.
     *
     * Für die Tests und für den Augenblick, in dem der Admin die Zone ändert:
     * Ohne das zeigte dieselbe Anfrage noch die alte.
     */
    public static function forget(): void
    {
        self::$zone = null;
    }
}
