<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use RuntimeException;

/**
 * Ein RingBuffer fester Größe in einer Datei.
 *
 * **Warum keine Tabelle.** Kennzahlen im Zehnsekundentakt sind 8 640 Zeilen je
 * Tag und Kennzahl. In einer Datenbanktabelle wären das nach einem Jahr drei
 * Millionen Zeilen, die niemand mehr liest, plus ein Aufräumlauf, den jemand
 * pflegen muss. Die Datei hier ist beim ersten Schreiben so groß wie am Ende
 * und braucht kein Aufräumen: Sie dreht sich.
 *
 * **Warum keine Datei je Messung.** PHP-FPM hält zwischen zwei Anfragen nichts
 * im Speicher — der Collector ist ein eigener Prozess, die Anzeige liest. Zwei
 * Prozesse, ein gemeinsamer Ort: eine Datei mit fester Satzlänge, in die an
 * berechneter Stelle geschrieben wird.
 *
 * Aufbau: 32 Byte Kopf, danach `vorhalt` Sätze zu je (1 + `spalten`) doubles.
 * Der Schreibzeiger steht im Kopf; ein Satz mit Zeitstempel 0 ist unbelegt.
 */
final class RingBuffer
{
    private const MAGIC = "CSRV\x01";

    private const HEADER = 32;

    public function __construct(
        private readonly string $file,
        private readonly int $columns,
        private readonly int $capacity,
    ) {
        if ($this->columns < 1 || $this->capacity < 2) {
            throw new RuntimeException('RingBuffer braucht mindestens eine Spalte und zwei Sätze.');
        }
    }

    public function recordSize(): int
    {
        return 8 * (1 + $this->columns);
    }

    /** @param list<float> $values */
    public function write(array $values, ?float $zeit = null): void
    {
        if (count($values) !== $this->columns) {
            throw new RuntimeException(sprintf(
                'Erwartet werden %d Werte, geliefert wurden %d.',
                $this->columns,
                count($values),
            ));
        }

        $handle = $this->open();

        try {
            flock($handle, LOCK_EX);
            $cursor = $this->readCursor($handle);

            $record = pack('e', $zeit ?? microtime(true));
            foreach ($values as $value) {
                $record .= pack('e', $value);
            }

            fseek($handle, self::HEADER + $cursor * $this->recordSize());
            fwrite($handle, $record);

            $this->writeCursor($handle, ($cursor + 1) % $this->capacity);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Die Stützstellen in zeitlicher Reihenfolge, älteste zuerst.
     *
     * @return list<array{zeit:float,werte:list<float>}>
     */
    public function read(?int $limit = null): array
    {
        if (! is_file($this->file)) {
            return [];
        }

        $handle = $this->open();

        try {
            flock($handle, LOCK_SH);
            $cursor = $this->readCursor($handle);

            fseek($handle, self::HEADER);
            $raw = (string) fread($handle, $this->recordSize() * $this->capacity);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $records = [];

        // Ab dem Schreibzeiger einmal herum: Das ist die Reihenfolge, in der
        // die Sätze entstanden sind — der älteste steht dort, wo als nächstes
        // geschrieben wird.
        for ($i = 0; $i < $this->capacity; $i++) {
            $offset = (($cursor + $i) % $this->capacity) * $this->recordSize();
            $chunk = substr($raw, $offset, $this->recordSize());

            if (strlen($chunk) < $this->recordSize()) {
                continue;
            }

            /** @var array<int,float> $numbers */
            $numbers = array_values(unpack('e'.(1 + $this->columns), $chunk) ?: []);

            if ($numbers === [] || $numbers[0] <= 0.0) {
                continue;
            }

            $records[] = [
                'time' => $numbers[0],
                'values' => array_slice($numbers, 1),
            ];
        }

        if ($limit !== null && count($records) > $limit) {
            $records = array_slice($records, -$limit);
        }

        return $records;
    }

    /** @return resource */
    private function open()
    {
        $directory = dirname($this->file);

        if (! is_dir($directory) && ! @mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Verzeichnis %s ließ sich nicht anlegen.', $directory));
        }

        $isNew = ! is_file($this->file);
        $handle = @fopen($this->file, $isNew ? 'c+b' : 'r+b');

        if ($handle === false) {
            throw new RuntimeException(sprintf('RingBuffer %s ließ sich nicht öffnen.', $this->file));
        }

        if ($isNew || filesize($this->file) < self::HEADER) {
            // Die Datei wird sofort in voller Länge angelegt. Ein RingBuffer,
            // der beim Umlauf noch wächst, hätte den Vorteil verspielt, den er
            // gegenüber einer Tabelle hat.
            ftruncate($handle, self::HEADER + $this->recordSize() * $this->capacity);
            fseek($handle, 0);
            fwrite($handle, self::MAGIC.pack('VVV', $this->columns, $this->capacity, 0));
            fflush($handle);
        }

        return $handle;
    }

    /** @param resource $handle */
    private function readCursor($handle): int
    {
        fseek($handle, 0);
        $kopf = (string) fread($handle, self::HEADER);

        if (strlen($kopf) < 17 || ! str_starts_with($kopf, self::MAGIC)) {
            return 0;
        }

        $fields = unpack('Vspalten/Vvorhalt/Vzeiger', substr($kopf, 5, 12));

        if ($fields === false) {
            return 0;
        }

        // Wurde die Form geändert (andere Spaltenzahl, anderer Vorhalt), ist
        // der Inhalt nicht mehr deutbar. Dann lieber von vorn als falsche
        // Kurven zeigen.
        if ($fields['spalten'] !== $this->columns || $fields['vorhalt'] !== $this->capacity) {
            ftruncate($handle, 0);
            fseek($handle, 0);
            fwrite($handle, self::MAGIC.pack('VVV', $this->columns, $this->capacity, 0));
            ftruncate($handle, self::HEADER + $this->recordSize() * $this->capacity);

            return 0;
        }

        return $fields['zeiger'] % $this->capacity;
    }

    /** @param resource $handle */
    private function writeCursor($handle, int $cursor): void
    {
        fseek($handle, 13);
        fwrite($handle, pack('V', $cursor));
    }
}
