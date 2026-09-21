<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die OpenAPI-Beschreibung von v1 (B7, `docs/131 §5`).
 *
 * **Eine Datei im Repo und kein Generator.** Entschieden vom Betreiber am
 * 21. September 2026: Ein Generator wäre eine weitere Abhängigkeit, und
 * richtig wäre sein Ergebnis auch nur dann, wenn jemand ihn laufen lässt — es
 * bräuchte also ohnehin einen Wächter. `OpenApiReachTest` hält die
 * Beschreibung in **beide** Richtungen gegen die Routen.
 *
 * **Offen, und das ist keine Auskunft über den Bestand.** Sie sagt, welche
 * Felder es gibt, und nicht, welche Abonnements. Ein Klient, der sie erst nach
 * einer Anmeldung bekäme, könnte seinen Zugang nicht einrichten, bevor er ihn
 * hat.
 */
final class SpecController extends Controller
{
    /** Wo die Beschreibung liegt — eine Stelle, die auch der Wächter liest. */
    public const PATH = 'docs/openapi-v1.yaml';

    public function show(): Response
    {
        $pfad = base_path(self::PATH);

        abort_unless(is_file($pfad), 404);

        return response(
            (string) file_get_contents($pfad),
            200,
            [
                'Content-Type' => 'application/yaml; charset=utf-8',

                /*
                 * Der Browser soll nicht raten, was er bekommen hat — dieselbe
                 * Zeile und derselbe Grund wie beim Logo aus B6.
                 */
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
