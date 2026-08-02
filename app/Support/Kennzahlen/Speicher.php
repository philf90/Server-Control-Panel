<?php

declare(strict_types=1);

namespace App\Support\Kennzahlen;

/**
 * Hält die Ringpuffer und macht aus ihnen Stützstellen für die Oberfläche.
 *
 * Die Umrechnung passiert hier und nicht im Browser: Was der Server schickt,
 * ist fertig — Wert, Beschriftung, Einheit, Position. Das ist Regel 2 des
 * Gestaltungssystems (§7.2) und der Grund, warum die Kachel im Browser mit
 * dreißig Zeilen auskommt.
 */
final class Speicher
{
    /** @var array<string,Ringpuffer> */
    private array $puffer = [];

    public function __construct(
        private readonly string $verzeichnis,
        private readonly int $vorhalt = 8640,
    ) {}

    public function puffer(string $name, int $spalten): Ringpuffer
    {
        $schluessel = $name.':'.$spalten;

        return $this->puffer[$schluessel] ??= new Ringpuffer(
            sprintf('%s/%s.ring', rtrim($this->verzeichnis, '/'), $name),
            $spalten,
            $this->vorhalt,
        );
    }

    /**
     * Ein Verlauf für die Kachel: höchstens `punkte` Stützstellen, auf 0…100
     * in x normiert, mit fertiger Beschriftung.
     *
     * @return array{hat:bool,punkte:list<array{x:float,y:float,t:string,v:string}>}
     */
    public function verlauf(
        string $name,
        int $spalten,
        int $spalte = 0,
        int $punkte = 60,
        string $einheit = '',
        int $nachkomma = 0,
    ): array {
        $saetze = $this->puffer($name, $spalten)->lies();

        if (count($saetze) < 2) {
            return ['hat' => false, 'punkte' => []];
        }

        $saetze = $this->verdichte($saetze, $punkte);

        $werte = array_map(static fn (array $s): float => $s['werte'][$spalte] ?? 0.0, $saetze);
        $min = min($werte);
        $max = max($werte);
        $spanne = ($max - $min) > 0.0001 ? $max - $min : 1.0;
        $letzte = count($saetze) - 1;

        $ausgabe = [];

        foreach ($saetze as $i => $satz) {
            $wert = $satz['werte'][$spalte] ?? 0.0;

            $ausgabe[] = [
                'x' => round($i / $letzte * 100, 3),
                // y wächst im SVG nach unten; die Umkehr steht hier, damit sie
                // nicht in jeder Komponente noch einmal auftaucht.
                'y' => round(28 - ($wert - $min) / $spanne * 24, 3),
                't' => date('H:i', (int) $satz['zeit']),
                'v' => number_format($wert, $nachkomma, ',', '.').$einheit,
            ];
        }

        return ['hat' => true, 'punkte' => $ausgabe];
    }

    /**
     * Auf höchstens `ziel` Stützstellen eindampfen, mit Mittelwert je Fenster.
     *
     * Jede n-te Stützstelle zu nehmen wäre billiger und würde Spitzen
     * verschlucken — genau die, wegen derer jemand auf die Kurve schaut.
     *
     * @param  list<array{zeit:float,werte:list<float>}>  $saetze
     * @return list<array{zeit:float,werte:list<float>}>
     */
    private function verdichte(array $saetze, int $ziel): array
    {
        $anzahl = count($saetze);

        if ($anzahl <= $ziel) {
            return $saetze;
        }

        $breite = $anzahl / $ziel;
        $ergebnis = [];

        for ($i = 0; $i < $ziel; $i++) {
            $von = (int) floor($i * $breite);
            $bis = min($anzahl, (int) floor(($i + 1) * $breite));
            $fenster = array_slice($saetze, $von, max(1, $bis - $von));

            $summen = [];

            foreach ($fenster as $satz) {
                foreach ($satz['werte'] as $spalte => $wert) {
                    $summen[$spalte] = ($summen[$spalte] ?? 0.0) + $wert;
                }
            }

            $ergebnis[] = [
                'zeit' => $fenster[count($fenster) - 1]['zeit'],
                'werte' => array_map(static fn (float $s): float => $s / count($fenster), $summen),
            ];
        }

        return $ergebnis;
    }
}
