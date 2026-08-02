<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

use RuntimeException;

/**
 * Fehler, die als Ergebnis über das Protokoll zurückgehen.
 *
 * Der Code ist maschinenlesbar und Teil der Schnittstelle; die Meldung ist für
 * Menschen und darf die tatsächliche Ausgabe des Systems enthalten. Was nicht
 * hineingehört: interne Pfade des Agenten, Stacktraces, Speicheradressen.
 */
final class AgentException extends RuntimeException
{
    public const BAD_REQUEST = 'bad_request';

    public const UNKNOWN_OP = 'unknown_op';

    public const DENIED = 'denied';

    public const TIMEOUT = 'timeout';

    public const EXEC_FAILED = 'exec_failed';

    public const NOT_FOUND = 'not_found';

    public const INTERNAL = 'internal';

    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $fehlercode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string,mixed> $details */
    public static function badRequest(string $message, array $details = []): self
    {
        return new self(self::BAD_REQUEST, $message, $details);
    }

    public static function denied(string $message): self
    {
        return new self(self::DENIED, $message);
    }

    /** @param array<string,mixed> $details */
    public static function execFailed(string $message, array $details = []): self
    {
        return new self(self::EXEC_FAILED, $message, $details);
    }

    /** @return array{code:string,message:string,details:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'code' => $this->fehlercode,
            'message' => $this->getMessage(),
            'details' => $this->details,
        ];
    }
}
