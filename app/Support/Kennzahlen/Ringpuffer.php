<?php

declare(strict_types=1);

namespace App\Support\Kennzahlen;

use RuntimeException;

/**
 * Ein Ringpuffer fester Größe in einer Datei.
 *
 * **Warum keine Tabelle.** Kennzahlen im Zehnsekundentakt sind 8 640 Zeilen je
 * Tag und Kennzahl. In einer Datenbanktabelle wären das nach einem Jahr drei
 * Millionen Zeilen, die niemand mehr liest, plus ein Aufräumlauf, den jemand
 * pflegen muss. Die Datei hier ist beim ersten Schreiben so groß wie am Ende
 * und braucht kein Aufräumen: Sie dreht sich.
 *
 * **Warum keine Datei je Messung.** PHP-FPM hält zwischen zwei Anfragen nichts
 * im Speicher — der Sammler ist ein eigener Prozess, die Anzeige liest. Zwei
 * Prozesse, ein gemeinsamer Ort: eine Datei mit fester Satzlänge, in die an
 * berechneter Stelle geschrieben wird.
 *
 * Aufbau: 32 Byte Kopf, danach `vorhalt` Sätze zu je (1 + `spalten`) doubles.
 * Der Schreibzeiger steht im Kopf; ein Satz mit Zeitstempel 0 ist unbelegt.
 */
final class Ringpuffer
{
    private const MAGIE = "CSRV\x01";

    private const KOPF = 32;

    public function __construct(
        private readonly string $datei,
        private readonly int $spalten,
        private readonly int $vorhalt,
    ) {
        if ($this->spalten < 1 || $this->vorhalt < 2) {
            throw new RuntimeException('Ringpuffer braucht mindestens eine Spalte und zwei Sätze.');
        }
    }

    public function satzlaenge(): int
    {
        return 8 * (1 + $this->spalten);
    }

    /** @param list<float> $werte */
    public function schreibe(array $werte, ?float $zeit = null): void
    {
        if (count($werte) !== $this->spalten) {
            throw new RuntimeException(sprintf(
                'Erwartet werden %d Werte, geliefert wurden %d.',
                $this->spalten,
                count($werte),
            ));
        }

        $griff = $this->oeffne();

        try {
            flock($griff, LOCK_EX);
            $zeiger = $this->leseZeiger($griff);

            $satz = pack('e', $zeit ?? microtime(true));
            foreach ($werte as $wert) {
                $satz .= pack('e', $wert);
            }

            fseek($griff, self::KOPF + $zeiger * $this->satzlaenge());
            fwrite($griff, $satz);

            $this->schreibeZeiger($griff, ($zeiger + 1) % $this->vorhalt);
            fflush($griff);
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    /**
     * Die Stützstellen in zeitlicher Reihenfolge, älteste zuerst.
     *
     * @return list<array{zeit:float,werte:list<float>}>
     */
    public function lies(?int $hoechstens = null): array
    {
        if (! is_file($this->datei)) {
            return [];
        }

        $griff = $this->oeffne();

        try {
            flock($griff, LOCK_SH);
            $zeiger = $this->leseZeiger($griff);

            fseek($griff, self::KOPF);
            $roh = (string) fread($griff, $this->satzlaenge() * $this->vorhalt);
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }

        $saetze = [];

        // Ab dem Schreibzeiger einmal herum: Das ist die Reihenfolge, in der
        // die Sätze entstanden sind — der älteste steht dort, wo als nächstes
        // geschrieben wird.
        for ($i = 0; $i < $this->vorhalt; $i++) {
            $stelle = (($zeiger + $i) % $this->vorhalt) * $this->satzlaenge();
            $stueck = substr($roh, $stelle, $this->satzlaenge());

            if (strlen($stueck) < $this->satzlaenge()) {
                continue;
            }

            /** @var array<int,float> $zahlen */
            $zahlen = array_values(unpack('e'.(1 + $this->spalten), $stueck) ?: []);

            if ($zahlen === [] || $zahlen[0] <= 0.0) {
                continue;
            }

            $saetze[] = [
                'zeit' => $zahlen[0],
                'werte' => array_slice($zahlen, 1),
            ];
        }

        if ($hoechstens !== null && count($saetze) > $hoechstens) {
            $saetze = array_slice($saetze, -$hoechstens);
        }

        return $saetze;
    }

    /** @return resource */
    private function oeffne()
    {
        $verzeichnis = dirname($this->datei);

        if (! is_dir($verzeichnis) && ! @mkdir($verzeichnis, 0o750, true) && ! is_dir($verzeichnis)) {
            throw new RuntimeException(sprintf('Verzeichnis %s ließ sich nicht anlegen.', $verzeichnis));
        }

        $neu = ! is_file($this->datei);
        $griff = @fopen($this->datei, $neu ? 'c+b' : 'r+b');

        if ($griff === false) {
            throw new RuntimeException(sprintf('Ringpuffer %s ließ sich nicht öffnen.', $this->datei));
        }

        if ($neu || filesize($this->datei) < self::KOPF) {
            // Die Datei wird sofort in voller Länge angelegt. Ein Ringpuffer,
            // der beim Umlauf noch wächst, hätte den Vorteil verspielt, den er
            // gegenüber einer Tabelle hat.
            ftruncate($griff, self::KOPF + $this->satzlaenge() * $this->vorhalt);
            fseek($griff, 0);
            fwrite($griff, self::MAGIE.pack('VVV', $this->spalten, $this->vorhalt, 0));
            fflush($griff);
        }

        return $griff;
    }

    /** @param resource $griff */
    private function leseZeiger($griff): int
    {
        fseek($griff, 0);
        $kopf = (string) fread($griff, self::KOPF);

        if (strlen($kopf) < 17 || ! str_starts_with($kopf, self::MAGIE)) {
            return 0;
        }

        $felder = unpack('Vspalten/Vvorhalt/Vzeiger', substr($kopf, 5, 12));

        if ($felder === false) {
            return 0;
        }

        // Wurde die Form geändert (andere Spaltenzahl, anderer Vorhalt), ist
        // der Inhalt nicht mehr deutbar. Dann lieber von vorn als falsche
        // Kurven zeigen.
        if ($felder['spalten'] !== $this->spalten || $felder['vorhalt'] !== $this->vorhalt) {
            ftruncate($griff, 0);
            fseek($griff, 0);
            fwrite($griff, self::MAGIE.pack('VVV', $this->spalten, $this->vorhalt, 0));
            ftruncate($griff, self::KOPF + $this->satzlaenge() * $this->vorhalt);

            return 0;
        }

        return $felder['zeiger'] % $this->vorhalt;
    }

    /** @param resource $griff */
    private function schreibeZeiger($griff, int $zeiger): void
    {
        fseek($griff, 13);
        fwrite($griff, pack('V', $zeiger));
    }
}
