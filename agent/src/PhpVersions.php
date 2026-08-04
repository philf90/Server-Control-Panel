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
        'fpm', 'mysql', 'xml', 'mbstring', 'curl', 'gd',
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
}
