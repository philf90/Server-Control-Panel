<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\AgentException;

/**
 * Aus geprüften Werten wird SQL — an dieser einen Stelle.
 *
 * **Die Werte sind hier bereits durch {@see Names} gegangen.** Was diese Klasse
 * tut, ist deshalb keine Rettung, sondern die zweite Schranke: Sie kostet je
 * eine Zeile und deckt jeden künftigen Fehler in der Namensprüfung ab. Dieselbe
 * Bauart wie die dritte Schranke in `SubscriptionRemove` — „Ein leerer Name
 * kommt nicht durch die Prüfung, aber die Bedingung steht trotzdem da."
 *
 * ## Die Unterstrich-Falle in GRANT
 *
 * **In `GRANT … ON <db>.*` ist `<db>` ein Muster und kein Name.** `_` steht
 * dort für ein beliebiges Zeichen, `%` für beliebig viele. Der naheliegende Weg,
 * einem Abonnement seine Datenbanken freizugeben, wäre gewesen:
 *
 *     GRANT ALL PRIVILEGES ON `p1001_%`.* TO 'p1001_web'@'localhost';
 *
 * Das sieht aus wie „alle Datenbanken von p1001" und ist es nicht: `p1001_%`
 * trifft auch `p10012_shop` — fünf Zeichen `p1001`, dann `_` für die `2`, dann
 * `%` für den Rest. **Das ist ein Zugriff über die Mandantengrenze hinweg**,
 * und zwar genau der, den das Abnahmekriterium von P5 ausschliesst.
 *
 * Daraus zwei Festlegungen, die {@see self::grantTarget()} einlöst:
 *
 * 1. Es wird nie auf ein Muster berechtigt, immer auf genau eine Datenbank.
 * 2. Der Name wird auch dann maskiert, wenn er ein Name ist:
 *    ``GRANT … ON `p1001\_shop`.* …``
 *
 * Ohne die Maskierung träfe `p1001_shop` auch `p1001Xshop`. Ein solcher Name
 * kann heute nicht entstehen, weil {@see Names} an dieser Stelle einen
 * Unterstrich verlangt. **Genau diese Sorte Begründung lehnt dieses Projekt
 * ab:** Eine Regel, die zufällig gilt, gilt bis zur nächsten Änderung an einer
 * ganz anderen Stelle. Die Maskierung kostet ein Zeichen und macht die Aussage
 * wahr, statt sie stimmen zu lassen.
 */
final class Sql
{
    /**
     * Ein Bezeichner in Backticks.
     *
     * Ein Backtick im Namen wird nicht verdoppelt, sondern abgewiesen. Das ist
     * der Unterschied zwischen einer Maskierung und einer Positivliste: Wer
     * verdoppelt, hat eine Regel für den Fall; wer abweist, hat den Fall nicht.
     */
    public static function identifier(string $name): string
    {
        if ($name === '' || str_contains($name, '`') || str_contains($name, "\0")) {
            throw AgentException::badRequest('Unzulässiger Bezeichner.', ['name' => $name]);
        }

        return '`'.$name.'`';
    }

    /**
     * Eine Zeichenkette in einfachen Anführungszeichen.
     *
     * Für Benutzernamen, Wirte und Passwörter. Maskiert werden Backslash und
     * Anführungszeichen — dieselben beiden wie in `PanelProvision::escape()`,
     * und der Kommentar dort gilt hier genauso: Das Passwort kommt aus dem
     * Panel und enthält kein Sonderzeichen, der Schutz ist trotzdem da.
     */
    public static function text(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw AgentException::badRequest('Eine Zeichenkette mit Nullbyte erreicht die Datenbank nicht.');
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * Ein qualifizierter Name — `` `p1001_shop`.`kunden` ``.
     *
     * Beide Hälften einzeln angeführt, wortgleich die Begründung aus
     * {@see \SrvPanel\Agent\Pg\Sql::qualified()}: Ein Punkt zwischen zwei
     * Backticks trennt, einer innerhalb ist ein Zeichen. Wer den ganzen Ausdruck
     * in einem Aufruf anführte, bekäme eine einzige Tabelle mit einem Punkt im
     * Namen.
     */
    public static function qualified(string $database, string $name): string
    {
        return self::identifier($database).'.'.self::identifier($name);
    }

    /** `'p1001_web'@'localhost'` — beide Hälften einzeln maskiert. */
    public static function account(string $user, string $host): string
    {
        return self::text($user).'@'.self::text($host);
    }

    /**
     * Das Ziel einer Rechtevergabe: genau eine Datenbank, Unterstrich maskiert.
     *
     * Die ganze Begründung steht im Kopf dieser Klasse. Was hier **nicht**
     * herauskommen darf, ist `*.*` und ist ein Name mit `%` darin —
     * `DbIsolationTest` und `GrantPatternTest` bestehen darauf.
     */
    public static function grantTarget(string $database): string
    {
        if (str_contains($database, '%')) {
            throw AgentException::denied('Auf ein Muster wird nicht berechtigt.');
        }

        // Backslash zuerst, sonst maskiert der zweite Durchgang den, den der
        // erste gesetzt hat. Ein Backslash kann in einem geprüften Namen nicht
        // vorkommen; die Reihenfolge steht da, weil sie ohne den Grund beim
        // nächsten Lesen falsch aussieht.
        $escaped = str_replace(['\\', '_'], ['\\\\', '\\_'], $database);

        return self::identifier($escaped).'.*';
    }
}
