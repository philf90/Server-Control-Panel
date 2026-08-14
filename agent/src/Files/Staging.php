<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Files;

/**
 * Wo eine hochgeladene Datei liegt, bevor sie ins Abonnement geht.
 *
 * **Ein eigener Ort und nicht der von `db.dump.import`.** Beide sind
 * Zwischenlager für Hochgeladenes, und beide liegen im Schreibbereich des
 * Panels — aber sie zusammenzulegen hiesse, dass eine Operation, die
 * Datenbanksicherungen einspielt, und eine, die Kundendateien verteilt,
 * denselben Vorrat lesen. Zwei Positivlisten, die auf dasselbe Verzeichnis
 * zeigen, sind eine Positivliste.
 *
 * Der Pfad steht als Konstante hier und wird nicht entgegengenommen; das Paket
 * legt ihn beim Einrichten an.
 */
final class Staging
{
    /**
     * Der Schreibbereich des Panels für Datei-Uploads.
     *
     * Er liegt unter `storage/app/private`, weil das Panel dort ohnehin
     * schreiben darf und der Webserver von dort nichts ausliefert.
     */
    public const ROOT = '/var/lib/srvpanel/storage/app/private/uploads';
}
