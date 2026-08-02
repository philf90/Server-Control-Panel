<?php

declare(strict_types=1);

namespace App\Enums;

enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Läuft noch — die Oberfläche hält die Verbindung offen. */
    public function open(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'wartet',
            self::Running => 'läuft',
            self::Succeeded => 'fertig',
            self::Failed => 'fehlgeschlagen',
            self::Cancelled => 'abgebrochen',
        };
    }
}
