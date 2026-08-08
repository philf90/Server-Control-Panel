<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Zustand einer Sicherung.
 *
 * **`Failed` gibt es, und das ist der Unterschied zu den anderen
 * Aufzählungen dieses Projekts.** Eine Domain und eine Datenbank bleiben bei
 * einem Fehlschlag auf „wird angelegt" stehen, und der Vorgang trägt den Grund
 * — dort gibt es nichts, was übrig bliebe. Eine gescheiterte Sicherung ist
 * dagegen ein Gegenstand, der bestehen bleibt: eine halbe Datei, eine Datenbank
 * mit halb eingespielten Daten. Wer sie sieht, muss wissen, dass er ihr nicht
 * trauen darf, und zwar auf der Liste und nicht erst im Vorgangsprotokoll.
 */
enum DumpStatus: string
{
    /** Der Vorgang läuft — oder wartet noch. */
    case Pending = 'pending';

    case Ready = 'ready';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'läuft',
            self::Ready => 'fertig',
            self::Failed => 'fehlgeschlagen',
        };
    }

    /** Lässt sich diese Sicherung herunterladen oder zurückspielen? */
    public function usable(): bool
    {
        return $this === self::Ready;
    }
}
