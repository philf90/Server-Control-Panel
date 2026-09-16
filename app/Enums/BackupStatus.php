<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was aus einer Sicherung geworden ist.
 *
 * **Drei Zustände und nicht zwei** — dieselbe Aufteilung wie bei
 * {@see DumpStatus}, und aus demselben Grund: „läuft noch" und „ist
 * fehlgeschlagen" sehen in einer Liste sonst gleich aus, und das ist genau der
 * Unterschied, auf den es ankommt.
 *
 * Der Zustand folgt dem **Agenten** und nicht dem Klick (`docs/20 §4`, die
 * zweite Grenze): `Ready` setzt `BackupLifecycle::afterSuccess()`, nachdem der
 * Agent geantwortet hat.
 */
enum BackupStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Lässt sich diese Sicherung benutzen?
     *
     * **Nur `Ready`.** Eine, die noch läuft, ist eine halb geschriebene Datei;
     * eine gescheiterte ist gar keine oder eine, von der niemand weiss, wie
     * weit sie kam. Beide herunterzugeben hiesse, etwas auszuliefern, das wie
     * eine Sicherung aussieht.
     */
    public function usable(): bool
    {
        return $this === self::Ready;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'wird erstellt',
            self::Ready => 'vorhanden',
            self::Failed => 'fehlgeschlagen',
        };
    }
}
