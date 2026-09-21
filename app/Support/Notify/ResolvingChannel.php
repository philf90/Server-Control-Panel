<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Models\FindingResolution;

/**
 * Ein Kanal, der auch meldet, dass etwas **wieder in Ordnung** ist — B1.
 *
 * ## Warum nicht jeder Kanal das tut
 *
 * Eine Entwarnung ist für einen Empfänger, der einen **Zustand führt**. Ein
 * Vorfallsystem hinter einem Eingangshaken hält den Vorfall offen, bis jemand
 * ihn schliesst; ohne diese Meldung schliesst ihn niemand, und der Leser
 * gewöhnt sich daran, dass dort immer etwas steht.
 *
 * > **Ein Kanal, der nur meldet, dass etwas kaputt ist, erzieht seinen Leser
 * > dazu, ihn zu ignorieren.**
 *
 * Ein Postfach führt keinen Zustand, und für es wiegt die andere Hälfte
 * schwerer: **Eine Entwarnung hat keine Entprellung.** Gemeldet wird, was
 * {@see Notices::HOLD_HOURS} lang stand — entwarnt wird, sobald der Befund fort
 * ist. Ein Kontingent, das um seine Schwelle schwankt, ergäbe damit je Nacht
 * eine Warnung und eine Entwarnung im Postfach des Kunden; für ein
 * Vorfallsystem ist genau dieselbe Folge richtig, weil sie den Zustand
 * nachzeichnet.
 *
 * > **Dieselbe Meldung ist für den einen Empfänger die Auskunft, die er
 * > braucht, und für den anderen die, die ihn abstumpfen lässt.**
 *
 * ## Warum eine zweite Schnittstelle und kein `resolves(): bool`
 *
 * Eine Fahne liesse `deliverResolved()` an jedem Kanal stehen, auch an dem, der
 * sie nie beantworten darf — und ein Rückgabewert für einen Aufruf, den es
 * nicht geben soll, ist entweder eine Lüge oder ein Wurf. Hier gibt es die
 * Methode nur dort, wo sie etwas bedeutet.
 *
 * > **Ein Zustand, den es nicht geben darf, wird nicht geprüft, sondern
 * > unmöglich gemacht.**
 *
 * `NoticeResolveTest` hält daneben, dass diese Frage die Kanäle wirklich
 * **trennt** — mindestens einer entwarnt, mindestens einer nicht. Das ist die
 * Lehre aus `Channel::carries()`, das am 24. September verschwand, weil zwei
 * von zwei Umsetzungen dasselbe antworteten:
 *
 * > **Eine Frage, die alle Umsetzungen gleich beantworten, ist keine Frage.**
 */
interface ResolvingChannel extends Channel
{
    /**
     * Zustellen, dass diese Befunde fort sind.
     *
     * **Der Gegenstand steht in den Zeilen und nicht daneben** — derselbe Grund
     * wie bei {@see Channel::deliver()}. Und wie dort wirft diese Methode
     * nicht: Ein Fehlschlag ist ein Ergebnis, die Zeilen bleiben liegen, und
     * der nächste Lauf versucht es wieder.
     *
     * @param  non-empty-list<FindingResolution>  $resolutions
     */
    public function deliverResolved(array $resolutions): Delivery;
}
