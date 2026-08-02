<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/** Das Ergebnis eines Programmlaufs. */
final class Result
{
    public function __construct(
        public readonly int $code,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $truncated = false,
        public readonly float $duration = 0.0,
    ) {}

    public function successful(): bool
    {
        return $this->code === 0;
    }

    /** Die Ausgabe, die einem Menschen gezeigt wird — stderr zuerst, weil dort der Fehler steht. */
    public function message(): string
    {
        $text = trim($this->stderr) !== '' ? trim($this->stderr) : trim($this->stdout);

        return $text === '' ? sprintf('Abbruch mit Code %d, keine Ausgabe.', $this->code) : $text;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $raw = explode("\n", trim($this->stdout));

        return array_values(array_filter($raw, static fn (string $l): bool => trim($l) !== ''));
    }
}
