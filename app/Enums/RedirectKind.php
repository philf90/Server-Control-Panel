<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie eine Domain weiterleitet, wenn sie weiterleitet.
 *
 * **Zwei Werte, und der Unterschied ist keine Feinheit.** Eine dauerhafte
 * Weiterleitung merkt sich der Browser — und zwar auch dann noch, wenn sie im
 * Panel längst gelöscht ist. Wer sie versehentlich setzt, erreicht seine
 * eigene Domain im eigenen Browser nicht mehr, bis er dessen Zwischenspeicher
 * leert. Deshalb ist die vorübergehende die Voreinstellung, und die Oberfläche
 * sagt den Unterschied mit {@see self::hint()} dazu.
 *
 * Kein Rahmen („Frame-Weiterleitung"), wie ihn ältere Panels anbieten: Er
 * versteckt die Zieladresse, bricht an jedem Formular und wird von jedem
 * modernen Browser mit Sicherheitswarnungen bedacht.
 */
enum RedirectKind: string
{
    case Temporary = 'temporary';
    case Permanent = 'permanent';

    public function label(): string
    {
        return match ($this) {
            self::Temporary => 'vorübergehend (302)',
            self::Permanent => 'dauerhaft (301)',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Temporary => 'Der Browser fragt bei jedem Aufruf neu. Rücknahme wirkt sofort.',
            self::Permanent => 'Der Browser merkt sie sich. Nach einer Rücknahme rufen Besucher noch lange das alte Ziel auf.',
        };
    }

    /** Der Statuscode, den nginx setzt. */
    public function statusCode(): int
    {
        return match ($this) {
            self::Temporary => 302,
            self::Permanent => 301,
        };
    }
}
