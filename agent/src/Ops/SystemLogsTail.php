<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Logs;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Result;

/**
 * Die letzten Zeilen eines Protokolls des Servers.
 *
 * **Kein Pfad und keine Unit kommen von aussen** — übergeben wird ein
 * Schlüssel aus {@see Logs}. Die Begründung steht dort.
 *
 * ## Der Fund, der diese Operation geprägt hat
 *
 * Gemessen am 24. August 2026 gegen systemd 255:
 *
 *     journalctl -u srvpanel-web       rc=0 · stdout „-- No entries --"
 *                                      stderr „No journal files were found."
 *     journalctl -u gibt-es-nicht      dasselbe, Zeichen für Zeichen
 *
 * Der Rückgabewert unterscheidet also **nicht** zwischen „diese Unit hat nichts
 * geschrieben", „es gibt gar kein Journal" und „diese Unit kennt niemand". Und
 * `-- No entries --` steht auf **stdout**, also genau dort, wo der Leser die
 * Zeilen erwartet.
 *
 * > **Ein Leser, der `-- No entries --` als Zeile nimmt, zeigt eine Meldung
 * > des Werkzeugs als Inhalt des Protokolls.**
 *
 * Deshalb zwei Dinge: Die Markierung wird herausgenommen statt durchgereicht,
 * und `stderr` wird als **Hinweis** mitgegeben statt verworfen. „Kein Journal
 * auf diesem Server" ist eine Auskunft, die ein Betreiber braucht — sie
 * bedeutet, dass die Einträge nach dem Neustart fort sind, und das ist etwas
 * anderes als ein stiller Dienst.
 *
 * Die Gegenprobe derselben Messung zeigt, dass der Rückgabewert sehr wohl
 * etwas tragen kann: Ein unbekanntes Ausgabeformat endet mit 1. Ein
 * Fehlschlag wird deshalb weiterhin am Rückgabewert erkannt.
 *
 * ## Warum nicht `grep`
 *
 * Der Filter kommt aus einem Formular. Er wird **in PHP** angewandt und nicht
 * an ein Programm gereicht — nicht wegen der Kommandozeile (der {@see Runner}
 * kennt keine Shell), sondern weil ein Filter, der als Muster interpretiert
 * wird, bei einem Punkt im Suchtext etwas anderes findet, als der Kunde
 * gemeint hat.
 */
final class SystemLogsTail implements Op
{
    public const MAX_LINES = 500;

    /**
     * Was `journalctl` schreibt, wenn es nichts zu zeigen gibt.
     *
     * Gemessen und nicht nachgelesen — mit `LC_ALL=C` aus
     * {@see \SrvPanel\Agent\Runner} ist der Wortlaut fest.
     */
    public const JOURNAL_EMPTY = '-- No entries --';

    public static function name(): string
    {
        return 'system.logs.tail';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $key = Guard::enum($args['source'] ?? null, Logs::keys(), 'source');
        $source = Logs::source($key);
        $lines = $this->lines($args['lines'] ?? 100);
        $filter = $this->filter($args['filter'] ?? null);

        $found = $source['kind'] === Logs::FILE
            ? $this->fromFile((string) $source['path'])
            : $this->fromJournal($context, (string) $source['unit']);

        $matched = $this->apply($found['lines'], $filter);

        return [
            'source' => $key,
            'label' => $source['label'],
            'origin' => $source['path'] ?? $source['unit'],
            'exists' => $found['exists'],
            'note' => $found['note'],
            'filter' => $filter,

            // **Erst filtern, dann kürzen.** Andersherum bekäme der Kunde die
            // Treffer aus den letzten hundert Zeilen und nicht die letzten
            // hundert Treffer — und die Zahl daneben wäre eine über den
            // Ausschnitt statt über das Protokoll.
            'lines' => array_values(array_slice($matched, -$lines)),

            /*
             * **`matched` und nicht `total`.** Gelesen wird immer ein Fenster
             * von {@see self::MAX_LINES} Zeilen vom Ende her; `matched` sagt,
             * wie viele davon der Filter durchgelassen hat. Das ist **nicht**
             * die Zahl der Zeilen in der Datei, und ein Feld namens `total`
             * behauptete genau das.
             *
             * > **Eine Zahl, die nach etwas anderem heisst, als sie zählt,
             * > wird irgendwann als das andere gelesen.**
             *
             * `window` steht daneben, damit die Seite den Satz vollständig
             * bilden kann: „12 Treffer in den letzten 500 Zeilen".
             */
            'matched' => count($matched),
            'window' => self::MAX_LINES,
            'truncated' => count($matched) > $lines,
        ];
    }

