<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Models\Operation;
use App\Support\Databases\DbLifecycle;
use App\Support\Databases\DumpLifecycle;
use App\Support\Databases\PgLifecycle;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tls\CertificateLifecycle;
use App\Support\Web\WebLifecycle;

/**
 * Wer nach einem Vorgang den Bestand nachzieht — die vollständige Liste.
 *
 * **Warum es diese Liste gibt.** Bis P2 kannte der Arbeiter genau einen
 * Lebenslauf und rief ihn direkt auf. Mit P3 kommt der zweite dazu, und damit
 * die Frage, die dieses Projekt schon mehrfach teuer beantwortet hat: Was
 * passiert, wenn jemand einen dritten schreibt und den Arbeiter nicht anfasst?
 * Antwort ohne diese Liste: Der Vorgang läuft durch, der Agent tut seine
 * Arbeit, und im Panel ändert sich nichts — ohne Fehler, ohne Meldung.
 *
 * `LifecycleReachTest` hält deshalb beide Richtungen zusammen: Jede Umsetzung
 * von {@see AfterOperation} steht hier, und jeder Eintrag hier ist eine.
 */
final class Lifecycles
{
    /**
     * **Die Reihenfolge ist keine Aufzählung, sondern eine Zusage.**
     * {@see Lifecycle} steht zuerst, weil er den Zustand des Abonnements setzt;
     * alle danach lesen ihn frisch aus der Datenbank. Liefe es umgekehrt, trüge
     * jeder Server-Block und jede Datenbanksperre noch den Zustand von vorher —
     * die Sperre stünde im Panel und die Webseite antwortete weiter.
     *
     * @var list<class-string<AfterOperation>>
     */
    public const HANDLERS = [
        Lifecycle::class,
        WebLifecycle::class,
        CertificateLifecycle::class,
        DbLifecycle::class,

        // P5b. Neben und nicht in DbLifecycle: Die Antworten der beiden
        // Rückbauoperationen haben nicht dieselbe Form (docs/38 §8).
        PgLifecycle::class,

        /*
         * **Eine Klasse je Gegenstand, nicht je System** (`docs/38 §21`,
         * Entscheidung 10). Was mit einer Sicherung geschieht, hängt an keinem
         * Datenbanksystem — nur die Namen der vier Aufgaben tun das, und die
         * stehen in `DumpLifecycle::tasks()`.
         */
        DumpLifecycle::class,
    ];

    /**
     * Alle Aufgaben, die irgendein Lebenslauf beantwortet.
     *
     * @return list<string>
     */
    public static function handled(): array
    {
        $tasks = [];

        foreach (self::HANDLERS as $handler) {
            foreach ($handler::handles() as $task) {
                $tasks[$task] = true;
            }
        }

        return array_keys($tasks);
    }

    public function afterSuccess(Operation $operation): void
    {
        foreach (self::HANDLERS as $handler) {
            /** @var AfterOperation $lifecycle */
            $lifecycle = app($handler);

            $lifecycle->afterSuccess($operation);
        }
    }

    /**
     * Und dasselbe, wenn der Vorgang gescheitert ist.
     *
     * **Die Reihenfolge ist hier nicht dieselbe Zusage wie oben.** Beim Erfolg
     * baut einer auf dem Zustand des vorigen auf; beim Fehlschlag räumt jeder
     * nur sein eigenes auf. Sie bleibt trotzdem gleich, weil zwei Reihenfolgen
     * für dieselbe Liste eine Einladung sind, die falsche zu lesen.
     */
    public function afterFailure(Operation $operation): void
    {
        foreach (self::HANDLERS as $handler) {
            /** @var AfterOperation $lifecycle */
            $lifecycle = app($handler);

            $lifecycle->afterFailure($operation);
        }
    }
}
