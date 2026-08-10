<?php

declare(strict_types=1);

namespace App\Support\Panel;

use SrvPanel\Agent\Pg\Clusters;

/**
 * Welche Fassung läuft hier — und die Antwort kommt vom Dateisystem.
 *
 * ## Der Anlass: eine Zahl, die seit dem ersten Tag falsch war
 *
 * `config('app.version')` las `env('SRVPANEL_VERSION', '0.1.0-dev')`, und
 * **diese Umgebungsvariable wird nirgends gesetzt** — nicht im Paket, nicht in
 * der Einrichtung, nicht in der `.env`. Das Panel meldete also seit seiner
 * ersten Woche `0.1.0-dev`, und zwar sichtbar: Die Marke im Menü zeigt sie, und
 * der Kommentar dort nennt sie „die erste Frage bei jedem Fehlerbericht".
 *
 * Gefunden am 10. August 2026 beim Testlauf von `v0.5.1-rc.2`, weil
 * `srvpanel --version` die Fassung von *Laravel* nannte und die Frage danach
 * lautete, wo die des Panels steht. Sie stand nirgends.
 *
 * **Es ist derselbe Fehler, den `CLAUDE.md` als den teuersten dieses Projekts
 * führt:** eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein
 * Test oder ein Werkzeug den Bezug prüft. Ein Vorgabewert für eine Variable,
 * die niemand setzt, ist kein Vorgabewert — er ist die Antwort.
 *
 * ## Warum das Verzeichnis und nicht eine Datei
 *
 * Das Paket legt jede Fassung nach `/opt/srvpanel/releases/<fassung>` und
 * setzt `current` als Verweis darauf (`packaging/install.sh`). **Der
 * Verzeichnisname ist damit nicht eine Angabe über die laufende Fassung — er
 * ist sie.** Eine Datei daneben wäre eine zweite Fassung derselben Auskunft,
 * und die zweite ist die, die beim nächsten Update stehen bleibt.
 *
 * Die CI prüft diesen Zusammenhang bereits von der anderen Seite: „Das
 * Verzeichnis der Fassung traegt die Fassung" steht in jedem Installationslauf.
 *
 * **Im Quellbaum gibt es keine Fassung, und dann sagt das hier auch niemand.**
 * `Server-Control-Panel` ist kein Fassungsname; wer aus dem Arbeitsverzeichnis
 * startet, bekommt {@see self::UNRELEASED} statt einer erfundenen Nummer.
 */
final class Release
{
    /**
     * Was dasteht, wenn keine Fassung ausgeliefert ist.
     *
     * **Ein Wort und keine Nummer.** Eine erfundene Zahl — `0.0.0`, `dev`,
     * `unbekannt-1.0` — sähe in einem Fehlerbericht aus wie eine Auskunft und
     * wäre keine. Genau daran ist `0.1.0-dev` zwei Jahre lang vorbeigekommen.
     */
    public const UNRELEASED = 'aus dem Quellbaum';

    /**
     * Die Form eines Fassungsnamens, wie ihn das Paket vergibt.
     *
     * `0.5.1`, `0.5.1-rc.2`, `1.0.0`. Kein `v` davor: Das trägt der Git-Tag,
     * das Verzeichnis nicht. Wer hier lockert, lässt jedes Verzeichnis als
     * Fassung durchgehen — auch `Server-Control-Panel`.
     */
    private const PATTERN = '/^\d+\.\d+\.\d+(-[a-z]+(\.\d+)?)?$/D';

    /**
     * Die laufende Fassung.
     *
     * `base_path()` zeigt auf das Verzeichnis der Anwendung. Auf einem Server
     * ist das `/opt/srvpanel/releases/0.5.1-rc.2` — der Verweis `current` ist
     * dabei schon aufgelöst, weil PHP den Pfad des laufenden Skripts kennt und
     * nicht den des Verweises.
     */
    public static function version(): string
    {
        return self::of(base_path());
    }

    /**
     * Dasselbe aus einem Pfad — getrennt, damit es prüfbar ist.
     *
     * An `base_path()` liesse sich die Regel nur dort prüfen, wo die Anwendung
     * gerade liegt, und das ist im Test immer der Quellbaum. Dieselbe Bauart
     * wie {@see Clusters::parse()}.
     */
    public static function of(string $path): string
    {
        $name = basename(rtrim($path, '/'));

        return preg_match(self::PATTERN, $name) === 1 ? $name : self::UNRELEASED;
    }
}
