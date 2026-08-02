<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/** Das Ergebnis eines Programmlaufs. */
final class Ergebnis
{
    public function __construct(
        public readonly int $code,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $gekuerzt = false,
        public readonly float $dauer = 0.0,
    ) {}

    public function erfolgreich(): bool
    {
        return $this->code === 0;
    }

    /** Die Ausgabe, die einem Menschen gezeigt wird — stderr zuerst, weil dort der Fehler steht. */
    public function meldung(): string
    {
        $text = trim($this->stderr) !== '' ? trim($this->stderr) : trim($this->stdout);

        return $text === '' ? sprintf('Abbruch mit Code %d, keine Ausgabe.', $this->code) : $text;
    }

    /** @return list<string> */
    public function zeilen(): array
    {
        $roh = explode("\n", trim($this->stdout));

        return array_values(array_filter($roh, static fn (string $z): bool => trim($z) !== ''));
    }
}
