<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Passwords\Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Die Passwortrichtlinie — und die einzige Verbindung zwischen PHP und
 * TypeScript, die es dafür gibt.
 *
 * **Warum diese Verbindung ein Test sein muss.** Die Anforderungen entstehen in
 * `Policy` und werden über Inertia geteilt; geprüft werden sie zweimal — im
 * Browser für die Prüfliste, auf dem Server für die Validierung. Zwei
 * Implementierungen derselben Regel in zwei Sprachen laufen auseinander, und
 * zwar leise: Die Prüfliste zeigt weiter Haken, während die Validierung
 * ablehnt, oder umgekehrt eine Anforderung ohne Haken, die sich nie erfüllen
 * lässt. Beides sieht nach einem Fehler des Benutzers aus.
 *
 * Kein Typ und kein Aufruf verbindet die beiden Seiten. Dieser Test tut es.
 */
final class PasswordPolicyTest extends TestCase
{
    private function passwordComponent(): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/Components/PasswordFields.vue';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_component_checks_every_requirement_the_policy_declares(): void
    {
        $component = $this->passwordComponent();
        $missing = [];

        foreach (Policy::requirements() as $requirement) {
            // Der Schlüssel muss in CHECKS stehen — als Eigenschaft, gefolgt
            // von einer Pfeilfunktion. Ein bloßes Vorkommen des Wortes
            // irgendwo in der Datei würde auch ein Kommentar erfüllen.
            if (preg_match('/^\s+'.preg_quote($requirement['key'], '/').':\s*\(/m', $component) !== 1) {
                $missing[] = $requirement['key'];
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Diese Anforderungen stellt die Richtlinie, die Oberfläche prüft sie nicht:\n  %s\n\n".
            'In der Prüfliste stünde dann eine Anforderung, die nie einen Haken bekommt — '.
            'und der Benutzer sucht den Fehler bei sich.',
            implode("\n  ", $missing),
        ));
    }

    public function test_the_component_checks_nothing_the_policy_does_not_declare(): void
    {
        // Die Gegenrichtung. Eine Prüfung im Browser ohne Anforderung dahinter
        // ist die stillere Hälfte desselben Fehlers: Sie zeigt nichts an und
        // fällt deshalb nie auf — bis jemand die Anforderung streicht und die
        // Prüfung liegenbleibt.
        preg_match_all('/^\s+([a-z][a-zA-Z]*):\s*\(value/m', $this->passwordComponent(), $matches);

        $declared = array_column(Policy::requirements(), 'key');
        $extra = array_values(array_diff(array_unique($matches[1]), $declared));

        $this->assertSame([], $extra, sprintf(
            "Diese Prüfungen kennt die Oberfläche, die Richtlinie nennt sie nicht:\n  %s",
            implode("\n  ", $extra),
        ));
    }

    public function test_the_document_lists_every_requirement(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__, 2).'/docs/22-passwoerter.md');
        $missing = [];

        foreach (Policy::requirements() as $requirement) {
            if (! str_contains($document, '`'.$requirement['key'].'`')) {
                $missing[] = $requirement['key'];
            }
        }

        $this->assertSame([], $missing, sprintf(
            "docs/22 §2 nennt diese Anforderungen nicht:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function passwords(): array
    {
        return [
            'zu kurz' => ['Ab1!efgh', false],
            'nur Kleinbuchstaben' => ['passwortpasswort', false],
            'ohne Ziffer' => ['Passwortpasswort!', false],
            'ohne Sonderzeichen' => ['Passwortpasswort1', false],
            'ohne Großbuchstabe' => ['passwortpasswort1!', false],
            'erfüllt alles' => ['Passwortpasswort1!', true],
            'genau zwölf' => ['Ab1!efghijkl', true],
        ];
    }

    #[DataProvider('passwords')]
    public function test_the_policy_judges_a_password(string $password, bool $expected): void
    {
        $this->assertSame($expected, Policy::satisfied($password), sprintf(
            'Offen: %s',
            implode(', ', Policy::unmet($password)) ?: 'nichts',
        ));
    }

    public function test_a_generated_password_satisfies_the_policy(): void
    {
        // Hundert Läufe, weil der Fehler hier ein seltener wäre: Ein Verfahren,
        // das zufällig zieht und die Pflichtzeichen nicht erzwingt, liefert in
        // neunundneunzig von hundert Fällen etwas Gültiges — und im
        // hundertsten eine Fehlermeldung im Formular, die niemand nachstellen
        // kann.
        for ($i = 0; $i < 100; $i++) {
            $password = Policy::generate();

            $this->assertSame([], Policy::unmet($password), 'Erzeugt: '.$password);
            $this->assertGreaterThanOrEqual(Policy::MINIMUM_LENGTH, mb_strlen($password));
        }
    }

    public function test_a_generated_password_is_not_the_same_twice(): void
    {
        $seen = [];

        for ($i = 0; $i < 50; $i++) {
            $seen[] = Policy::generate();
        }

        $this->assertCount(50, array_unique($seen));
    }

    public function test_the_generated_length_never_falls_below_the_minimum(): void
    {
        // Ein Aufrufer, der eine kleinere Länge verlangt, bekommt trotzdem ein
        // gültiges Passwort. Andernfalls wäre `generate(8)` ein Weg, die
        // Richtlinie zu unterlaufen — mit einem Passwort, das der Server selbst
        // erzeugt hat.
        $this->assertGreaterThanOrEqual(Policy::MINIMUM_LENGTH, mb_strlen(Policy::generate(4)));
    }
}