    /**
     * @return array{lines: list<string>, exists: bool, note: null|string}
     */
    private function fromFile(string $path): array
    {
        if (! is_file($path)) {
            // Kein Protokoll ist kein Fehler — dieselbe Entscheidung wie in
            // {@see WebLogsTail}: Ein Server, der noch nichts geschrieben hat,
            // ist der Normalfall am ersten Tag.
            return ['lines' => [], 'exists' => false, 'note' => null];
        }

        // **Von hinten gelesen, und zwar mit demselben Leser.** Ein zweiter
        // Rückwärtsleser wäre die Stelle, an der die beiden auseinanderlaufen.
        return ['lines' => WebLogsTail::tail($path, self::MAX_LINES), 'exists' => true, 'note' => null];
    }

    /**
     * @return array{lines: list<string>, exists: bool, note: null|string}
     */
    private function fromJournal(Context $context, string $unit): array
    {
        $result = $context->runner->run('journalctl', [
            '--unit='.$unit,
            '--lines='.self::MAX_LINES,
            '--no-pager',
            '--output=short-iso',
        ], 30);

        if (! $result->successful()) {
            throw AgentException::execFailed(
                'Das Journal liess sich nicht lesen: '.$result->message(),
                ['unit' => $unit],
            );
        }

        return self::readJournal($result);
    }

    /**
     * Die Zeilen aus der Ausgabe von `journalctl` — die reine Naht.
     *
     * **Getrennt vom Aufruf, damit die Regel ohne systemd prüfbar ist.** Der
     * Container, in dem dieses Projekt gebaut wird, hat kein Journal; ohne
     * diesen Schnitt wäre der interessanteste Fall — die leere Antwort mit
     * Rückgabe 0 — nur auf einem echten Server zu sehen.
     *
     * @return array{lines: list<string>, exists: bool, note: null|string}
     */
    public static function readJournal(Result $result): array
    {
        $lines = [];

        foreach (explode("\n", $result->stdout) as $line) {
            $line = rtrim($line, "\r");

            if (trim($line) === '' || trim($line) === self::JOURNAL_EMPTY) {
                continue;
            }

            $lines[] = $line;
        }

        // **`stderr` wird zum Hinweis und nicht zur Zeile.** „No journal files
        // were found" heisst, dass dieser Server sein Journal nicht behält —
        // eine Auskunft über die Einrichtung, nicht über den Dienst.
        $note = trim($result->stderr);

        return [
            'lines' => $lines,
            'exists' => $lines !== [],
            'note' => $note === '' ? null : $note,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function apply(array $lines, ?string $filter): array
    {
        if ($filter === null) {
            return $lines;
        }

        return array_values(array_filter(
            $lines,
            // Ohne Rücksicht auf Gross- und Kleinschreibung: Wer nach „error"
            // sucht, meint auch „ERROR" — und in einem Protokoll stehen beide.
            static fn (string $line): bool => mb_stripos($line, $filter) !== false,
        ));
    }

    private function filter(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $filter = trim(Guard::string($value, 'filter'));

        if ($filter === '') {
            return null;
        }

        if (mb_strlen($filter) > 200) {
            throw AgentException::badRequest('Der Filter ist auf 200 Zeichen begrenzt.', ['filter' => $filter]);
        }

        return $filter;
    }

    private function lines(mixed $value): int
    {
        $lines = Guard::int($value, 'lines');

        if ($lines < 1 || $lines > self::MAX_LINES) {
            throw AgentException::badRequest(
                sprintf('lines liegt zwischen 1 und %d.', self::MAX_LINES),
                ['lines' => $lines],
            );
        }

        return $lines;
    }
}
