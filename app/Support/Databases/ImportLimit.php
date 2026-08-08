<?php

declare(strict_types=1);

namespace App\Support\Databases;

/**
 * Wie gross eine hochgeladene Sicherung sein darf — an einer Stelle.
 *
 * **Drei Zahlen müssen zusammenpassen, und sie stehen an drei Orten**
 * (`docs/36 §10.3`): `client_max_body_size` im Server-Block der Oberfläche,
 * `upload_max_filesize`/`post_max_size` im FPM-Pool der Oberfläche, und die
 * Prüfregel am Formular. Eine davon zu ändern und die anderen nicht ergibt
 * einen Upload, der bei 90 % abbricht — mit einer nginx-Fehlerseite, die von
 * PHP nichts weiss.
 *
 * **Die Prüfregel ist die kleinste der drei, und das ist der Punkt.** Wer
 * abgewiesen wird, soll die Meldung des Panels sehen und nicht die des
 * Webservers. `UploadLimitTest` liest alle drei aus ihren Quellen und besteht
 * darauf.
 *
 * **Diese Klasse gab es schon einmal, und sie ist am 8. August 2026 gelöscht
 * worden** (`docs/36 §22.3f`): Die drei Zahlen standen, der Wächter darüber
 * stand — und das Hochladen selbst war nie gebaut. Was blieb, war eine Zusage
 * in der Oberfläche ohne Route dahinter und ein Panel, das 544 MB
 * Anfragekörper annahm, ohne je eine Datei entgegenzunehmen. Sie kommt hier
 * zurück, weil es die Funktion jetzt gibt — und der Wächter prüft seit diesem
 * Beitrag auch, dass sie aufgerufen wird.
 */
final class ImportLimit
{
    /**
     * Was das Formular annimmt: 512 MB.
     *
     * Der Vorschlag aus `docs/36 §10.3`. Grösseres gehört in P8 — Sicherungen
     * mit Zielen und Aufbewahrung —, nicht durch ein Formularfeld.
     */
    public const MAX_BYTES = 512 * 1024 * 1024;

    /**
     * Die Prüfregel, wie Laravel sie versteht — in Kibibyte.
     *
     * `max:` zählt bei einer Datei in KiB. Die Umrechnung steht hier und nicht
     * im Steuerungscode: Sie ist der Ort, an dem sich ein Faktor 1024
     * verstecken kann, und dieses Projekt hat genau daran schon einmal einen
     * Nachmittag verloren (`docs/36 §22.3j`).
     */
    public static function rule(): string
    {
        return 'max:'.intdiv(self::MAX_BYTES, 1024);
    }

    /** Dieselbe Zahl für einen Menschen — die Oberfläche sagt sie an. */
    public static function label(): string
    {
        return intdiv(self::MAX_BYTES, 1024 * 1024).' MB';
    }
}
