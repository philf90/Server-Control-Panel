<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die PHP-Versionen, die das Panel kennt — und alles, was zu einer gehört.
 *
 * **`docs/23 §7` nennt drei Zusagen je Version: eine FPM-Vorlage, einen
 * Paketnamen und einen Handler.** Sie stehen ab hier an einer Stelle statt an
 * dreien, und ein Test hält sie zusammen: Wer eine Version in den Katalog
 * schreibt, ohne die drei mitzuliefern, bekommt einen roten Lauf und keinen
 * Kunden, dessen Website nichts ausliefert.
 *
 * **Der Katalog liegt im Agenten, nicht im Panel.** `Quota::PHP_VERSIONS` im
 * Panel zeigt auf diese Liste. Umgekehrt ginge es nicht: Der Agent glaubt dem
 * Panel nichts, und eine Versionsangabe, die von dort käme, wäre ein Wert aus
 * einer Anfrage, aus dem ein Paketname und ein Dateipfad würden.
 *
 * **Der Sockel trägt den Systembenutzer, nicht den Namen des Abonnements.**
 * Ein Abonnement darf 63 Zeichen heissen, ein Unix-Sockel-Pfad aber nur 108
 * Bytes lang sein — mit dem Namen im Pfad wäre das eine Grenze, die erst beim
 * langen Namen zubeisst, und dann mit einer Meldung über einen abgeschnittenen
 * Pfad. `p1001` ist kurz, eindeutig und ohnehin schon vergeben.
 */
final class PhpVersions
{
    /**
     * Die Versionen. Für jede gibt es Vorlage, Paket und Handler.
     *
     * 8.1 ist die älteste: Ubuntu 22.04 bringt sie mit, und ältere sind seit
     * Ende 2023 ohne Sicherheitsunterstützung. Eine Version aufzunehmen, für
     * die es keine Aktualisierungen mehr gibt, hiesse, sie einem Kunden
     * anzubieten, der sie dann jahrelang betreibt.
     *
     * @var list<string>
     */
    public const CATALOG = ['8.1', '8.2', '8.3', '8.4'];

    /**
     * Die Erweiterungen, die mit jeder Version installiert werden.
     *
     * Zusammen mit {@see self::CATALOG} ist das die ganze Freiheit, die
     * `apt-get` bekommt: Der Paketname entsteht aus zwei Positivlisten und
     * wird nie übergeben. `php8.2-mysql` wird gebaut, nicht entgegengenommen.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        'fpm', 'mysql', 'pgsql', 'xml', 'mbstring', 'curl', 'gd',
        'zip', 'intl', 'bcmath', 'opcache', 'readline', 'soap',
    ];

    /**
     * Wo die Konfiguration der Distribution liegt.
     *
     * Als Vorgabe und nicht als feste Zeichenkette, damit ein Test die Pfade
     * in einem Sandkasten prüfen kann — **ohne sie dort ein zweites Mal zu
     * bauen.** Ein Test, der `/tmp/.../fpm/pool.d/srvpanel-p1001.conf` selbst
     * zusammensetzt, prüft seine eigene Formel und nicht die des Agenten.
     */
    public const PHP_ROOT = '/etc/php';

    /** Prüft eine Versionsangabe gegen den Katalog. */
    public static function normalize(mixed $value, string $field = 'php_version'): string
    {
        return Guard::enum($value, self::CATALOG, $field);
    }

    /**
     * Die Pakete einer Version.
     *
     * @return list<string>
     */
    public static function packages(string $version): array
    {
        $version = self::normalize($version);

        return array_map(
            static fn (string $extension): string => 'php'.$version.'-'.$extension,
            self::EXTENSIONS,
        );
    }

    /** Die systemd-Unit des Masterprozesses. */
    public static function unit(string $version): string
    {
        return 'php'.self::normalize($version).'-fpm.service';
    }

    /** Der logische Name des Programms in der Positivliste des {@see Runner}. */
    public static function program(string $version): string
    {
        return 'php-fpm'.self::normalize($version);
    }

    /** Der Handler — hier liegt er, wenn die Version installiert ist. */
    public static function binary(string $version): string
    {
        return '/usr/sbin/php-fpm'.self::normalize($version);
    }

    public static function poolDir(string $version, string $root = self::PHP_ROOT): string
    {
        return rtrim($root, '/').'/'.self::normalize($version).'/fpm/pool.d';
    }

    /** Die Pool-Datei eines Abonnements in dieser Version. */
    public static function poolFile(string $version, string $user, string $root = self::PHP_ROOT): string
    {
        return self::poolDir($version, $root).'/srvpanel-'.Ops\SubscriptionProvision::systemUser($user).'.conf';
    }

    /**
     * Der Pool der Distribution.
     *
     * Er kommt mit dem Paket, heisst `www`, läuft als `www-data` und hat kein
     * `open_basedir`. Er ist damit genau das geteilte Loch, das P3 zumacht —
     * und deshalb schaltet ihn `php.version.install` ab, statt ihn stehen zu
     * lassen.
     */
    public static function distributionPool(string $version): string
    {
        return self::poolDir($version).'/www.conf';
    }

    public static function socket(string $version, string $user): string
    {
        return '/run/php/srvpanel-'.Ops\SubscriptionProvision::systemUser($user).'-'.self::normalize($version).'.sock';
    }

    /**
     * Die Quelldatei, die `packaging/php-source.sh` schreibt.
     *
     * **Als Vorgabe und nicht als feste Zeichenkette**, damit ein Wächter die
     * Auswertung in einem Sandkasten prüfen kann, ohne den Pfad ein zweites
     * Mal zu bauen — dieselbe Bauart wie bei {@see self::PHP_ROOT}.
     */
    public const SOURCE_FILE = '/etc/apt/sources.list.d/php-sury.sources';

