<?php

declare(strict_types=1);

namespace App\Mail\Notice;

use App\Mail\CustomerNotice;

/**
 * Ein Abschnitt der Mail an den Kunden — je Art von Befund einer (B9,
 * `docs/142 §4`).
 *
 * **Eine Mail je Abonnement, Empfänger und Nacht**, wie seit B5. Ein Kunde,
 * dessen Platz knapp wird und dessen Sicherung scheitert, bekommt eine Mail
 * mit zwei Abschnitten und nicht zwei Mails — zwei in derselben Minute sind
 * für den Empfänger genau das, wogegen „genau eine Mail" geschrieben ist.
 * {@see CustomerNotice} setzt die Abschnitte zusammen; was in einem steht,
 * weiss nur er selbst.
 *
 * **Fertig gebrochene Texte und keine Daten.** Die Vorlage gibt aus, was hier
 * steht, und jeder Abschnitt hält seine Zeilen unter 78 Zeichen.
 */
interface Section
{
    /**
     * Die Überschriften für den Betreff — je Grund, der in der Mail steht.
     *
     * @return non-empty-list<string>
     */
    public function headlines(): array;

    /**
     * Die Zeilen der Befunde: der Satz, darunter die Angaben.
     *
     * @return non-empty-list<string>
     */
    public function lines(): array;

    /**
     * Die Absätze dazu — nur zu dem, was in diesem Abschnitt steht.
     *
     * @return list<string> je Absatz ein fertig gebrochener Text
     */
    public function paragraphs(): array;
}
