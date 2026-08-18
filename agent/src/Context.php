<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Was eine Operation zur Verfügung hat: den Runner, das Protokoll und einen
 * Rückkanal für Fortschritt und Ausgabe.
 *
 * Der Rückkanal ist der Grund, warum ein Vorgang im Panel zusehen kann, statt
 * am Ende ein Ergebnis vorgesetzt zu bekommen. Er ist bewusst ein Callback und
 * kein Rückgabewert: Eine Operation, die zehn Minuten läuft, soll nach zehn
 * Sekunden etwas gesagt haben.
 */
final class Context
{
    /** Unter welchen Kennungen der Vorgang lief — der Schlüssel in der Antwort. */
    public const RAN_AS = 'ran_as';

    /**
     * Was die Sandbox über den Lauf gemeldet hat, oder `null`.
     *
     * @var array{uid: int, groups: list<int>}|null
     */
    private ?array $ranAs = null;

    /**
     * @param  callable(array<string,mixed>):void  $send
     * @param  null|callable():bool  $abort  Sagt, ob der Aufrufer weg ist
     */
    public function __construct(
        public readonly Runner $runner,
        public readonly Journal $journal,
        private $send,
        private $abort = null,
    ) {}

    /**
     * Festhalten, unter wem die Sandbox gelaufen ist.
     *
     * ## Warum das hier steht und nicht im Ergebnis der Operation
     *
     * `docs/51 §4` verlangt in Punkt 13 und 14, dass **jeder** Datei-Vorgang
     * seine `uid` und seine Zusatzgruppen meldet. Der erste Wurf hängte den Beleg
     * in `Files\Workspace::run()` an das Ergebnis der Sandbox — eine Stelle für
     * dreizehn Operationen, und das schien die richtige Bauform.
     *
     * **Sie war es nicht, und zwar messbar:** `files.list` und `files.extract`
     * bauen aus dem Ergebnis ein **frisches** Feld-Array und geben nur einzelne
     * Werte daraus weiter. Der Beleg wäre bei elf von dreizehn angekommen und
     * bei zweien lautlos verschwunden — und die nächste Operation, die ihr
     * Ergebnis umbaut, hätte ihn wieder verloren.
     *
     * > **Ein Beleg, den die Zwischenstelle weiterreichen muss, ist bei der
     * > ersten Zwischenstelle weg, die ihn nicht kennt.**
     *
     * Der Vorgang trägt ihn deshalb nicht; die Anfrage tut es. {@see Connection}
     * hängt ihn an die Antwort, nachdem die Operation fertig ist — dort kann
     * keine ihn mehr verlieren.
     *
     * @param  array{uid: int, groups: list<int>}  $ranAs
     */
    public function recordRanAs(array $ranAs): void
    {
        /*
         * **Zwei verschiedene Konten in einer Anfrage sind kein Sonderfall,
         * sondern ein Bruch der Mandantengrenze.** Eine Operation gehört zu
         * einem Abonnement; liefe sie zweimal unter verschiedenen Kennungen,
         * wäre die Frage „unter wem lief sie?" nicht mehr beantwortbar — und
         * das ist die einzige Frage, die dieser Beleg hat.
         */
        if ($this->ranAs !== null && $this->ranAs !== $ranAs) {
            throw AgentException::execFailed('Ein Vorgang ist unter zwei verschiedenen Konten gelaufen.');
        }

        $this->ranAs = $ranAs;
    }

    /**
     * Was die Sandbox gemeldet hat — `null`, wenn keine lief.
     *
     * @return array{uid: int, groups: list<int>}|null
     */
    public function ranAs(): ?array
    {
        return $this->ranAs;
    }

    /** Der Aufrufer ist weg — was hier läuft, wartet auf niemanden mehr. */
    public function abandoned(): bool
    {
        return $this->abort !== null && ($this->abort)();
    }

    public function progress(int $percent, string $text): void
    {
        // Gebaut und nicht getippt: Die Namen der Felder stehen ausschliesslich
        // in {@see Frame}, weil die Gegenseite sie zehn Monate lang anders
        // gelesen hat (docs/36 §22.3w).
        ($this->send)(Frame::progress($percent, $text));
    }

    public function output(string $channel, string $line): void
    {
        ($this->send)(Frame::log($channel, $line));
    }

    /**
     * Ein Runner, dessen Ausgabe unterwegs an die Anwendung geht.
     *
     * @param  list<string>  $args
     */
    public function stream(string $program, array $args, int $timeout = 60): Result
    {
        return $this->runner->run(
            $program,
            $args,
            $timeout,
            fn (string $channel, string $line) => $this->output($channel, $line),
            null,
            fn (): bool => $this->abandoned(),
        );
    }
}
