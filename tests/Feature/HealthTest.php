<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Die Bereitschaftsprüfung ist die Bedingung, unter der ein Update übernommen
 * wird. Ein Test dafür ist deshalb kein Beiwerk: Wenn sie eine Ausnahme wirft
 * statt 503 zu antworten, wertet der Update-Pfad das als „nicht bereit" —
 * richtig — oder als Fehler im Paket — falsch. Beides fällt hier auf.
 */
final class HealthTest extends TestCase
{
    public function test_reports_not_ready_when_the_agent_is_unreachable(): void
    {
        config(['cloudsrv.agent.socket' => '/nicht/vorhanden/agent.sock']);

        $response = $this->getJson('/gesundheit');

        $response->assertStatus(503);
        $response->assertJson(['ready' => false, 'agent' => 'nicht erreichbar']);
    }

    public function test_the_overview_renders_without_an_agent(): void
    {
        config(['cloudsrv.agent.socket' => '/nicht/vorhanden/agent.sock']);

        // Ohne Agent bleibt die Übersicht bedienbar und sagt, dass er schweigt.
        // Eine weiße Seite mit Stacktrace wäre die schlechtere Auskunft über
        // denselben Zustand.
        $this->get('/')->assertOk();
    }
}
