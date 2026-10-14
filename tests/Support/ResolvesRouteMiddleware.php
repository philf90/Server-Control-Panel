<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Die Mittelschichten einer Route, so wie sie am Ende laufen.
 *
 * Herausgezogen am 14. Oktober 2026 aus `RouteAuthorizationTest`, als
 * `ImpersonationDoorTest` dieselbe Frage stellen musste. Eine zweite Fassung
 * hätte die Fallen unten ein zweites Mal gebraucht, und die zweite Fassung ist
 * die, die veraltet.
 *
 * Nicht `list<string>`: In `gatherMiddleware()` können auch Closures stehen.
 * Die Aufrufer prüfen deshalb mit `is_string`, bevor sie vergleichen.
 */
trait ResolvesRouteMiddleware
{
    /** @return list<mixed> */
    private function resolvedMiddleware(Route $route): array
    {
        /*
         * **Abzüglich dessen, was die Route ausdrücklich ablegt.**
         * `gatherMiddleware()` sammelt Gruppe und Route zusammen und weiss von
         * `withoutMiddleware()` nichts — die Ausschlüsse stehen daneben. Ohne
         * sie läse ein Wächter `api/v1/openapi.yaml` als „braucht ein Konto",
         * obwohl die Route ihre Wache ablegt.
         *
         * > **Eine Liste, die nur das Hinzugefügte kennt, beschreibt nicht,
         * > was am Ende läuft.**
         */
        $abgelegt = $route->excludedMiddleware();

        /*
         * **Gruppen werden aufgelöst.** `gatherMiddleware()` gibt den
         * **Namen** einer Gruppe zurück und nicht ihre Mitglieder — für eine
         * Seite fällt das nicht auf, weil `auth` dort an der Route steht. Eine
         * api-Route trägt `['api']` und sonst nichts, und die Wache steckt in
         * der Gruppe.
         *
         * > **Eine Liste, die einen Namen statt seines Inhalts nennt, ist
         * > vollständig und beantwortet die Frage trotzdem nicht.**
         */
        /*
         * **Der HTTP-Kernel wird angefasst, bevor gefragt wird.** Die Gruppen
         * kommen aus `withMiddleware(…)` und stehen erst im Router, nachdem
         * der Kernel sie dorthin gespiegelt hat (`syncMiddlewareToRouter()`).
         * Ein Fall, der keine Anfrage schickt, fragte sonst eine leere Liste —
         * und eine leere Liste löst keine Gruppe auf und meldet trotzdem
         * nichts.
         */
        $this->app?->make(HttpKernel::class);

        $gruppen = Router::getMiddlewareGroups();
        $aufgeloest = [];

        foreach ($route->gatherMiddleware() as $eintrag) {
            if (is_string($eintrag) && array_key_exists($eintrag, $gruppen)) {
                foreach ($gruppen[$eintrag] as $mitglied) {
                    $aufgeloest[] = $mitglied;
                }

                continue;
            }

            $aufgeloest[] = $eintrag;
        }

        return array_values(array_filter(
            $aufgeloest,
            static fn (mixed $m): bool => ! in_array($m, $abgelegt, true),
        ));
    }
}
