<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateToken;
use App\Support\Authorization\RouteGuard;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Die mechanische Vollständigkeitsprüfung der Routen (§6.2.2).
 *
 * Autorisierung an der Aktion statt am Menüpunkt ist eine Zusage, die sich
 * nicht durch Sorgfalt halten lässt: Eine neue Route entsteht in einem
 * Nebensatz, und ob jemand dabei an die Rechte gedacht hat, sieht man einem
 * Diff nicht an. Deshalb prüft das hier eine Maschine.
 *
 * Der Test hat drei Richtungen, und die zweite und dritte sind die, die man
 * beim Schreiben so eines Tests vergisst:
 *
 * 1. Jede Route trägt eine Policy (`can:`) oder steht mit Begründung in der
 *    Registratur.
 * 2. Jede Eintragung gehört zu einer Route, die es noch gibt — sonst wächst
 *    die Registratur über Jahre und deckt irgendwann etwas, an das niemand
 *    mehr gedacht hat.
 * 3. Die Eintragung stimmt mit dem überein, was der Router tut. Was als „nur
 *    mit Anmeldung" deklariert ist, muss `auth` tragen; was als „öffentlich"
 *    deklariert ist, darf es nicht.
 */
final class RouteAuthorizationTest extends TestCase
{
    /** @return list<Route> */
    private function routes(): array
    {
        return array_values(Router::getRoutes()->getRoutes());
    }

    private function key(Route $route): string
    {
        return RouteGuard::key($route->methods(), $route->uri());
    }

    /**
     * Die Middleware einer Route.
     *
     * Nicht `list<string>`: In `gatherMiddleware()` koennen auch Closures
     * stehen. Die Aufrufer pruefen deshalb mit `is_string`, bevor sie
     * vergleichen.
     *
     * @return list<mixed>
     */
    private function middleware(Route $route): array
    {
        /*
         * **Abzüglich dessen, was die Route ausdrücklich ablegt.**
         * `gatherMiddleware()` sammelt Gruppe und Route zusammen und weiss von
         * `withoutMiddleware()` nichts — die Ausschlüsse stehen daneben. Ohne
         * sie läse dieser Wächter `api/v1/openapi.yaml` als „braucht ein
         * Konto", obwohl die Route ihre Wache ablegt, und die Eintragung
         * „öffentlich" wäre fälschlich falsch.
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

    private function hasPolicy(Route $route): bool
    {
        foreach ($this->middleware($route) as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                return true;
            }
        }

        return false;
    }

    private function requiresAccount(Route $route): bool
    {
        foreach ($this->middleware($route) as $middleware) {
            if ($middleware === 'auth' || (is_string($middleware) && str_starts_with($middleware, 'auth:'))) {
                return true;
            }

            /*
             * **Die API verlangt ihr Konto über eine andere Wache.**
             * {@see AuthenticateToken} löst es aus einer Zugangsmarke statt
             * aus einer Sitzung; für die Frage „braucht diese Route ein
             * Konto?" ist das dasselbe. Ohne diese Zeile stünde jede
             * api-Route als „öffentlich" da — und die Begründung daneben wäre
             * falsch, nicht die Route.
             */
            if ($middleware === AuthenticateToken::class) {
                return true;
            }
        }

        return false;
    }

    public function test_every_route_is_either_guarded_or_declared(): void
    {
        $declarations = RouteGuard::declarations();
        $undeclared = [];

        foreach ($this->routes() as $route) {
            if ($this->hasPolicy($route)) {
                continue;
            }

            $key = $this->key($route);

            if (! array_key_exists($key, $declarations)) {
                $undeclared[] = $key;
            }
        }

        $this->assertSame([], $undeclared, sprintf(
            "Diese Routen tragen weder eine Policy noch eine Begründung:\n  %s\n\n".
            'Entweder eine `can:`-Middleware anhängen — oder in '.
            'App\\Support\\Authorization\\RouteGuard eintragen und dort hinschreiben, warum das vertretbar ist.',
            implode("\n  ", $undeclared),
        ));
    }

    public function test_no_declaration_outlives_its_route(): void
    {
        $live = [];

        foreach ($this->routes() as $route) {
            $live[$this->key($route)] = true;
        }

        $stale = array_values(array_diff(array_keys(RouteGuard::declarations()), array_keys($live)));

        $this->assertSame([], $stale, sprintf(
            "Diese Eintragungen gehören zu keiner Route mehr:\n  %s\n\n".
            'Eine Ausnahme, die niemand mehr braucht, deckt irgendwann etwas, an das niemand mehr denkt.',
            implode("\n  ", $stale),
        ));
    }

    public function test_a_declaration_says_what_the_router_does(): void
    {
        $declarations = RouteGuard::declarations();
        $wrong = [];

        foreach ($this->routes() as $route) {
            $key = $this->key($route);
            $declaration = $declarations[$key] ?? null;

            if ($declaration === null) {
                continue;
            }

            $needsAccount = $this->requiresAccount($route);

            if ($declaration['kind'] === RouteGuard::AUTHENTICATED && ! $needsAccount) {
                $wrong[] = "{$key}: als „nur mit Anmeldung\" eingetragen, trägt aber keine auth-Middleware";
            }

            if ($declaration['kind'] === RouteGuard::OPEN && $needsAccount) {
                $wrong[] = "{$key}: als „öffentlich\" eingetragen, trägt aber auth — die Begründung passt nicht";
            }
        }

        $this->assertSame([], $wrong, "Registratur und Router widersprechen sich:\n  ".implode("\n  ", $wrong));
    }

    public function test_every_declaration_carries_a_reason(): void
    {
        foreach (RouteGuard::declarations() as $key => $declaration) {
            $this->assertContains(
                $declaration['kind'],
                [RouteGuard::OPEN, RouteGuard::AUTHENTICATED, RouteGuard::SIGNED],
                "{$key}: unbekannte Art der Freistellung.",
            );

            // Eine Ausnahme ohne Begründung ist eine Ausnahme, über die
            // niemand nachgedacht hat.
            $this->assertGreaterThan(
                40,
                mb_strlen($declaration['reason']),
                "{$key}: die Begründung ist zu knapp, um eine zu sein.",
            );
        }
    }
}
