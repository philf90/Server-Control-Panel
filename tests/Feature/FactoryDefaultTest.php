<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use App\Support\Databases\Databases;
use App\Support\Subscriptions\Lifecycle;
use PHPUnit\Framework\TestCase;

/**
 * Eine Factory baut die Zeile so, wie die Anwendung sie schreibt.
 *
 * **Der Anlass ist Lauf 463, und er sah aus wie ein Testfehler.** Die Spalte
 * `engine` kam mit P5b und trägt `default('mariadb')`; das Modell führt sie als
 * `@property DatabaseEngine $engine`, also ohne `null`. Beides stimmte — und
 * `Database::factory()->create()` lieferte trotzdem ein Modell, dessen `engine`
 * `null` war. `Databases::remove()` gab es an einen `match` weiter, und der
 * verlangt eine Aufzählung.
 *
 * **Warum das keine Eigenheit der Tests ist.** Ein `default` gilt beim
 * `INSERT`. Was danach im Speicher steht, ist das, was hineingeschrieben wurde
 * — Eloquent liest die Zeile nicht zurück. Jeder Code, der im selben Vorgang
 * anlegt und danach die Spalte liest, sieht dasselbe `null`. Dass es die
 * Anwendung nicht trifft, liegt allein daran, dass {@see Databases}
 * `engine` beim Anlegen immer mitschreibt — die Factory tat es nicht, und damit
 * baute sie eine Zeile, die es so nie gibt.
 *
 * **Die Regel prüft deshalb den Bauplan und nicht den Vorgabewert.** Ein
 * Vorgabewert im Modell (`$attributes`) wäre die zweite Fassung dessen, was in
 * der Migration steht, und die zweite ist die, die veraltet.
 *
 * **Gelesen wird der `@property`-Block**, weil dort steht, was das Modell über
 * seine Spalten behauptet — dieselbe Quelle, die larastan liest, und damit eine
 * gepflegte. `RedirectKind|null` bei {@see Domain} ist genau der
 * Fall, den diese Regel nicht meint: Eine Domain ohne Weiterleitung hat keine
 * Art, und eine Factory, die eine erfände, wäre falscher als eine, die sie
 * weglässt.
 */
final class FactoryDefaultTest extends TestCase
{
    /**
     * Modelle ohne Factory.
     *
     * `SystemUser` ist ein Verzeichnis verbrauchter Namen (`docs/35`) und wird
     * von {@see Lifecycle::claim()} geschrieben, nie
     * von einem Test — eine Factory dafür gäbe es nur, damit dieser Test etwas
     * findet.
     *
     * @var list<string>
     */
    private const WITHOUT_FACTORY = [
        'SystemUser',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Aufzählungsspalten eines Modells, die nicht `null` sein dürfen.
     *
     * Gelesen wird `casts()` — dort steht, welche Spalte eine Aufzählung ist —
     * und danach der `@property`-Block, ob sie `null` sein darf.
     *
     * @return list<string>
     */
    private function requiredEnumColumns(string $source): array
    {
        if (preg_match('/protected function casts\(\): array.*?return \[(.*?)\];/s', $source, $block) !== 1) {
            return [];
        }

        preg_match_all('/[\'"]([a-z_]+)[\'"]\s*=>\s*([A-Za-z]+)::class/', $block[1], $casts, PREG_SET_ORDER);

        $columns = [];

        foreach ($casts as $cast) {
            [, $column, $type] = $cast;

            // Nur Aufzählungen. `'datetime'` und Verwandte stehen als
            // Zeichenkette und fallen schon am Ausdruck heraus; ein
            // `AsCollection::class` oder ein eigener Guss nicht — die erkennt
            // man daran, dass das Modell die Spalte nicht als diesen Typ führt.
            if (preg_match('/@property\s+'.preg_quote($type, '/').'\s+\$'.preg_quote($column, '/').'\b/', $source) !== 1) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    public function test_every_required_enum_column_is_built_by_its_factory(): void
    {
        $befunde = [];
        $geprueft = 0;

        foreach (glob($this->root().'/app/Models/*.php') ?: [] as $model) {
            $name = basename($model, '.php');

            if (in_array($name, self::WITHOUT_FACTORY, true)) {
                continue;
            }

            $source = (string) file_get_contents($model);
            $columns = $this->requiredEnumColumns($source);

            if ($columns === []) {
                continue;
            }

            $factory = $this->root().'/database/factories/'.$name.'Factory.php';

            if (! is_file($factory)) {
                $befunde[] = sprintf('%s hat Aufzählungsspalten, aber keine Factory.', $name);

                continue;
            }

            // Nur `definition()`. Ein Zustand setzt die Spalte für einen Fall;
            // gefragt ist, womit die Factory ohne Zutun baut.
            if (preg_match('/public function definition\(\): array.*?\n    \}/s', (string) file_get_contents($factory), $block) !== 1) {
                $befunde[] = sprintf('%sFactory hat kein lesbares definition().', $name);

                continue;
            }

            foreach ($columns as $column) {
                $geprueft++;

                if (preg_match('/[\'"]'.preg_quote($column, '/').'[\'"]\s*=>/', $block[0]) === 1) {
                    continue;
                }

                $befunde[] = sprintf('%sFactory::definition() setzt %s nicht.', $name, $column);
            }
        }

        // Die Untergrenze zählt, wo die Regel stehen *darf*: Wer ein Modell
        // umbaut, soll hier kein Rot bekommen — ein Ausdruck, der nichts mehr
        // findet, aber schon.
        $this->assertGreaterThanOrEqual(
            12,
            $geprueft,
            'Es werden kaum Aufzählungsspalten gefunden — dann prüft dieser Test nichts.',
        );

        $this->assertSame([], $befunde, sprintf(
            "Diese Spalten dürfen laut Modell nicht `null` sein, entstehen aus der Factory aber leer:\n  %s\n\n".
            'Ein `default` in der Migration gilt beim INSERT und erreicht das Modell im Speicher nicht. '.
            'Was die Anwendung beim Anlegen mitschreibt, schreibt die Factory mit.',
            implode("\n  ", $befunde),
        ));
    }

    /**
     * Und die Gegenrichtung: Was `null` sein darf, wird nicht erfunden.
     *
     * Ohne sie liesse sich der Befund oben dadurch beheben, dass man jede
     * Spalte in jede Factory schreibt — und dann hätte jede Domain eine
     * Weiterleitungsart, auch die ohne Weiterleitung. Die Regel ist nicht
     * „alles setzen", sondern „setzen, was die Zeile immer hat".
     */
    public function test_a_nullable_enum_column_is_not_invented(): void
    {
        $domain = (string) file_get_contents($this->root().'/app/Models/Domain.php');

        $this->assertMatchesRegularExpression(
            '/@property\s+RedirectKind\|null\s+\$redirect_kind/',
            $domain,
            'Domain::$redirect_kind ist der Fall, an dem diese Prüfung hängt — bleibt sie nicht nullbar, '.
            'prüft der Test hier nichts mehr.',
        );

        $this->assertNotEmpty(
            $this->requiredEnumColumns($domain),
            'Domain hat Aufzählungsspalten, die nicht null sein dürfen — sonst wäre das Beispiel keines.',
        );

        $this->assertNotContains(
            'redirect_kind',
            $this->requiredEnumColumns($domain),
            'redirect_kind darf `null` sein und gehört damit nicht in die Liste der Pflichtspalten.',
        );
    }
}
