<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Zwischenmeldungen eines Vorgangs — Form und Bezeichner an einer Stelle.
 *
 * **Diese Klasse gibt es wegen eines Fehlers, der zehn Monate unbemerkt lief**
 * (`docs/36 §22.3w`). Der Agent schickte während einer Operation Frames der
 * Form `['type' => 'progress', 'pct' => 25, 'text' => '…']`, und das Panel las
 * daraus `percent` und `message`. Für die Ausgabe dasselbe eine Ebene höher:
 * gesendet wurde `type: 'log'`, gelesen wurde auf `type === 'output'`.
 *
 * Beides sah auf jeder Seite richtig aus. Die Folge war unsichtbar und
 * vollständig: **Der Fortschrittsbalken jedes Vorgangs sprang von 0 auf 100,
 * und die Ausgabe des Agenten hat nie ein Mensch gesehen** — auf 471 Vorgängen
 * am 9. August 2026 gemessen, kein einziger mit einem Wert dazwischen. Die
 * `progress()`-Aufrufe in jeder Operation seit P0 waren totes Gewicht, und die
 * Oberfläche hatte eine Anzeige für eine Ausgabe, die nie ankam.
 *
 * **Das ist wortwörtlich das Muster aus CLAUDE.md** — eine Zeichenkette, die
 * auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
 * prüft —, nur diesmal über eine Prozessgrenze hinweg, wo kein Aufrufgraph und
 * kein PHPStan hinsieht.
 *
 * **Die Antwort darauf ist nicht „die Namen richtigstellen", sondern sie nur
 * noch einmal zu schreiben.** Wer sendet, baut hier; wer liest, liest hier.
 * Ein Tippfehler ist damit ein Fehler beim Laden der Klasse und nicht eine
 * Anzeige, die still nichts tut.
 *
 * Die Drahtform bleibt die des Agenten (`pct`, `text`, `log`) und nicht die,
 * die das Panel erwartete: Sie ist die ältere und steht im Protokoll des
 * Agenten; sie umzubenennen hiesse, jede aufgezeichnete Sitzung unlesbar zu
 * machen, um einen Lesefehler zu kaschieren.
 */
final class Frame
{
    /** Ein Fortschrittsschritt mit Prozentzahl und kurzem Text. */
    public const PROGRESS = 'progress';

    /** Eine Zeile Programmausgabe, mit ihrem Kanal. */
    public const LOG = 'log';

    /** Das Ergebnis — es beendet den Aufruf und wird nicht hier gelesen. */
    public const RESULT = 'result';

    /**
     * Alle Arten, die während eines Aufrufs auftreten können.
     *
     * `RESULT` steht bewusst nicht darin: Es ist das Ende und keine
     * Zwischenmeldung. Die Liste ist der Gegenstand von `FrameContractTest` —
     * wer eine Art dazunimmt, bekommt dort Rot, solange niemand sie liest.
     *
     * @var list<string>
     */
    public const KINDS = [self::PROGRESS, self::LOG];

    /**
     * @return array<string, mixed>
     */
    public static function progress(int $percent, string $text): array
    {
        return [
            'type' => self::PROGRESS,
            'pct' => max(0, min(100, $percent)),
            'text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function log(string $channel, string $line): array
    {
        return [
            'type' => self::LOG,
            'stream' => $channel === 'stderr' ? 'stderr' : 'stdout',
            'line' => $line,
        ];
    }

    /** @param array<string, mixed> $frame */
    public static function kindOf(array $frame): ?string
    {
        $type = $frame['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * Die Prozentzahl — oder `null`, wenn dieser Frame keine trägt.
     *
     * **`null` und nicht `0`.** Das ist der Unterschied, der den Fehler oben
     * ausgemacht hat: Die alte Leseseite setzte bei einem unbekannten Schlüssel
     * stillschweigend `0` ein und schrieb ihn in den Bestand. Ein Frame ohne
     * Prozentzahl ist keine Meldung „null Prozent", sondern gar keine Meldung —
     * und der Aufrufer muss das unterscheiden können.
     *
     * @param  array<string, mixed>  $frame
     */
    public static function percentOf(array $frame): ?int
    {
        $value = $frame['pct'] ?? null;

        return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
    }

    /** @param array<string, mixed> $frame */
    public static function textOf(array $frame): ?string
    {
        $value = $frame['text'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $frame */
    public static function lineOf(array $frame): ?string
    {
        $value = $frame['line'] ?? null;

        return is_string($value) ? $value : null;
    }
}
