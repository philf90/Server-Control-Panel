<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Concerns\BelongsToSubscription;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Support\ReadsMethodSource;

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
    use ReadsMethodSource;

    /**
     * Was ein `destroy()` sagen muss, um einen Filter abzuschalten.
     *
     * **Verlangt wird nur, was das Kindmodell auch wirklich anlegt.** Bis
     * August 2026 stand hier eine feste Liste beider Einträge, und sie wurde
     * fällig, sobald ein Modell *irgendwie* gefiltert war. Das ging genau so
     * lange gut, wie beide Filter an denselben Modellen hingen: Als
     * `Subscription` mit docs/35 seine weiche Löschung verlor, verlangte der
     * Wächter weiter ein `withTrashed()`, das es gar nicht mehr geben kann —
     * er hätte beim Aufräumen zugebissen und wäre dafür abgeschaltet worden.
     * Genau die Falle, vor der CLAUDE.md warnt.
     *
     * Es bleiben zwei getrennte Fragen, und sie werden getrennt gestellt:
     * `withTrashed()` allein zählt die Grabsteine mit und bleibt trotzdem in
     * der Klammer, `withoutRestriction()` allein hebt die Klammer auf und
     * übersieht weiter jeden Grabstein.
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
        $checked = 0;

        foreach ($pairs as [$child, $parent]) {
            $model = $this->model($child);

            if ($model === null) {
                continue;
            }

            $required = $this->requiredFor($model);

            if ($required === []) {
                continue;
            }

            $checked++;

            $source = $this->destroySource($parent);

            if ($source === null) {
                $missing[] = "  {$parent}: kein Controller mit destroy() — wo wird dann gezählt?";

                continue;
            }

            foreach ($required as $what) {
                $found = false;

                foreach (self::REQUIRED[$what] as $call) {
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

        // **Die Untergrenze, ohne die der Wächter still ins Leere liefe.** Die
        // Prüfung darunter besteht auch dann, wenn kein einziges Kindmodell
        // mehr gefiltert ist — und genau dann prüft sie nichts.
        $this->assertGreaterThan(
            0,
            $checked,
            'Kein einziges Kindmodell eines RESTRICT-Fremdschlüssels filtert noch mehr, als die Datenbank kennt — dann bewacht dieser Test nichts mehr.',
        );

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
     * Welche Filter dieses Modell anlegt — und deshalb abgeschaltet gehören.
     *
     * Gefragt wird je Filter einzeln. Ein Modell mit Mandantenklammer und ohne
     * weiche Löschung verlangt genau ein `withoutRestriction()` und kein
     * `withTrashed()`, das es nicht geben kann.
     *
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    private function requiredFor(string $model): array
    {
        $required = [];

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $required[] = 'die Grabsteine';
        }

        if ($this->scoped($model)) {
            $required[] = 'die Mandantenklammer';
        }

        return $required;
    }

    /**
     * Trägt dieses Modell eine Mandantenklammer?
     *
     * Am Quelltext und nicht an `getGlobalScopes()`: Der Filter wird beim
     * Booten registriert, und dieser Testfall bootet Laravel nicht.
     * `BelongsToSubscription` steht mit dazu — der Filter wohnt dort im Trait
     * und nicht in der Modelldatei.
     *
     * @param  class-string<Model>  $model
     */
    private function scoped(string $model): bool
    {
        if (in_array(BelongsToSubscription::class, class_uses_recursive($model), true)) {
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
     * Die Reflexion selbst steht in {@see ReadsMethodSource} — sie beantwortet
     * dieselbe Frage für den Wächter der Namensvergabe, und zweimal
     * ausformuliert wäre sie dieselbe Regel an zwei Orten.
     */
    private function destroySource(string $table): ?string
    {
        return $this->methodSource(
            'App\\Http\\Controllers\\'.Str::studly(Str::singular($table)).'Controller',
            'destroy',
        );
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
