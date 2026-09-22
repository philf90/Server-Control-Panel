<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Enums\FindingCheck;
use App\Models\Finding;
use App\Models\FindingResolution;

/**
 * Ein Weg, auf dem eine Meldung den Server verlässt — B1, `docs/129 §7`.
 *
 * **Es gibt zwei.** {@see MailChannel} schreibt über das Relay des Betreibers —
 * an den **Kunden**, wenn der Befund sein Kontingent betrifft, und sonst an den
 * **Betreiber**. {@see WebhookChannel} meldet an ein Ziel je Server, das allein
 * dem Betreiber gehört.
 *
 * **Hier stand bis zum 24. September eine dritte Frage, `carries()`** — *„trägt
 * dieser Kanal diesen Befund"*. Mit der Erweiterung auf die übrigen siebzehn
 * Prüfungen tragen **beide** Kanäle **jeden** beurteilten Befund, und damit
 * antworteten zwei von zwei Umsetzungen dasselbe.
 *
 * > **Eine Erklärung, die fast immer dasselbe sagt, wird abgeschrieben statt
 * > beantwortet.** Der Satz hat am 20. September die vierte Methode an `Op`
 * > verhindert; er gilt hier genauso.
 *
 * Was bleibt, sind zwei echte Fragen: *„kommt dieser Kanal überhaupt durch"*
 * ({@see usable}) und *„wen fasst er zusammen"* ({@see batchKey}).
 *
 * **Die dritte hat seit demselben Tag einen eigenen Ort**, weil sie die Kanäle
 * wirklich trennt: Ob einer auch meldet, dass etwas **wieder in Ordnung** ist,
 * beantwortet {@see ResolvingChannel} — und zwar dadurch, dass es ihn gibt
 * oder nicht.
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
     * Nach welchem Schlüssel dieser Kanal seine Meldungen bündelt.
     *
     * **Die Bündelung folgt dem Empfänger und nicht dem Gegenstand.** Ein
     * Kunde, der Platz **und** Verkehr überzieht, bekommt eine Nachricht mit
     * zwei Zeilen und nicht zwei Nachrichten — und ein Betreiber, dessen
     * Server in einer Nacht einen toten Dienst und ein ablaufendes Zertifikat
     * hat, ebenso. Nach `subject` gebündelt wären das zwei Mails, und das
     * Abnahmekriterium sagt „genau eine".
     *
     * > **Eine Bündelung nach dem Gegenstand ist eine nach dem Absender.**
     *
     * Der Schlüssel ist **undurchsichtig**: Was er bedeutet, weiss nur der
     * Kanal, der ihn gebildet hat. {@see Notices} vergleicht ihn und liest ihn
     * nicht — sonst stünde die Zuordnung an zwei Stellen.
     *
     * **Er nimmt Prüfung und Gegenstand und nicht den Befund.** Bis zum
     * 21. September 2026 stand hier ein {@see Finding}; gebraucht hat keine
     * Umsetzung mehr als diese beiden Angaben, und eine
     * {@see FindingResolution} trägt genau sie — der Befund dahinter ist ja
     * fort. Ein zweites `batchKeyOf(FindingResolution)` wäre die zweite Fassung
     * derselben Zuordnung gewesen.
     *
     * > **Ein Argument, von dem der Aufgerufene zwei Felder liest, ist zwei
     * > Argumente.**
     */
    public function batchKey(FindingCheck $check, string $subject): string;

    /**
     * Zustellen, was **ein** Schlüssel zusammengefasst hat.
     *
     * **Der Gegenstand steht in den Befunden und nicht in einem Argument
     * daneben.** Er ist `$findings[0]->subject`; ihn zusätzlich zu übergeben
     * hiesse, dieselbe Angabe zweimal zu führen — und die zweite wäre die, die
     * beim nächsten Umbau nicht mitgeht.
     *
     * **Diese Methode wirft nicht.** Ein Fehlschlag ist ein Ergebnis und kein
     * Ausnahmezustand: Der Nachtlauf fährt danach weiter, der nächste Kanal
     * bekommt seine Gelegenheit, und was nicht ankam, bleibt fällig.
     *
     * @param  non-empty-list<Finding>  $findings
     */
    public function deliver(array $findings): Delivery;
}
