<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Woher ein Zertifikat stammt.
 *
 * **Die Angabe entscheidet mehr als die Anzeige.** `docs/27 §7`: Ein
 * selbstsigniertes Zertifikat bekommt keinen `Strict-Transport-Security`, weil
 * ein Jahr erzwungenes HTTPS eine Zusage wäre, die das Panel nicht halten kann
 * — der nächste Zertifikatswechsel sperrt den Betreiber sonst aus seinem
 * eigenen Panel aus. `panel.vhost.apply` liest das heute aus der Datei
 * (Aussteller gleich Inhaber); hier steht dieselbe Auskunft an dem Gegenstand,
 * über den das Panel redet.
 *
 * `Uploaded` gibt es schon, obwohl der Weg dorthin erst im zweiten Wurf
 * gebaut wird. Der Grund ist nicht Vorratshaltung: Ohne diesen Fall müsste die
 * Spalte später einen Wert annehmen, den es beim Schreiben der Migration nicht
 * gab — und eine Aufzählung nachträglich zu erweitern heisst, jeden `match`
 * darüber noch einmal anzufassen.
 */
enum CertificateSource: string
{
    case Acme = 'acme';
    case SelfSigned = 'self_signed';
    case Uploaded = 'uploaded';

    public function label(): string
    {
        return match ($this) {
            self::Acme => 'Let’s Encrypt',
            self::SelfSigned => 'selbstsigniert',
            self::Uploaded => 'hochgeladen',
        };
    }

    /**
     * Kann ein Browser diesem Zertifikat trauen?
     *
     * Nur dann ist `Strict-Transport-Security` richtig. Ein hochgeladenes
     * Zertifikat zählt dazu: Wer eines einspielt, hat es von einer
     * Zertifizierungsstelle — und wenn nicht, sieht er dieselbe Warnung wie
     * beim selbstsignierten, nur ohne Zusage auf ein Jahr.
     */
    public function trusted(): bool
    {
        return $this !== self::SelfSigned;
    }
}
