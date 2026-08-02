<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Authorization\RouteGuard;
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
        return array_values(iterator_to_array(Router::getRoutes()));
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
        return array_values($route->gatherMiddleware());
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
