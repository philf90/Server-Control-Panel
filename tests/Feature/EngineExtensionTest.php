<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DatabaseEngine;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\PhpVersions;

/**
 * Kann die Website eines Kunden mit der Datenbank reden, die er hier bekommt?
 *
 * **Der Wächter zu dem Fund, für den es keinen gab.** `DatabaseEngine` ist am
 * 9. August 2026 entstanden und hat `postgres` mitgebracht;
 * {@see PhpVersions::EXTENSIONS} kannte weiter nur `mysql`. Zwischen beiden
 * gab es keinen Bezug — wortwörtlich das Muster, das `CLAUDE.md` als das
 * wiederkehrende beschreibt: *eine Zeichenkette, die auf etwas verweist, ohne
 * dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft.*
 *
 * Die Folge wäre nicht sichtbar gewesen. Das Panel legt die Datenbank an, der
 * Agent meldet Erfolg, im Bestand steht eine Zeile, der Kunde bekommt seine
 * Zugangsdaten — und seine Website antwortet *„could not find driver"*. Kein
 * roter Vorgang, kein Eintrag im Protokoll, nichts, wonach jemand suchen würde.
 *
 * **Dieser Test wäre an dem Tag rot geworden, an dem die Aufzählung entstand**,
 * also drei Beiträge vor dem Fund. Er beisst wieder, sobald ein drittes System
 * dazukommt — und das ist der Zweck: Wer eines hinzufügt, soll an der Frage
 * nicht vorbeikommen, wie der Kunde es erreicht.
 */
final class EngineExtensionTest extends TestCase
{
    /**
     * Jedes Datenbanksystem hat seine PHP-Erweiterung auf dem Server.
     */
    public function test_every_engine_has_its_php_extension_installed_with_every_version(): void
    {
        // Ein Ausdruck, der nichts findet, ist kein bestandener Test — und
        // eine Aufzählung, die leer wird, ebenfalls nicht.
        $this->assertNotSame([], DatabaseEngine::cases());

        foreach (DatabaseEngine::cases() as $engine) {
            $this->assertContains(
                $engine->phpExtension(),
                PhpVersions::EXTENSIONS,
                sprintf(
                    'Das Panel bietet %s an, und PhpVersions::EXTENSIONS holt „%s" nicht. Der Kunde '
                    .'bekäme seine Datenbank und keine Verbindung dazu: Seine Website antwortet '
                    .'„could not find driver", und im Panel sieht alles grün aus.',
                    $engine->label(),
                    $engine->phpExtension(),
                ),
            );
        }
    }

    /**
     * Und die Erweiterung landet auch in den Paketnamen.
     *
     * **Die Gegenrichtung, und sie ist nicht überflüssig.** `EXTENSIONS` ist
     * eine Liste von Paket*suffixen*; dass ein Eintrag darin bei
     * {@see PhpVersions::packages()} als `phpX.Y-<suffix>` herauskommt, ist die
     * Zusage, auf die sich der Test darüber verlässt. Bekäme `packages()`
     * jemals eine Ausnahmeliste — „dieses eine heisst anders" —, prüfte der
     * erste Test eine Zusage, die nicht mehr gilt.
     */
    public function test_the_extension_reaches_the_package_name(): void
    {
        foreach (DatabaseEngine::cases() as $engine) {
            $this->assertContains(
                'php8.4-'.$engine->phpExtension(),
                PhpVersions::packages('8.4'),
                sprintf('Der Suffix von %s wird nicht zu einem Paketnamen.', $engine->label()),
            );
        }
    }
}
