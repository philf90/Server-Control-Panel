<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Models\Operation;
use App\Support\Subscriptions\Lifecycle;
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
    /** @var list<class-string<AfterOperation>> */
    public const HANDLERS = [
        Lifecycle::class,
        WebLifecycle::class,
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
}
