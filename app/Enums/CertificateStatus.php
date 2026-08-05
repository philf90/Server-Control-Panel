<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Zustand eines Zertifikats — und er folgt dem Agenten, nicht dem Klick.
 *
 * Dieselbe Aufteilung wie bei {@see DomainStatus}, aus demselben Grund: Zwischen
 * dem Absenden und der Antwort der Zertifizierungsstelle liegen Sekunden bis
 * Minuten, in denen die Bestellung im Panel steht und noch nichts gilt.
 *
 * **`Failed` ist der Zustand, den man beim Bauen vergisst.** Das
 * Abnahmekriterium der Stufe verlangt, dass ein Fehlschlag den laufenden
 * Betrieb nicht unterbricht — eine gescheiterte Ausstellung darf die Domain
 * also weder offline nehmen noch ihr vorheriges Zertifikat wegwerfen. Ohne
 * eigenen Zustand dafür bliebe die Bestellung auf `Pending` stehen, und der
 * Betreiber sähe „läuft noch" für etwas, das vor drei Tagen aufgegeben hat.
 */
enum CertificateStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'wird ausgestellt',
            self::Active => 'gültig',
            self::Failed => 'fehlgeschlagen',
        };
    }

    /** Liefert der Webserver dieses Zertifikat gerade aus? */
    public function usable(): bool
    {
        return $this === self::Active;
    }
}
