<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Server;

/**
 * Eine mitgebrachte PostgreSQL-Sicherung übernehmen.
 *
 * Sie kommt aus dem Schreibbereich des Panels ({@see Dump::STAGING_ROOT}) und
 * wird geprüft, gemessen und in die Ablage verschoben — Zeile für Zeile
 * dieselbe Folge wie in {@see DbDumpImport}, und deshalb stehen die vier
 * Prüfungen seit P5b in {@see Dump} statt zweimal in je einer Operation.
 *
 * **Eingespielt wird hier nichts.** Das tut `pg.restore`, unter der befristeten
 * Rolle. Diese Operation legt nur ab.
 *
 * ## Der Fassungsversatz — die eine Prüfung, die es hier zusätzlich gibt
 *
 * Ein Dump aus einer **neueren** PostgreSQL-Fassung lässt sich nicht
 * einspielen; PostgreSQL kennt für Dumps keine Abwärtskompatibilität in diese
 * Richtung. Das trifft mitgebrachte Sicherungen und nie eigene, und es gehört
 * **vor** den Lauf gesagt (`docs/38 §13.3`): Ein Kunde, der eine Sicherung von
 * einem Server mit PostgreSQL 17 auf einen mit 16 bringt, soll das beim
 * Hochladen erfahren und nicht nach vierzig Minuten Zurückspielen an einer
 * Syntaxmeldung.
 *
 * `pg_dump` schreibt die Fassung in den Kopf, gemessen am 9. August 2026:
 *
 *     -- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
 *     -- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
 *
 * **Verglichen wird die Hauptfassung und nicht die Nebenfassung.** 16.14 auf
 * 16.13 einzuspielen geht; das Format ändert sich innerhalb einer
 * Hauptfassung nicht. Eine Prüfung auf die volle Zeichenkette wiese den
 * häufigsten Fall ab, den es gibt — zwei Server derselben Distribution mit
 * einem Sicherheitsupdate Abstand.
 *
 * **Fehlt der Kopf, wird nicht abgewiesen.** Eine von Hand geschriebene
 * `.sql.gz` hat keinen, und sie ist eine gültige Sicherung. Was diese Prüfung
 * leisten soll, ist eine *Auskunft vor dem Lauf* — keine Echtheitsprüfung.
 *
 * **Und ein Befund, der nicht in `docs/38 §13.3` steht:** Seit den
 * Sicherheitsupdates von 2025 schreibt `pg_dump` eine Zeile `\restrict <token>`
 * an den Anfang. Ein **älteres** `psql` kennt das Meta-Kommando nicht und
 * bricht daran ab — der Versatz wirkt also auch dann, wenn die Hauptfassungen
 * gleich sind. Dagegen hilft diese Prüfung nicht, und sie soll es auch nicht:
 * Was dann kommt, ist eine Meldung von `psql` mit Zeilennummer, und die ist
 * verständlicher als eine Regel über Nebenfassungen, die dieses Panel pflegen
 * müsste.
 */
final class PgDumpImport implements Op
{
    /**
     * Wie viel vom ausgepackten Anfang für den Kopf gelesen wird.
     *
     * Der Kopf steht in den ersten Zeilen; vier Kilobyte sind grosszügig und
     * kosten nichts. Ein Dump, der seine Fassung erst nach vier Kilobyte nennt,
     * nennt sie für diese Prüfung nicht — und das ist der Fall „kein Kopf", der
     * ohnehin durchgelassen wird.
     */
    private const HEAD_BYTES = 4096;

    /** Der Ausdruck, der die Fassung aus dem Kopf holt. */
    private const VERSION_LINE = '/^-- Dumped from database version (\d+)\.(\d+)/m';

    public function __construct(private readonly Server $server = new Server) {}

    public static function name(): string
    {
        return 'pg.dump.import';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $prefix = Names::prefix($args['prefix'] ?? null);
        $subscription = Guard::string($args['subscription'] ?? null, 'subscription');
        $storage = Dump::storageName(Guard::string($args['storage'] ?? null, 'storage'));
        $source = Guard::pathInside($args['source'] ?? null, [Dump::STAGING_ROOT]);

        if (! is_file($source)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Die hochgeladene Datei ist nicht da.',
                ['source' => $source],
            );
        }