    /**
     * Die Adressen der PHP-Quelle — gelesen, nicht gewusst.
     *
     * ## Warum die Datei und keine Konstante
     *
     * `packaging/php-source.sh` trägt je nach Distribution eine andere Adresse
     * ein: `https://packages.sury.org/php/` auf Debian, das PPA von Ondřej
     * Surý auf Ubuntu. Dieselbe Fallunterscheidung hier noch einmal
     * hinzuschreiben wäre eine zweite Fassung derselben Regel — und die
     * zweite ist die, die veraltet. Sie wäre ausserdem falsch, sobald ein
     * Betreiber einen Spiegel einträgt.
     *
     * > **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen
     * > Zustand.** Hier ist die Datei der wirksame Zustand: Genau sie liest
     * > apt.
     *
     * ## Der Leser steht seit dem 1. September 2026 in `Sources`
     *
     * Er ist hier entstanden, weil PHP ihn zuerst brauchte, und hat mit PHP
     * nichts zu tun; `PanelUpdate` stellt dieselbe Frage für die eigene
     * Paketquelle des Panels. Was bleibt, ist die **Vorgabedatei** — und die
     * ist die Naht zur Paketierung, die `PhpSourceUriTest` hält.
     *
     * Leer, wenn es die Datei nicht gibt — auf Debian 13 kommt PHP 8.4 aus der
     * Distribution, und dort richtet das Paket gar keine Quelle ein. **Leer
     * heisst „keine eigene Quelle" und nicht „nicht nachgesehen":** Der
     * Aufrufer kann dann keine Quelle als schuldig benennen, und das ist
     * richtig so.
     *
     * @return list<string>
     */
    public static function sourceUris(string $file = self::SOURCE_FILE): array
    {
        return Sources::uris($file);
    }

    /** Ist diese Version auf dem System vorhanden? */
    public static function installed(string $version): bool
    {
        return is_executable(self::binary($version));
    }

    /**
     * Die installierten Versionen.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::CATALOG, self::installed(...)));
    }

    /**
     * Die Argumente für `dpkg-query`, mit denen {@see self::missing()} rechnet.
     *
     * **Als Konstante, damit Frage und Auswertung nicht auseinanderlaufen.**
     * Das Ausgabeformat bestimmt, was der Parser unten liest; stünden die
     * beiden an verschiedenen Stellen, wäre die erste Änderung am Format ein
     * Parser, der nichts mehr findet und deshalb alles für installiert hält.
     *
     * @var list<string>
     */
    public const DPKG_ARGUMENTS = ['-W', '-f=${binary:Package} ${db:Status-Status}\n'];

    /**
     * Welche der gewünschten Pakete fehlen — aus der Ausgabe von `dpkg-query`.
     *
     * **Getrennt vom Aufruf, damit die Regel ohne Server prüfbar ist.**
     * Derselbe Schnitt wie bei `Pg\Clusters::parse()`, und aus demselben
     * Anlass: Was gemessen werden soll, ist eine Eigenschaft der Zeichenkette.
     *
     * ## Wie die Ausgabe aussieht, gemessen
     *
     * ```
     * $ dpkg-query -W -f='${binary:Package} ${db:Status-Status}\n' a fehlt b
     * a installed
     * b installed                                    ← stdout, vollständig
     * dpkg-query: no packages found matching fehlt   ← stderr
     * rc=1
     * ```
     *
     * **Der Rückgabewert 1 heisst „eines davon kennt dpkg nicht" und nicht
     * „der Aufruf ist gescheitert".** Genau dafür ist er hier da; wer ihn als
     * Fehlschlag liest, bekommt eine Operation, die immer dann abbricht, wenn
     * sie etwas zu tun hätte. Deshalb wird `stdout` ausgewertet und der Code
     * nicht angesehen.
     *
     * **Und gezählt wird, was `installed` meldet, nicht was fehlt.** Ein Paket
     * kann `config-files` sein (entfernt, Konfiguration noch da) oder
     * `half-installed`; beides ist nicht benutzbar. Wer auf „steht nicht in
     * der Ausgabe" prüfte, hielte diese Zustände für in Ordnung.
     *
     * **`installed` ist englisch und bleibt es, auch auf einem deutschen
     * System — gemessen und nicht angenommen.** Der Entwicklungscontainer
     * spricht englisch, dieser Vergleich hätte dort also in jedem Fall
     * gepasst. Am 9. August 2026 auf `cloudsrv24` nachgesehen, dessen Locale
     * deutsch ist: Die *Meldung* ist übersetzt — „Kein Paket gefunden, das auf
     * php8.4-pgsql passt" —, das Feld `${db:Status-Status}` nicht.
     *
     * Der Unterschied wäre teuer gewesen und lautlos: Ein übersetztes
     * Statuswort hiesse, dass **jedes** Paket als fehlend gilt, und
     * `php.version.install` liesse bei jedem Aufruf `apt-get` laufen. Ein Lauf,
     * der zu viel installiert, sieht aus wie ein erfolgreicher.
     *
     * @param  list<string>  $wanted
     * @return list<string>
     */
    public static function missing(array $wanted, string $output): array
    {
        $present = [];

        foreach (explode("\n", $output) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) < 2 || $fields[1] !== 'installed') {
                continue;
            }

            $present[] = $fields[0];
        }

        return array_values(array_filter(
            $wanted,
            static fn (string $package): bool => ! in_array($package, $present, true),
        ));
    }
}
