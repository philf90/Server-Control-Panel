<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Was im Chroot gebraucht wird, ist vor dem Chroot geladen.
 *
 * **Dieser Wächter stand als Zusage im Plan, bevor er existierte — und genau
 * der Fehler, den er verhindern soll, ist in der Zwischenzeit passiert.**
 * `docs/51 §5.1` nennt die Falle als erste von vieren: Nach dem `chroot` liegt
 * `agent/src/` ausserhalb, der Autoloader findet nichts mehr, und eine Klasse,
 * die erst im Kind gebraucht wird, fehlt erst im Kind. Der erste Bau der
 * Dateiverwaltung lud in `Sandbox::preload()` nur `AgentException` — und jede
 * einzelne Datei-Operation endete mit
 * `Class "SrvPanel\Agent\Files\Entry" not found`, gemeldet als `internal`.
 *
 * > **Eine Falle, die man kennt und benennt, ist keine, in die man nicht
 * > fällt.** Sie ist nur eine, deren Ursache man schneller findet.
 *
 * Geprüft wird beides, was daraufhin gebaut wurde:
 *
 * 1. `preload()` zählt das Verzeichnis `Files/` **auf**, statt es
 *    aufzulisten — eine handgepflegte Liste wäre wieder eine Zeichenkette, die
 *    auf etwas verweist, ohne dass jemand den Bezug prüft.
 * 2. Im Kind hängt ein Autoloader, der eine verständliche Meldung wirft. Ohne
 *    ihn ist die nächste fehlende Klasse wieder ein `internal` ohne Hinweis.
 */
final class SandboxPreloadTest extends TestCase
{
    use WithoutPhpComments;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function source(): string
    {
        return $this->withoutComments(
            (string) file_get_contents($this->root().'/agent/src/Sandbox.php'),
        );
    }

    /**
     * Das Verzeichnis `Files/` wird aufgezählt und nicht abgetippt.
     */
    public function test_the_files_namespace_is_enumerated(): void
    {
        $this->assertMatchesRegularExpression(
            "/glob\(__DIR__\.'\/Files\/\*\.php'\)/",
            $this->source(),
            implode("\n", [
                'Sandbox::preload() zaehlt das Verzeichnis Files/ nicht mehr auf.',
                'Eine handgepflegte Liste veraltet beim naechsten Hinzufuegen — und',
                'sie faellt erst im Kind auf, also erst im Fehlerfall.',
            ]),
        );
    }

    /**
     * Und es gibt dort überhaupt etwas aufzuzählen.
     *
     * Der Nachbar der Null: Verschwände `Files/`, liefe die Aufzählung ins
     * Leere und dieser Wächter bliebe grün.
     */
    public function test_there_is_something_to_enumerate(): void
    {
        $files = glob($this->root().'/agent/src/Files/*.php') ?: [];

        $this->assertGreaterThan(
            1,
            count($files),
            'Unter agent/src/Files/ steht (fast) nichts — dann prueft die Aufzaehlung nichts.',
        );
    }

    /**
     * Eine fehlende Klasse im Kind sagt, dass sie fehlt.
     */
    public function test_a_missing_class_explains_itself(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            '/spl_autoload_register\(/',
            $source,
            'Im Kind haengt kein Autoloader, der eine fehlende Klasse erklaert.',
        );

        // **Dass es die Methode gibt, sagt nichts darüber, dass sie läuft.**
        // Der erste Entwurf dieses Wächters prüfte nur das Vorhandensein — und
        // blieb grün, als der Bruch den *Aufruf* entfernte und die Definition
        // stehenliess. Ein Autoloader, den niemand registriert, erklärt nichts.
        $this->assertStringContainsString(
            'self::explainMissingClasses();',
            $source,
            'explainMissingClasses() ist definiert, wird aber nicht gerufen.',
        );

        $this->assertStringContainsString(
            'Sandbox::preload()',
            $source,
            'Die Meldung nennt nicht die Stelle, an der die Klasse fehlt.',
        );
    }

    /**
     * Die Erklärung hängt, bevor die Arbeit läuft.
     *
     * Ein Autoloader, der nach dem `chroot` registriert würde, käme zu spät für
     * genau die Klassen, die beim Einsperren gebraucht werden.
     */
    public function test_the_explanation_is_registered_before_the_chroot(): void
    {
        $source = $this->source();

        // Ohne diese Zeile liefe der Vergleich unten gegen `false`, das als 0
        // gilt — und ein fehlender Aufruf sähe aus wie ein sehr früher.
        $this->assertStringContainsString(
            'self::explainMissingClasses();',
            $source,
            'explainMissingClasses() wird nicht gerufen.',
        );

        $this->assertGreaterThan(
            (int) strpos($source, 'self::explainMissingClasses()'),
            (int) strpos($source, 'chroot('),
            'Der erklaerende Autoloader wird erst nach dem chroot registriert.',
        );
    }

    /**
     * Und niemand ausserhalb der Sandbox arbeitet in `Files/`.
     *
     * Die Klassen unter `Files/` setzen voraus, dass sie im Chroot laufen —
     * ihre Pfade sind dort absolut. Wer sie ausserhalb ruft, deutet dieselben
     * Zeichenketten gegen das echte Wurzelverzeichnis, und `/httpdocs` ist dann
     * nicht das des Abonnements, sondern eines, das es hoffentlich nicht gibt.
     */
    public function test_the_files_helpers_are_used_only_through_the_workspace(): void
    {
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/agent/src', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($this->root().'/', '', $file->getPathname());

            if (str_starts_with($relative, 'agent/src/Files/') || $relative === 'agent/src/Sandbox.php') {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($file->getPathname()));

            // `Entry::of()` ausserhalb einer Sandbox-Arbeitsfunktion: Der
            // Aufruf steht dann nicht in einer Closure, die an run() geht.
            // Geprüft wird deshalb, dass die Datei überhaupt eine Workspace
            // benutzt, wenn sie Entry anfasst.
            if (preg_match('/Entry::of\s*\(/', $source) === 1
                && preg_match('/Workspace::(fromArgs|path)\s*\(/', $source) !== 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Eine Datei fasst Files\\Entry an, ohne eine Workspace zu benutzen.',
            'Ausserhalb des Chroots bezeichnen diese Pfade etwas anderes als gemeint.',
        ]));
    }
}
