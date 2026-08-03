<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Die Gegenrichtung zur Routenprüfung: Führt zu jeder Fähigkeit auch ein Weg?
 *
 * **Warum es diesen Test gibt.** `RouteAuthorizationTest` prüft, dass jede
 * Route eine Policy trägt. Das ist die Richtung, in der ein Fehler gefährlich
 * ist — eine ungeschützte Route. Die andere Richtung fiel deshalb nie auf, und
 * genau dort lag im August 2026 eine Lücke: `CustomerPolicy::delete()` stand
 * da, `Customer` benutzte `SoftDeletes`, die Nummernvergabe fragte
 * `withTrashed()`, und die Anmeldung wies Konten eines zurückgezogenen Kunden
 * ab — die halbe Mechanik war gebaut. Es gab nur keine Route, keine
 * Controllermethode und keinen Knopf. Ein Kunde liess sich ausschliesslich
 * über die Datenbank zurückziehen, und niemandem fiel es auf, weil alles,
 * *was* da war, richtig war.
 *
 * Eine Fähigkeit ohne Weg ist kein Sicherheitsproblem. Sie ist etwas anderes:
 * eine Zusage im Quelltext, die es in der Anwendung nicht gibt. Wer sie liest,
 * hält eine Funktion für vorhanden.
 *
 * **Was als Weg zählt:** eine Route mit `can:<fähigkeit>,<modell>`, ein
 * `authorize('<fähigkeit>'` oder ein `Gate::`-Aufruf im Anwendungscode. Nicht
 * gezählt wird der blosse Name — `can:delete,plan` deckt nicht
 * `CustomerPolicy::delete`.
 */
final class PolicyReachTest extends TestCase
{
    /**
     * Fähigkeiten ohne Weg — mit Begründung, und die Begründung ist die Hälfte.
     *
     * Eine Liste ohne Grund je Eintrag wächst über Jahre, und irgendwann steht
     * darin, was jemand nicht nachziehen wollte. Deshalb steht der Grund im
     * Wert und nicht in einem Kommentar daneben.
     *
     * @var array<string, string>
     */
    private const WITHOUT_ROUTE = [
        // Sie sagen „niemand, auch kein Admin" und geben `false` zurück. Eine
        // Route dazu wäre gerade das, was sie ausschliessen: Ein Protokoll,
        // das sich bearbeiten lässt, ist als Nachweis wertlos.
        'AuditEventPolicy::update' => 'verweigert grundsätzlich — eine Route dazu wäre der Fehler',
        'AuditEventPolicy::delete' => 'verweigert grundsätzlich — eine Route dazu wäre der Fehler',

        // Das Protokoll hat eine Liste und einen Export, keine Einzelansicht.
        // `view` filtert die Liste (AuditQuery::visibleTo) und ist damit
        // erreichbar, nur nicht über eine eigene Route.
        'AuditEventPolicy::view' => 'filtert die Liste, hat keine Einzelansicht',

        // Ein Kunde sieht seine Kontingente an seinem Abonnement und nicht am
        // Plan; die Planseite ist Betreibersache. `view` beantwortet trotzdem
        // eine echte Frage — welcher Plan gehört zu einem Abonnement, das
        // dieses Konto sehen darf — und wird gebraucht, sobald der Kunde den
        // Plannamen angezeigt bekommt.
        'PlanPolicy::view' => 'Kundensicht auf den eigenen Plan, noch ohne eigene Seite',

        // Der Einstieg für alles, was ab P3 dazukommt: Datenbanken, DNS, Cron,
        // Sicherungen. Sie prüft drei Bedingungen auf einmal und steht
        // absichtlich vor den Modulen, die sie brauchen werden.
        'SubscriptionPolicy::useFeature' => 'für die Module ab P3, die es noch nicht gibt',
    ];

    /** Was der Router und der Anwendungscode an Autorisierung enthalten. */
    private function sources(): string
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        foreach (['Http/Controllers', 'Support', 'Jobs', 'Models'] as $directory) {
            foreach (glob(dirname(__DIR__, 2).'/app/'.$directory.'/*.php') ?: [] as $path) {
                $text .= (string) file_get_contents($path);
            }
        }

        return $text;
    }

    /** Der Modellname zu einer Policy: CustomerPolicy → Customer. */
    private function model(string $policy): string
    {
        return (string) preg_replace('/Policy$/', '', $policy);
    }

    public function test_every_ability_can_be_reached(): void
    {
        $sources = $this->sources();
        $unreachable = [];
        $checked = 0;

        foreach (glob(dirname(__DIR__, 2).'/app/Policies/*.php') ?: [] as $path) {
            $policy = basename($path, '.php');
            $model = $this->model($policy);

            $class = new ReflectionClass('App\\Policies\\'.$policy);

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $ability = $method->getName();

                if ($method->isConstructor() || $method->class !== $class->getName()) {
                    continue;
                }

                $checked++;

                if (array_key_exists($policy.'::'.$ability, self::WITHOUT_ROUTE)) {
                    continue;
                }

                // `can:view,customer` (Modellbindung), `can:viewAny,'.Customer::class`
                // (Klassenname) oder ein Aufruf im Code.
                $patterns = [
                    '/can:'.$ability.','.lcfirst($model).'\b/',
                    '/can:'.$ability.",'\\.".$model.'::class/',
                    '/authorize\(\s*[\'"]'.$ability.'[\'"]/',
                    '/Gate::[a-zA-Z]+\(\s*[\'"]'.$ability.'[\'"]/',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $sources) === 1) {
                        continue 2;
                    }
                }

                $unreachable[] = $policy.'::'.$ability;
            }
        }

        $this->assertGreaterThan(15, $checked, 'Es werden kaum Fähigkeiten gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $unreachable, sprintf(
            "Zu diesen Fähigkeiten führt kein Weg — keine Route, kein Aufruf:\n  %s\n\n".
            'Entweder fehlt die Route (das war der Fall bei CustomerPolicy::delete), oder die Fähigkeit '.
            'gehört mit Begründung in PolicyReachTest::WITHOUT_ROUTE.',
            implode("\n  ", $unreachable),
        ));
    }

    public function test_every_exception_still_exists(): void
    {
        // Dieselbe Sorgfalt wie bei der Routenregistratur: Eine Ausnahme, die
        // auf eine gelöschte Methode zeigt, deckt nichts mehr — sie steht nur
        // noch da und sieht nach einer Entscheidung aus.
        foreach (array_keys(self::WITHOUT_ROUTE) as $entry) {
            [$policy, $ability] = explode('::', $entry);

            $this->assertTrue(
                method_exists('App\\Policies\\'.$policy, $ability),
                $entry.' steht in der Ausnahmeliste, die Methode gibt es nicht mehr.',
            );
        }
    }
}
