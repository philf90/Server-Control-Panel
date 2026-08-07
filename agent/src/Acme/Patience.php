<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Wie lange auf die Sichtbarkeit einer Aufgabe gewartet wird.
 *
 * **Warum das vom Anbieter kommt und nicht aus einer Konstante.** Bis zum
 * 7. August 2026 wartete {@see Order} für jede Bestellung dieselben 120
 * Sekunden. Das ist kürzer, als lego für drei der acht DNS-Anbieter für nötig
 * hält — netcup und IONOS bekommen dort 900 Sekunden, INWX 360. Eine
 * Bestellung, die nach zwei Minuten aufgibt, wo der Anbieter fünfzehn braucht,
 * verbrennt einen der fünf Fehlversuche je Konto und Stunde, **und die gelten
 * für jeden Kunden dieses Servers**.
 *
 * **Umgekehrt ist eine Frist für alle genauso falsch.** Fünfzehn Minuten für
 * IPv64.net hiessen: Eine Bestellung, die aus einem anderen Grund hängt, hält
 * eine Operation des Agenten eine Viertelstunde fest, statt nach einer Minute
 * mit einer brauchbaren Meldung zurückzukommen.
 *
 * **Die Zahlen sind die von lego**, weil sie aus dem Einsatz stammen und nicht
 * aus einer Schätzung. Wo sie sich als falsch erweisen, ändert man sie an einer
 * Stelle je Anbieter — und sieht dort auch, warum sie so ist.
 *
 * Gilt **nur** für das Warten auf die Sichtbarkeit. Wie lange die
 * Zertifizierungsstelle für ihre eigene Prüfung brauchen darf, ist eine andere
 * Frage und steht weiter in `Order::TIMEOUT_SECONDS`.
 */
final class Patience
{
    public function __construct(
        /** Wie lange insgesamt gewartet wird, in Sekunden. */
        public readonly int $seconds,
        /** Der Abstand zwischen zwei Fragen, in Sekunden. */
        public readonly int $interval,
    ) {}
}
