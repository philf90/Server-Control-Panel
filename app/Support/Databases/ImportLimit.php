<?php

declare(strict_types=1);

namespace App\Support\Databases;

/**
 * Die Grösse, die ein hochgeladener Dump haben darf.
 *
 * **Sie steht an drei Stellen, und die drei müssen zusammenpassen** (`docs/36
 * §10.3`):
 *
 * | Zahl | Wo sie steht | Wer sie setzt |
 * |---|---|---|
 * | `client_max_body_size` | Server-Block der Oberfläche | `agent/src/Ops/PanelVhost.php` |
 * | `upload_max_filesize`, `post_max_size` | FPM-Pool der Oberfläche | `agent/src/Ops/PanelProvision.php` |
 * | die Prüfregel am Formular | `DatabaseController` | diese Klasse |
 *
 * Eine davon zu ändern und die anderen nicht ergibt einen Upload, der bei 90 %
 * abbricht — mit einer nginx-Fehlerseite, die von PHP nichts weiss und von
 * diesem Panel erst recht nicht. Der Kunde sieht einen Abbruch ohne Grund.
 *
 * **Die Prüfregel ist die kleinste der drei**, und das ist die Aussage: Wer
 * abgewiesen wird, soll die Meldung des Panels sehen und nicht die des
 * Webservers. `UploadLimitTest` liest alle drei aus ihren Quellen und besteht
 * darauf.
 *
 * **512 MB** ist der Vorschlag aus `docs/36 §19` Punkt 6. Grösseres gehört in
 * P8, wo es Sicherungsziele und Aufbewahrung gibt — nicht in ein Formularfeld.
 */
final class ImportLimit
{
    /** Was ein Formular annimmt. Die kleinste der drei Zahlen. */
    public const MEGABYTES = 512;

    /**
     * Was nginx durchlässt — mit Luft für den Rest der Anfrage.
     *
     * Ein `multipart/form-data` trägt neben der Datei noch Feldnamen, Grenzen
     * und den CSRF-Wert. Wären beide Zahlen gleich, bräche eine Datei von genau
     * 512 MB an der Kopfzeile ab — und die Meldung käme von nginx.
     */
    public const NGINX_MEGABYTES = 544;

    /**
     * Was PHP annimmt.
     *
     * Zwischen nginx und der Prüfregel: Ein Upload, den nginx durchlässt und
     * PHP verwirft, endet in einer leeren `$_FILES` — also in einer
     * Fehlermeldung „keine Datei gewählt" für eine Datei, die der Benutzer
     * gewählt hat. Das ist die verwirrendste der drei Möglichkeiten.
     */
    public const PHP_MEGABYTES = 528;

    /** Die Regel für `validate()`. Laravel rechnet in Kilobyte. */
    public static function rule(): string
    {
        return 'max:'.(self::MEGABYTES * 1024);
    }

    public static function bytes(): int
    {
        return self::MEGABYTES * 1024 * 1024;
    }
}