        $context->progress(10, 'Datei prüfen');
        Dump::requireGzip($source);

        $context->progress(20, 'Fassung prüfen');
        $from = $this->requireCompatible($context, $source);

        $context->progress(35, 'ausgepackte Grösse messen');
        $unpacked = Dump::unpackedSize($source);

        $context->progress(60, 'Platz prüfen');
        Dump::requireSpace($unpacked, $this->dataDirectory($context));

        $context->progress(80, 'Sicherung übernehmen');

        $directory = Dump::prepare($subscription);
        $target = Dump::path($subscription, $storage);

        // Der Name ist eindeutig gegen den Bestand des Panels; existiert die
        // Datei trotzdem, ist etwas anderes schiefgelaufen, und Überschreiben
        // wäre die falsche Antwort.
        if (is_file($target)) {
            throw AgentException::denied('Unter diesem Namen liegt schon eine Sicherung: '.$target);
        }

        Dump::moveInto($source, $target);

        chmod($target, Dump::FILE_MODE);
        @chown($target, 'root');
        @chgrp($target, Dump::GROUP);

        $context->progress(100, 'fertig');

        return [
            'path' => $target,
            'directory' => $directory,
            'prefix' => $prefix,
            'bytes' => (int) filesize($target),
            'unpacked_bytes' => $unpacked,
            'dumped_from' => $from,
            'kind' => 'imported',
        ];
    }

    /**
     * Die Fassung aus dem Kopf lesen — als Text, damit sie prüfbar ist.
     *
     * Getrennt vom Auspacken und `public static`, aus demselben Grund wie
     * überall in P5b: An einer Datei liesse sich die Regel nur mit einer Datei
     * prüfen, und dann prüfte man das Auspacken.
     *
     * @return null|array{0: int, 1: int} Hauptfassung und Nebenfassung, oder `null`
     */
    public static function versionOf(string $head): ?array
    {
        if (preg_match(self::VERSION_LINE, $head, $match) !== 1) {
            return null;
        }

        return [(int) $match[1], (int) $match[2]];
    }

    /**
     * Ist der Dump für diesen Server nicht zu neu?
     *
     * @return string Die gefundene Fassung, oder `''` wenn der Dump keine nennt
     */
    private function requireCompatible(Context $context, string $source): string
    {
        $handle = @gzopen($source, 'rb');

        if ($handle === false) {
            throw AgentException::badRequest('Die hochgeladene Datei liess sich nicht auspacken.');
        }

        try {
            $head = (string) gzread($handle, self::HEAD_BYTES);
        } finally {
            gzclose($handle);
        }

        $version = self::versionOf($head);

        if ($version === null) {
            return '';
        }

        $here = $this->server->majorOf($context);

        // Keine Auskunft über den eigenen Server ist kein Grund abzuweisen —
        // dieselbe Zurückhaltung wie bei {@see Dump::requireSpace()}. Eine
        // Grenze ohne Gegenstand ist keine Grenze, sondern ein Ausfall.
        if ($here !== null && $version[0] > $here) {
            throw AgentException::denied(sprintf(
                'Diese Sicherung stammt aus PostgreSQL %d.%d, dieser Server hat %d. Ein Dump lässt '
                .'sich nicht in eine ältere Hauptfassung einspielen — dafür müsste der Server '
                .'zuerst auf %d gebracht werden.',
                $version[0],
                $version[1],
                $here,
                $version[0],
            ));
        }

        return sprintf('%d.%d', $version[0], $version[1]);
    }

    /**
     * Wo die Daten des Clusters liegen — dort muss der Platz sein.
     *
     * **Gefragt und nicht gesetzt.** `/var/lib/postgresql/16/main` wäre der
     * Pfad, den Debian anlegt; auf einem Server mit eigener Platte für die
     * Datenbank ist er ein anderer, und dann prüfte diese Operation den Platz
     * am falschen Ort. `pg_lsclusters` weiss ihn.
     */
    private function dataDirectory(Context $context): string
    {
        $cluster = $this->server->primaryCluster($context);

        return $cluster === null ? Dump::ROOT : $cluster['directory'];
    }
}
