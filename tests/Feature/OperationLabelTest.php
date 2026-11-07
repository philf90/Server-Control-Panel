<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Operations\Lifecycles;
use App\Support\Operations\OperationLabel;
use App\Support\Operations\Task;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;

/**
 * Jeder Vorgang, der entsteht, hat eine Bezeichnung.
 *
 * **Der Anlass:** Bis zum 7. November 2026 stand jeder Vorgang eines Kunden mit
 * seiner Maschinenkennung da — im Streifen „Vorgang 812 web.site.apply —
 * läuft", in den Tabellen `php.pool.apply` und `backup.create`. Bezeichnungen
 * gab es nur für die Aufgaben des Betreibers in {@see Task}. Gefunden beim
 * Ausschreiben des Durchlaufs mit einer fremden Person (`docs/145 §0`,
 * Befund 5), nicht von einem Test: Es gab keinen, der danach fragte.
 *
 * **Wo Vorgänge entstehen, sucht derselbe Ausdruck wie in
 * `AgentOperationReachTest`** — ohne den unmittelbaren Aufruf `->call(…)`, der
 * auf eine Antwort wartet und keine Zeile anlegt —, dazu jede Aufgabe, auf
 * die ein Lebenslauf antwortet. Das ist ein Netz: Ein Name, der durch eine
 * eigene Methode gereicht wird, entgeht dem Ausdruck; die Lebensläufe fangen
 * die meisten davon, und die Gegenrichtung hält die Liste ehrlich.
 */
final class OperationLabelTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Jede Kennung, aus der ein Vorgang wird, mit ihren Fundstellen.
     *
     * @return array<string, list<string>>
     */
    private function created(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/app', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->root().'/', '', $file->getPathname());

            foreach ([
                // Die Operation einer Zeile: 'type' => 'backup.restore'
                '/\'type\'\s*=>\s*\'([a-z][a-z0-9.]*)\'/',
                // Ein Vorgang für ein Objekt: dispatch($domain, 'web.site.apply', …)
                '/->dispatch[A-Za-z]*\(\s*\$[a-zA-Z]+,\s*\'([a-z][a-z0-9.]*)\'/',
                // Ein Vorgang mit der Kennung vorn: dispatch('backup.create', …)
                '/->dispatch[A-Za-z]*\(\s*\'([a-z][a-z0-9.]*)\'/',
            ] as $pattern) {
                preg_match_all($pattern, $source, $matches);

                foreach ($matches[1] as $name) {
                    $found[$name][] = $relative;
                }
            }
        }

        foreach (Lifecycles::handled() as $name) {
            $found[$name][] = 'Lifecycles::handled()';
        }

        ksort($found);

        return $found;
    }

    public function test_every_operation_that_is_created_has_a_label(): void
    {
        $created = $this->created();

        $this->assertGreaterThan(20, count($created), 'Das Netz findet kaum Vorgänge — dann prüft dieser Test nichts.');

        $ohne = [];

        foreach ($created as $name => $where) {
            if (OperationLabel::of($name, $name) === $name) {
                $ohne[] = sprintf('%s — %s', $name, implode(', ', array_values(array_unique($where))));
            }
        }

        $this->assertSame([], $ohne, sprintf(
            "Diese Vorgänge entstehen und haben keine Bezeichnung:\n\n  %s\n\n".
            'Im Streifen, in der Vorgangsliste und auf der Domain- und Abonnementseite stünde '.
            'dann die Kennung — für einen Kunden eine Zeichenkette, die nichts sagt. Die '.
            'Bezeichnung gehört nach OperationLabel::LABELS oder, für eine Aufgabe des '.
            'Betreibers, nach Task.',
            implode("\n  ", $ohne),
        ));
    }

    /** Und jede Bezeichnung gehört zu einer Operation, die es im Agenten gibt. */
    public function test_every_label_names_an_operation_of_the_agent(): void
    {
        $known = (new Registry(new Config))->names();

        $this->assertGreaterThan(50, count($known), 'Der Agent kennt kaum Operationen — dann prüft dieser Test nichts.');

        $tot = array_values(array_diff(array_keys(OperationLabel::LABELS), $known));

        $this->assertSame([], $tot, sprintf(
            "Diese Bezeichnungen gehören zu keiner Operation des Agenten:\n  %s\n\n".
            'Ein Tippfehler in der Kennung lässt den Vorgang lautlos auf die Kennung zurückfallen.',
            implode("\n  ", $tot),
        ));
    }

    /** Eine Aufgabe des Betreibers behält ihre Bezeichnung — und steht nicht ein zweites Mal hier. */
    public function test_a_task_of_the_operator_keeps_its_own_label(): void
    {
        foreach (Task::cases() as $task) {
            $this->assertSame($task->label(), OperationLabel::of($task->value, $task->value));
            $this->assertArrayNotHasKey(
                $task->value,
                OperationLabel::LABELS,
                'Zwei Bezeichnungen für dieselbe Aufgabe — eine davon veraltet.',
            );
        }
    }

    /** Ohne Bezeichnung bleibt die Kennung; ein alter Vorgang kostet nicht die Seite. */
    public function test_without_a_label_the_name_stays(): void
    {
        $this->assertSame('gibt.es.nicht', OperationLabel::of(null, 'gibt.es.nicht'));
        $this->assertSame('Domain einrichten', OperationLabel::of('web.site.apply', 'web.site.apply'));
    }

    /**
     * Und die fünf Orte fragen hier.
     *
     * Gemessen am Quelltext, weil es um die Stelle geht, an der gefragt wird:
     * Ein Ort, der wieder `task ?? type` schreibt, fällt hier auf und nicht
     * erst bei einem Kunden. Das Band selbst misst `RunningBandTest`.
     */
    public function test_the_band_the_lists_and_the_tables_ask_here(): void
    {
        foreach ([
            'app/Support/Operations/RunningBand.php',
            'app/Http/Controllers/OperationController.php',
            'app/Http/Controllers/DomainController.php',
            'app/Http/Controllers/SubscriptionController.php',
        ] as $path) {
            $this->assertStringContainsString(
                'OperationLabel::of(',
                (string) file_get_contents($this->root().'/'.$path),
                $path.' fragt OperationLabel nicht mehr — dann steht dort wieder die Kennung.',
            );
        }

        foreach ([
            'resources/js/Pages/Domains/Show.vue' => '{{ op.label }}',
            'resources/js/Pages/Subscriptions/Show.vue' => '{{ o.label }}',
            'resources/js/Pages/Operations/Show.vue' => '{{ props.operation.label }}',
        ] as $path => $needle) {
            $this->assertStringContainsString(
                $needle,
                (string) file_get_contents($this->root().'/'.$path),
                $path.' zeigt die Bezeichnung nicht mehr.',
            );
        }
    }
}
