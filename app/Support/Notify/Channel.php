<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Models\Finding;

/**
 * Ein Weg, auf dem eine Meldung den Server verlässt — B1, `docs/129 §7`.
 *
 * **Es gibt zwei, und sie haben verschiedene Empfänger.** {@see MailChannel}
 * schreibt dem **Kunden** über das Relay des Betreibers; {@see WebhookChannel}
 * meldet dem **Betreiber** an ein Ziel je Server. Sie sind deshalb keine zwei
 * Fassungen derselben Sache, sondern zwei Kanäle mit je eigener Frage: *„trägt
 * dieser Kanal diesen Befund"* ({@see carries}) und *„kommt er überhaupt
 * durch"* ({@see usable}).
 *
 * **Warum eine Schnittstelle und nicht zwei Zweige in {@see Notices}.** Die
 * Buchung je Kanal, die Entprellung und „zuletzt erfolgreich zugestellt" sind
 * für beide dasselbe; was sich unterscheidet, sind drei Methoden. Zwei Zweige
 * hiessen, die gemeinsame Hälfte zweimal zu schreiben — und die zweite ist die,
 * die veraltet.
 *
 * **Ein Kanal ist Code und keine Zeile in einer Tabelle.** `ChannelReachTest`
 * hält beide Richtungen: Jeder Kanal, den die Einstellungsseite anbietet, hat
 * eine Umsetzung, und jede Umsetzung steht auf der Seite. Eine Tabelle der
 * Kanäle wäre eine dritte Fassung derselben Liste.
 */
interface Channel
{
    /**
     * Der Schlüssel dieses Kanals.
     *
     * Er steht in `finding_notifications.channel` und in den Einstellungen
     * unter „zuletzt erfolgreich zugestellt". **Er ist kein Anzeigetext** — die
     * Beschriftung der Seite steht auf der Seite, wo sie auf Deutsch sein muss.
     */
    public function key(): string;

    /**
     * Ist dieser Kanal eingerichtet?
     *
     * **`false` heisst „nicht eingerichtet" und nicht „kaputt".** Ein Server
     * ohne Relay und ohne Meldeziel ist nicht fehlerhaft, er ist unvollständig
     * eingerichtet — und deshalb wird für einen unbenutzbaren Kanal **nichts**
     * gebucht: Eine Zeile in `finding_notifications` behauptete eine
     * Zustellung, die es nicht gab, und die Meldung wäre für immer fort.
     */
    public function usable(): bool;

    /**
     * Trägt dieser Kanal diesen Befund?
     *
     * **Die Frage gehört dem Kanal und nicht dem Befund.** Ein Befund weiss,
     * was er misst; wen das angeht, entscheidet der Weg hinaus. Stünde es am
     * Befund, müsste jede neue Prüfung jeden Kanal kennen — und die nächste
     * vergisst einen.
     */
    public function carries(Finding $finding): bool;

    /**
     * Zustellen, was zu **einem** Gegenstand gehört — in einer Nachricht.
     *
     * Ein Kunde, der Platz **und** Verkehr überzieht, bekommt eine Nachricht
     * mit zwei Zeilen und nicht zwei Nachrichten; das Abnahmekriterium sagt
     * „genau eine Mail", und zwei in derselben Minute sind für den Empfänger
     * genau das, wogegen es geschrieben ist.
     *
     * **Diese Methode wirft nicht.** Ein Fehlschlag ist ein Ergebnis und kein
     * Ausnahmezustand: Der Nachtlauf fährt danach weiter, der nächste Kanal
     * bekommt seine Gelegenheit, und was nicht ankam, bleibt fällig.
     *
     * @param  list<Finding>  $findings
     */
    public function deliver(string $subject, array $findings): Delivery;
}
