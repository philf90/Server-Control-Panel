<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Drei Ausgänge, nicht zwei.
 *
 * „Abgewiesen" ist kein Sonderfall von „fehlgeschlagen": Ein Fehlschlag heißt,
 * jemand durfte und es ging schief; eine Abweisung heißt, jemand durfte nicht.
 * Wer beides zusammenwirft, kann im Protokoll später nicht mehr sehen, ob ein
 * Kunde an eine Grenze gestoßen ist oder ob jemand versucht hat, sie zu
 * überschreiten.
 */
enum AuditResult: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'erfolgreich',
            self::Failure => 'fehlgeschlagen',
            self::Denied => 'abgewiesen',
        };
    }
}
