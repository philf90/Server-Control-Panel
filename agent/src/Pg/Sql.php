<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Sql as DbSql;

/**
 * Aus geprüften Werten wird SQL — an dieser einen Stelle.
 *
 * Die Werte sind hier bereits durch {@see Names} gegangen; was diese Klasse tut,
 * ist die zweite Schranke. Dieselbe Bauart wie {@see DbSql}, mit drei
 * Unterschieden, die alle am 9. August 2026 auf PostgreSQL 16.13 **gemessen**
 * und nicht aus dem Gedächtnis übernommen sind.
 *
 * ## 1. Die Unterstrich-Falle aus P5 gibt es hier nicht
 *
 * `docs/36 §3.1` ist der teuerste Fund jenes Entwurfs: In MariaDB ist
 * ``GRANT … ON `p1001_%`.*`` ein **Muster**, `_` trifft ein beliebiges Zeichen,
 * und `p1001\_shop` müsste deshalb maskiert werden, damit es nicht auch
 * `p1001Xshop` trifft.
 *
 * **In PostgreSQL ist das Ziel einer Rechtevergabe ein Bezeichner und kein
 * Muster.** Gemessen: `GRANT CONNECT ON DATABASE m29_a TO m29` gibt Zugang zu
 * `m29_a` und **nicht** zu `m29xa`. Es gibt hier deshalb kein Gegenstück zu
 * `Db\Sql::grantTarget()` — und das ist keine Auslassung, sondern ein Befund.
 * Wer eine Maskierung nachbaut, die es nicht braucht, baut eine Regel, die
 * niemand mehr erklären kann.
 *
 * ## 2. Der Backslash maskiert nicht
 *
 * `standard_conforming_strings` steht seit PostgreSQL 9.1 auf `on`, gemessen
 * auch hier: In `'a\b'` ist der Backslash ein gewöhnliches Zeichen. Zu
 * maskieren ist deshalb **nur** das einfache Anführungszeichen, und zwar durch
 * Verdoppeln. `Db\Sql::text()` maskiert beide, weil MariaDB beide braucht — die
 * Regel von dort zu übernehmen, ergäbe hier Passwörter mit einem Backslash zu
 * viel.
 *
 * ## 3. Bezeichner stehen in doppelten Anführungszeichen
 *
 * Nicht in Backticks. Und unangeführt schreibt PostgreSQL sie klein, weshalb
 * {@see Names} nur Kleinbuchstaben zulässt: Angeführt wäre `Shop` etwas anderes
 * als `shop`, unangeführt dasselbe, und der Unterschied fiele erst auf, wenn
 * jemand beide anlegt.
 */
final class Sql
{
    /**
     * Ein Bezeichner in doppelten Anführungszeichen.
     *
     * Ein Anführungszeichen im Namen wird **nicht verdoppelt, sondern
     * abgewiesen** — der Unterschied zwischen einer Maskierung und einer
     * Positivliste: Wer verdoppelt, hat eine Regel für den Fall; wer abweist,
     * hat den Fall nicht. Wortgleich die Begründung aus {@see DbSql::identifier()}.
     *
     * Die Längengrenze steht hier und nicht nur in {@see Names}, weil ein
     * Bezeichner auch aus einer Konstante dieses Agenten kommen kann —
     * `pg_stat_database` etwa. **PostgreSQL weist einen zu langen Namen nicht
     * ab, es schneidet ihn ab** (gemessen: 63 Zeichen), und zwei Namen, die
     * sich erst danach unterscheiden, wären hinterher derselbe.
     */
    public static function identifier(string $name): string
    {
        if ($name === '' || str_contains($name, '"') || str_contains($name, "\0")) {
            throw AgentException::badRequest('Unzulässiger Bezeichner.', ['name' => $name]);
        }

        if (strlen($name) > Names::MAX_IDENTIFIER) {
            throw AgentException::badRequest(
                sprintf('Bezeichner zu lang: %d Zeichen, erlaubt sind %d.', strlen($name), Names::MAX_IDENTIFIER),
                ['name' => $name],
            );
        }

        return '"'.$name.'"';
    }

    /**
     * Ein qualifizierter Name — `"public"."tabelle"`.
     *
     * Beide Hälften einzeln angeführt. Ein Punkt, der zwischen zwei
     * Anführungszeichen steht, trennt; einer innerhalb ist ein Zeichen. Wer den
     * ganzen Ausdruck in einem Aufruf anführte, bekäme eine einzige Tabelle mit
     * einem Punkt im Namen.
     */
    public static function qualified(string $schema, string $name): string
    {
        return self::identifier($schema).'.'.self::identifier($name);
    }

    /**
     * Eine Zeichenkette in einfachen Anführungszeichen.
     *
     * Für Passwörter und Netze. Verdoppelt wird das Anführungszeichen und sonst
     * nichts — siehe Punkt 2 im Kopf dieser Klasse.
     */
    public static function text(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw AgentException::badRequest('Eine Zeichenkette mit Nullbyte erreicht die Datenbank nicht.');
        }

        return "'".str_replace("'", "''", $value)."'";
    }
}
