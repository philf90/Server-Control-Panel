<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\SpecController;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Die Beschreibung und die Routen halten einander — in **beide** Richtungen.
 *
 * ## Warum beide
 *
 * Die erste Richtung („jede Route steht in der Beschreibung") fängt die neue
 * Route, die niemand nachgetragen hat. Die zweite („jeder Pfad ist eine
 * Route") fängt den toten Eintrag — und **so entsteht er wirklich**: Bei einer
 * Umbenennung trägt man den neuen Pfad nach, die erste Richtung ist danach
 * wieder grün, und der alte bleibt liegen.
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt
 * > — und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 *
 * ## Gelesen wird die Einrückung und kein Parser
 *
 * Dieses Repo hat kein YAML in `vendor/` (gemessen: weder `symfony/yaml` noch
 * die PHP-Erweiterung). Die Pfade stehen unter `paths:` mit genau zwei
 * Leerzeichen Einrückung; alles darunter ist tiefer. Das ist deterministisch
 * und braucht nichts, was nicht da ist — und die Untergrenze fängt den Fall,
 * dass der Ausdruck ins Leere greift.
 */
final class OpenApiReachTest extends TestCase
{
    /** Wie viele Pfade mindestens zusammenkommen müssen. */
    private const AT_LEAST = 5;

    /** Das Präfix, unter dem die Routen im Router stehen. */
    private const PREFIX = 'api/v1';

    /**
     * Die Pfade der Beschreibung.
     *
     * @return list<string>
     */
    private function documented(): array
    {
        $datei = dirname(__DIR__, 2).'/'.SpecController::PATH;

        self::assertFileExists($datei, 'Die Beschreibung fehlt — die offene Route liefert dann 404.');

        $zeilen = explode("\n", (string) file_get_contents($datei));

        $pfade = [];
        $drin = false;

        foreach ($zeilen as $zeile) {
            if ($zeile === 'paths:') {
                $drin = true;

                continue;
            }

            if (! $drin) {
                continue;
            }

            /* Eine Zeile am linken Rand beendet den Block. */
            if ($zeile !== '' && ! str_starts_with($zeile, ' ')) {
                break;
            }

            if (preg_match('/^  (\/\S*):\s*$/', $zeile, $treffer) === 1) {
                $pfade[] = $treffer[1];
            }
        }

        return $pfade;
    }

    /**
     * Die Routen unter `api/v1`, ohne ihr Präfix.
     *
     * @return list<string>
     */
    private function routed(): array
    {
        $this->app?->make(HttpKernel::class);

        $pfade = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), self::PREFIX.'/')) {
                continue;
            }

            $pfade[] = substr($route->uri(), strlen(self::PREFIX));
        }

        sort($pfade);

        return array_values(array_unique($pfade));
    }

    public function test_every_route_stands_in_the_description(): void
    {
        $beschrieben = $this->documented();
        $gefahren = $this->routed();

        self::assertGreaterThanOrEqual(self::AT_LEAST, count($gefahren),
            'Es werden kaum Routen unter api/v1 gefunden — dann prüft dieser Fall nichts.');

        $fehlen = array_values(array_diff($gefahren, $beschrieben));

        self::assertSame([], $fehlen, sprintf(
            "Diese Routen stehen in keiner Beschreibung:\n  %s\n\n".
            'Ein Klient, der die Beschreibung liest, kennt sie nicht — und für ihn gibt es sie nicht.',
            implode("\n  ", $fehlen),
        ));
    }

    public function test_every_documented_path_is_a_route(): void
    {
        $beschrieben = $this->documented();
        $gefahren = $this->routed();

        self::assertGreaterThanOrEqual(self::AT_LEAST, count($beschrieben),
            'Es werden kaum Pfade in der Beschreibung gefunden — dann prüft dieser Fall nichts.');

        $tot = array_values(array_diff($beschrieben, $gefahren));

        self::assertSame([], $tot, sprintf(
            "Diese Pfade der Beschreibung gibt es nicht:\n  %s\n\n".
            'So entsteht ein toter Eintrag wirklich: Bei einer Umbenennung trägt man den neuen '.
            'Pfad nach, und der alte bleibt liegen.',
            implode("\n  ", $tot),
        ));
    }

    /** Und sie wird ausgeliefert — offen, mit ihrem Typ und ohne Raten. */
    public function test_the_description_is_served(): void
    {
        $antwort = $this->get('/api/v1/openapi.yaml');

        $antwort->assertOk();
        $antwort->assertHeader('X-Content-Type-Options', 'nosniff');

        self::assertStringStartsWith('application/yaml', (string) $antwort->headers->get('Content-Type'));
        self::assertStringContainsString('openapi:', $antwort->getContent() ?: '');
    }
}
