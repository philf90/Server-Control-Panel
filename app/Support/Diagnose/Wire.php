<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

/**
 * Die Frage an die Leitung: Welches Zertifikat liefert der Webserver dieser
 * Maschine für einen Namen aus?
 *
 * Die zweite der zwei Fragen aus `docs/98 §3 E`. Die erste geht an die Datei
 * und beantwortet der Agent; diese hier braucht keinen — eine gesicherte
 * Verbindung zum eigenen Webserver darf jeder Prozess öffnen. Was sie fängt,
 * fängt nur sie: Die Datei liegt gültig da, und der Server liefert sie nicht
 * aus, weil der Block nicht neu geladen wurde oder die Anfrage auf den
 * Vorgabeblock fällt.
 *
 * > **Ein Beleg für den Weg ist keiner für das Ziel.** (`docs/78`)
 *
 * **Als Schnittstelle, weil die Antwort vom Netz kommt.** {@see TlsWire} öffnet
 * die Verbindung; die Wächter geben Fingerabdrücke vor und zählen, wie oft
 * gefragt wurde — denn gefragt wird nur für Domains, deren Datei in Ordnung ist
 * (Frage 3 in `docs/98 §9`, entschieden mit c).
 */
interface Wire
{
    /**
     * Der SHA-256-Fingerabdruck des ausgelieferten Zertifikats — mit SNI.
     *
     * **Der Fingerabdruck und nicht die Seriennummer** (`docs/81 §2.3o` M23):
     * Eine Seriennummer ist nur je Aussteller eindeutig, und dieses Panel
     * erzeugt selbstsignierte Zertifikate. Der Fingerabdruck beantwortet
     * dieselbe Frage ohne diesen Vorbehalt.
     *
     * `null`, wenn keine gesicherte Verbindung zustande kommt. Das ist hier der
     * gemessene Zustand und keine ausgefallene Messung: `tls.wire` kennt
     * deshalb kein `unreachable` (`FindingCheck`).
     */
    public function fingerprint(string $name): ?string;
}
