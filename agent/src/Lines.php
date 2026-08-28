<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Ganze Zeilen aus Stücken, die keine sind.
 *
 * ## Befund 4 aus `docs/86`, und er war eine Frage
 *
 * Auf der Vorgangsseite stand `W` allein und `: …` darunter — die Marke
 * zerrissen, an der eine Zeile überhaupt als Warnung erkennbar ist. Gemessen
 * war, dass apt sie zusammenhängend schreibt; offen war, ob der Umbruch aus
 * der CSS kommt oder aus dem gespeicherten Text.
 *
 * **Gemessen am 28. August 2026 gegen den echten Runner und echtes apt:** 320
 * Zeilen auf dem Rahmenweg gegen **317** in der vollständigen Ausgabe
 * desselben Laufs. Die Antwort ist der Text und nicht die CSS.
 *
 * ## Warum
 *
 * {@see Runner} liest mit `fread($pipe, 65536)`. Was dabei zurückkommt, sind
 * Bytes und keine Zeilen — die Grenze fällt, wohin sie fällt. Die alte Schleife
 * machte daraus trotzdem Zeilen:
 *
 *     foreach (explode("\n", rtrim($chunk, "\n")) as $line) { … }
 *
 * `rtrim` schneidet **nur hinten**. Endet ein Stück ohne seinen Umbruch,
 * beginnt das nächste damit, und `explode` liefert eine leere erste Zeile —
 * so entstanden die drei Zeilen Unterschied. Fällt die Grenze mitten im Text,
 * wird die Zeile stattdessen in zwei zerrissen, und genau das hat der Betreiber
 * am `W:` gesehen.
 *
 * > **Eine Stückgrenze ist keine Zeilengrenze — und wer je Stück Zeilen
 * > schreibt, macht aus jeder Grenze eine.**
 *
 * ## Der Rest gehört dem Kanal
 *
 * `stdout` und `stderr` werden abwechselnd gelesen. Ein gemeinsamer Rest
 * klebte das halbe Ende der einen an den Anfang der anderen — aus zwei
 * zerrissenen Zeilen würde eine falsch zusammengesetzte, und die sähe aus wie
 * eine Meldung, die es nie gab.
 *
 * > **Zwei Ströme, die sich einen Puffer teilen, erzeugen eine Zeile, die
 * > keiner von beiden geschrieben hat.**
 *
 * ## Und was am Ende übrig ist, geht nicht verloren
 *
 * Ein Programm, dessen letzte Zeile ohne Umbruch endet, hätte sie sonst nie
 * gesendet — ausgerechnet die letzte, in der bei `apt-run` das Urteil steht.
 */
final class Lines
{
    /**
     * Was vom letzten Stück ohne Umbruch übrig blieb, je Kanal.
     *
     * @var array<string, string>
     */
    private array $rest = [];

    /**
     * Die ganzen Zeilen aus diesem Stück — der Rest wartet auf das nächste.
     *
     * @return list<string>
     */
    public function feed(string $channel, string $chunk): array
    {
        $text = ($this->rest[$channel] ?? '').$chunk;

        $letzter = strrpos($text, "\n");

        if ($letzter === false) {
            // Keine einzige ganze Zeile — alles wartet.
            $this->rest[$channel] = $text;

            return [];
        }

        $this->rest[$channel] = substr($text, $letzter + 1);

        return explode("\n", substr($text, 0, $letzter));
    }

    /**
     * Was am Ende noch aussteht — einmal, und danach ist der Kanal leer.
     *
     * `null` und nicht `''`: Ein leerer Rest ist keine Zeile, und wer ihn als
     * eine sendete, hängte an jede Ausgabe eine leere an.
     */
    public function flush(string $channel): ?string
    {
        $rest = $this->rest[$channel] ?? '';
        unset($this->rest[$channel]);

        return $rest === '' ? null : $rest;
    }

    /** @return list<string> Die Kanäle, die noch etwas ausstehen haben. */
    public function pending(): array
    {
        return array_keys(array_filter($this->rest, static fn (string $r): bool => $r !== ''));
    }
}
