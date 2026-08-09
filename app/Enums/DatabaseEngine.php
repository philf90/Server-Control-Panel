<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Welches Datenbanksystem hinter einer Zeile steht.
 *
 * **Eine Aufzählung von Anfang an, und das ist die Lehre aus `DumpKind`.** Dort
 * war `kind` bis zum 9. August eine nackte Spalte, deren zwei Werte als
 * Zeichenketten an vier Stellen verstreut standen — bis eine davon im
 * Vue-Template landete, also über eine Grenze, die kein Typ prüft. Der Fehler
 * war nie die Wahl der Wörter, sondern dass man sie **tippen** musste.
 *
 * `engine` wäre der nächste Kandidat gewesen: Er steht in drei Tabellen, wird
 * in jeder Verzweigung zwischen `db.*` und `pg.*` gelesen und geht als Marke in
 * die Oberfläche. `DatabaseEngineTest` besteht darauf, dass ihn niemand tippt.
 *
 * **Warum `postgres` und nicht `postgresql`.** Der kürzere Name ist der, den
 * PostgreSQL selbst überall benutzt — der Unix-Benutzer, die Vorgabedatenbank,
 * das Binärprogramm. Ein Wert, der anders heisst als das, was daneben steht,
 * ist eine Einladung, ihn falsch zu tippen; genau das hat `export` gegen
 * `exported` gekostet.
 */
enum DatabaseEngine: string
{
    case MariaDb = 'mariadb';

    case Postgres = 'postgres';

    /**
     * Wie es in der Oberfläche heisst.
     *
     * **Beide tragen eine Marke, anders als bei {@see DumpKind}.** Dort trägt
     * nur der Ausnahmefall eine, weil eine Spalte, in der überall dasselbe
     * steht, niemand liest. Hier gibt es keinen Regelfall: Welches System hinter
     * einer Datenbank steht, entscheidet, wie der Kunde sich verbindet — und
     * eine Zeile ohne Angabe hiesse „das übliche", was es nicht gibt.
     */
    public function label(): string
    {
        return match ($this) {
            self::MariaDb => 'MariaDB',
            self::Postgres => 'PostgreSQL',
        };
    }

    /**
     * Das Präfix der Agent-Operationen dieses Systems.
     *
     * `db.database.create` gegen `pg.database.create` — die Verzweigung, die
     * `docs/38 §8` an genau einer Stelle haben will. Sie steht hier und nicht in
     * der Dienstschicht, damit es nicht zwei Fassungen davon gibt.
     */
    public function operationPrefix(): string
    {
        return match ($this) {
            self::MariaDb => 'db',
            self::Postgres => 'pg',
        };
    }

    /**
     * Die PHP-Erweiterung, mit der ein Kunde sich verbindet.
     *
     * **Der teuerste fehlende Bezug dieser Stufe, und er stand nirgends.**
     * Diese Aufzählung ist am 9. August 2026 entstanden, und derselbe Tag hätte
     * die Frage beantworten müssen: Womit spricht die Website des Kunden mit
     * seiner Datenbank? `PhpVersions::EXTENSIONS` kannte `mysql` und kein
     * `pgsql` — ein Kunde hätte seine PostgreSQL-Datenbank bekommen und keine
     * Verbindung dazu. Gefunden hat es kein Test, sondern ein Blick in die
     * Paketbeziehungen drei Beiträge später (`docs/38 §24.2`).
     *
     * Der Name steht deshalb hier, an der Aufzählung, und nicht als weitere
     * Zeile in der Erweiterungsliste des Agenten: **Wer ein Datenbanksystem
     * hinzufügt, kommt an dieser Methode nicht vorbei**, und `EngineExtensionTest`
     * hält beide Seiten zusammen. Ein System ohne Weg vom PHP des Kunden ist
     * ein Angebot, das an der ersten Verbindung scheitert.
     *
     * Es ist der Paketsuffix und nicht der Modulname: `php8.2-mysql` bringt
     * `mysqli`, `mysqlnd` und `pdo_mysql` mit und kein Modul namens `mysql`.
     * Installiert wird nach Paket.
     */
    public function phpExtension(): string
    {
        return match ($this) {
            self::MariaDb => 'mysql',
            self::Postgres => 'pgsql',
        };
    }
}
