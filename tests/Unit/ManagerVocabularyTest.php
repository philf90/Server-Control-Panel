<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\FilterState;

/**
 * Jeder Verwalter, den der Agent aussprechen kann, ist dem Panel bekannt.
 *
 * **Dieselbe Naht wie `DiagnoseSeamTest`, und aus demselben Grund.** Der Agent
 * gibt einen Schlüssel zurück, die Seite macht daraus ein Wort. Kommt ein
 * Schlüssel dazu, den die Seite nicht kennt, steht dort entweder ein englischer
 * Rohwert oder — schlimmer — „nicht feststellbar" für einen Zustand, der sehr
 * wohl feststeht.
 *
 * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
 * > beiden ist der Ort, an dem man nachsieht.**
 *
 * Geprüft wird **in beide Richtungen**: Ein Eintrag auf der Seite, den es im
 * Agenten nicht gibt, ist ein toter Eintrag, und der entsteht bei einer
 * Umbenennung — man trägt den neuen Namen nach und lässt den alten liegen.
 *
 * Framework-frei: gelesen wird die `.vue` als Text.
 */
final class ManagerVocabularyTest extends TestCase
{
    private const SEITE = __DIR__.'/../../resources/js/Pages/Services/Index.vue';

    /**
     * Die Zuordnung Schlüssel → Wort, aus der Seite gelesen.
     *
     * @return list<string>
     */
    private function seite(): array
    {
        $quelle = file_get_contents(self::SEITE);

        $this->assertIsString($quelle, 'Die Seite ist nicht lesbar.');

        $anfang = strpos($quelle, 'const VERWALTER');

        $this->assertNotFalse($anfang, 'Die Zuordnung heisst nicht mehr VERWALTER — dann prüft dieser Wächter nichts.');

        $ende = strpos($quelle, '}', $anfang);

        $this->assertNotFalse($ende);

        preg_match_all('/^\s*(\w+):/m', substr($quelle, $anfang, $ende - $anfang), $treffer);

        return $treffer[1];
    }

    /** Was der Agent sagen kann, kann die Seite lesen. */
    public function test_every_manager_the_agent_can_say_is_known_to_the_page(): void
    {
        $seite = $this->seite();

        // Untergrenze: Läuft der Ausdruck ins Leere, meldet er sonst „alles
        // bekannt" für null gefundene Einträge.
        $this->assertGreaterThanOrEqual(
            count(FilterState::MANAGERS),
            count($seite),
            'Die Zuordnung auf der Seite ist kürzer als die Grundmenge des Agenten — oder der Ausdruck findet sie nicht.',
        );

        foreach (FilterState::MANAGERS as $schluessel) {
            $this->assertContains(
                $schluessel,
                $seite,
                sprintf('Der Agent kann „%s" sagen, und die Seite kennt das Wort nicht.', $schluessel),
            );
        }
    }

    /**
     * Und kein Wort auf der Seite ohne einen Zustand dahinter.
     *
     * **So entsteht ein toter Eintrag wirklich:** bei einer Umbenennung. Man
     * trägt den neuen Namen nach, die erste Richtung ist wieder grün, und der
     * alte bleibt liegen — dieselbe Lehre wie bei `PackagingTest` und dem
     * `case`-Zweig des Wrappers.
     */
    public function test_every_word_on_the_page_has_a_state_behind_it(): void
    {
        foreach ($this->seite() as $schluessel) {
            $this->assertContains(
                $schluessel,
                FilterState::MANAGERS,
                sprintf('Die Seite kennt „%s", und der Agent kann es nicht sagen.', $schluessel),
            );
        }
    }
}
