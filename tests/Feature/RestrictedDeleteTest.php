<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Wer vor dem Löschen zählt, zählt mit denselben Augen wie der Fremdschlüssel.
 *
 * **Der gemeldete Fehler, 7. August 2026.** Der Betreiber wollte einen Plan
 * löschen, an dem „keine Abos mehr" hingen. Das Panel zählte null, die
 * Datenbank zählte eins, und der Knopf antwortete mit einem 500er.
 *
 * Der Grund ist eine Asymmetrie, die man beim Lesen des Codes nicht sieht:
 * `$plan->subscriptions()->count()` sieht **weniger** als
 * `ON DELETE RESTRICT`. Zwei Filter kommen dazwischen, und beide sind für sich
 * richtig:
 *
 *   `SoftDeletes`   — ein zurückgebautes Abonnement ist aus dem Panel fort,
 *                     seine Zeile bleibt aber liegen, damit sein
 *                     Systembenutzer nicht ein zweites Mal vergeben wird.
 *   Mandantenklammer — sie zeigt, was das anfragende Konto sehen darf. Ein
 *                     Kommando ohne gesetzten Mandanten sieht gar nichts.
 *
 * Der Fremdschlüssel kennt weder das eine noch das andere. Er zählt Zeilen.
 *
 * **Deshalb dieser Wächter und nicht nur ein Testfall für Pläne.** Heute gibt
 * es genau einen solchen Fremdschlüssel; der nächste wird nicht daran denken.
 * Geprüft wird die Regel, nicht der Einzelfall: Zu jedem `restrictOnDelete()`,
 * dessen Kind weich löscht oder eine Mandantenklammer trägt, gehört ein
 * `destroy()`, das beide Filter ausdrücklich abschaltet.
 *
 * Wieder dasselbe Muster wie überall hier — eine Prüfung, die auf eine
 * Datenbankregel zeigt, und niemand vergleicht die beiden.
 */
final class RestrictedDeleteTest extends TestCase
{
    /**
     * Was ein `destroy()` sagen muss, um beide Filter abzuschalten.
     *
     * Es sind zwei getrennte Fragen, deshalb zwei Einträge: `withTrashed()`
     * allein zählt die Grabsteine mit und bleibt trotzdem in der Klammer,
     * `withoutRestriction()` allein hebt die Klammer auf und übersieht weiter
     * jeden Grabstein.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED = [
        'die Grabsteine' => ['withTrashed', 'onlyTrashed'],
        'die Mandantenklammer' => ['withoutRestriction'],
    ];

    /**
     * Jeder einschränkende Fremdschlüssel hat einen Löschweg, der richtig zählt.
     */
    public function test_a_destroy_counts_what_the_foreign_key_counts(): void
    {
        $pairs = $this->restrictingKeys();

        $this->assertNotSame(
            [],
            $pairs,
            'Es wird kein einziger restrictOnDelete-Fremdschlüssel gefunden — dann bewacht dieser Test nichts mehr.',
        );

        $missing = [];

        foreach ($pairs as [$child, $parent]) {
            $model = $this->model($child);

            if ($model === null || ! $this->filtered($model)) {
                continue;
            }

            $source = $this->destroySource($parent);

            if ($source === null) {
                $missing[] = "  {$parent}: kein Controller mit destroy() — wo wird dann gezählt?";

                continue;
            }

            foreach (self::REQUIRED as $what => $calls) {
                $found = false;

                foreach ($calls as $call) {
                    if (str_contains($source, $call.'(')) {
                        $found = true;

                        break;
                    }
                }

                if (! $found) {
                    $missing[] = "  {$parent}: destroy() zählt {$what} nicht mit ({$child} filtert sie weg).";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'Ein Fremdschlüssel mit RESTRICT zählt Zeilen — ohne Rücksicht auf',
            'deleted_at und ohne Rücksicht darauf, wer gerade fragt. Eine Prüfung',
            'davor, die weniger sieht, lässt das DELETE laufen und endet als 500:',
            ...$missing,
        ]));
    }

    /**
     * Die Paare (Kindtabelle, Elterntabelle) aus den Migrationen.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function restrictingKeys(): array
    {
        $pairs = [];

        foreach ($this->migrations() as $path) {
            $source = (string) file_get_contents($path);

            // Der Tabellenname steht im umschliessenden `Schema::create`. Ohne
            // ihn wüsste man zwar, dass irgendwo eingeschränkt wird, aber nicht
            // welches Modell filtert — und genau das ist die Frage.
            foreach ($this->tables($source) as [$table, $body]) {
                // `[^;]` und nicht `.` mit `/s`: Eine Zeile weiter oben steht
                // `foreignId('customer_id')->constrained()->cascadeOnDelete()`,
                // und ein Ausdruck, der über das Semikolon hinweglesen darf,
                // beginnt genügsam dort und endet beim `restrictOnDelete()` der
                // *nächsten* Zeile. Der Wächter meldete damit `customers` statt
                // `plans` — und hätte den `destroy()` eines unbeteiligten
                // Controllers geprüft. Beim Probelauf aufgefallen, nicht beim
                // Lesen.
                preg_match_all(
                    "/foreignId\('([a-z_]+)_id'\)([^;]*?)->restrictOnDelete\(\)/",
                    $body,
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as $match) {
                    $explicit = preg_match("/constrained\('([a-z_]+)'\)/", $match[2], $named) === 1;

                    $pairs[] = [$table, $explicit ? $named[1] : Str::plural($match[1])];
                }
            }
        }

        return $pairs;
    }

    /**
     * Die `Schema::create`-Blöcke einer Migration, je als Name und Rumpf.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function tables(string $source): array
    {
        preg_match_all(
            "/Schema::create\('([a-z_]+)'.*?\n        \}\);/s",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): array => [$match[1], $match[0]],
            $matches,
        );
    }

    /**
     * Filtert dieses Modell mehr weg, als die Datenbank kennt?
     *
     * @param  class-string<Model>  $model
     */
    private function filtered(string $model): bool
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return true;
        }

        $file = (new ReflectionClass($model))->getFileName();

        return $file !== false && str_contains((string) file_get_contents($file), 'addGlobalScope');
    }

    /**
     * Das Modell zu einer Tabelle — `subscriptions` heisst `Subscription`.
     *
     * @return class-string<Model>|null
     */
    private function model(string $table): ?string
    {
        $class = 'App\\Models\\'.Str::studly(Str::singular($table));

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /**
     * Der Rumpf von `destroy()` im Controller einer Tabelle.
     *
     * Gelesen wird die Methode und nicht die Datei: Ein `withTrashed()`
     * irgendwo sonst im Controller beantwortet die Frage nicht.
     */
    private function destroySource(string $table): ?string
    {
        $controller = 'App\\Http\\Controllers\\'.Str::studly(Str::singular($table)).'Controller';

        if (! class_exists($controller) || ! method_exists($controller, 'destroy')) {
            return null;
        }

        $method = new ReflectionMethod($controller, 'destroy');
        $file = $method->getFileName();

        if ($file === false) {
            return null;
        }

        $lines = file($file) ?: [];

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    /** @return list<string> */
    private function migrations(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/database/migrations', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
