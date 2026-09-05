<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Subscription;
use PHPUnit\Framework\TestCase;

/**
 * Ein Modell führt jede Spalte, die es castet, als `@property`.
 *
 * **Der Anlass ist `Announcement` und eine rote CI.** Neunzehn von zwanzig
 * Modellen tragen einen `@property`-Block; das zwanzigste trug keinen, und
 * gemeldet hat es niemand hier, sondern die „Statische Prüfung" mit fünfzehn
 * Zeilen der Form *„Cannot call method rank() on string"*. Larastan liest die
 * Typen einer Spalte aus diesem Block — was `casts()` sagt, sieht es nicht.
 * Ohne den Block ist `$a->category` eine Zeichenkette, und jeder Aufruf darauf
 * ein Fehler.
 *
 * > **Eine Regel, die neunzehn Dateien einhalten und nichts durchsetzt, ist
 * > keine Regel, sondern eine Gewohnheit — und die zwanzigste Datei bricht
 * > sie.**
 *
 * ## Die teurere Hälfte: ein bestehender Wächter wurde davon stumm
 *
 * {@see FactoryDefaultTest::requiredEnumColumns()} liest `casts()` und fragt
 * **danach** den `@property`-Block, ob die Spalte diesen Typ führt; findet er
 * die Zeile nicht, überspringt er die Spalte. Für ein Modell ohne Block
 * bedeutet das nicht „eine Spalte ist in Ordnung", sondern „null Spalten
 * geprüft". Gemessen an {@see Announcement}: mit Block eine Aufzählungsspalte,
 * ohne Block keine.
 *
 * > **Ein Wächter, der seine Frage aus einem Block liest, den nichts erzwingt,
 * > ist für eine Datei ohne diesen Block stumm — und die Stummheit sieht aus
 * > wie Zustimmung.**
 *
 * Dass dort nichts Kaputtes lag, war Glück: `AnnouncementFactory` setzt
 * `category`. Der Wächter hätte es nur nicht gemerkt.
 *
 * ## Was dieser Wächter nicht kann
 *
 * Er prüft **eine** Richtung — jede gecastete Spalte hat ihre Zeile. Die
 * Gegenrichtung, eine `@property`-Zeile für eine Spalte, die es nicht mehr
 * gibt, bräuchte den Bestand der Migrationen; sie ist hier bewusst nicht
 * gebaut, weil ein Wächter, der zu viel meldet, abgeschaltet wird. Eine
 * umbenannte Spalte fängt die geprüfte Richtung ohnehin, nur bleibt die alte
 * Zeile als Lüge stehen.
 *
 * Und über die **Richtigkeit** des Typs sagt er nichts: `@property string
 * $category` würde ihn zufriedenstellen und PHPStan genauso rot machen. Was
 * ihn hält, ist die Prüfung selbst — hier steht nur, dass die Zeile da ist.
 */
final class ModelPropertyTest extends TestCase
{
    /**
     * Wieviele gecastete Spalten mindestens zusammenkommen müssen.
     *
     * Gemessen am 5. September 2026: 19 Modelle, 70 Spalten. Die Untergrenze
     * steht deutlich darunter, weil sie nicht die Zahl festschreiben soll,
     * sondern den Fall abfangen, dass der Ausdruck über `casts()` ins Leere
     * läuft — dann meldet dieser Wächter nichts und sieht aus wie erfüllt.
     */
    private const AT_LEAST = 50;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Spalten, die ein Modell castet.
     *
     * @return list<string>
     */
    private function castColumns(string $source): array
    {
        if (preg_match('/protected function casts\(\): array.*?return \[(.*?)\];/s', $source, $block) !== 1) {
            return [];
        }

        preg_match_all('/[\'"]([a-z_]+)[\'"]\s*=>/', $block[1], $treffer);

        return $treffer[1];
    }

    /**
     * Führt die Datei diese Spalte als Eigenschaft?
     *
     * **Der Typ darf Leerzeichen enthalten**, und das ist keine Kleinigkeit:
     * Der erste Wurf dieses Ausdrucks verlangte `\S+` und meldete daraufhin
     * zehn Modelle, von denen neun in Ordnung waren — `array<string, mixed>`
     * trägt eines.
     *
     * > **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die
     * > Gewohnheit und nicht die Regel.**
     */
    private function declares(string $source, string $column): bool
    {
        return preg_match(
            '/^\s*\*\s*@property(?:-read)?\s+.+\s+\$'.preg_quote($column, '/').'\b/m',
            $source,
        ) === 1;
    }

    public function test_every_cast_column_is_declared_as_a_property(): void
    {
        $befunde = [];
        $geprueft = 0;

        foreach (glob($this->root().'/app/Models/*.php') ?: [] as $model) {
            $source = (string) file_get_contents($model);

            foreach ($this->castColumns($source) as $column) {
                $geprueft++;

                if ($this->declares($source, $column)) {
                    continue;
                }

                $befunde[] = sprintf(
                    '%s castet %s, führt die Spalte aber nicht als @property — larastan sieht dort eine Zeichenkette.',
                    basename($model, '.php'),
                    $column,
                );
            }
        }

        self::assertSame([], $befunde, implode("\n", $befunde));

        self::assertGreaterThanOrEqual(
            self::AT_LEAST,
            $geprueft,
            sprintf(
                'Nur %d gecastete Spalten gefunden. Entweder liest der Ausdruck casts() nicht mehr, '
                .'oder die Modelle casten woanders — geprüft hat dieser Wächter dann nichts.',
                $geprueft,
            ),
        );
    }

    /**
     * Die Spalte, die diesen Wächter gebraucht hat.
     *
     * **Ein Prüfkörper aus dem Bestand und keiner aus der Vorstellung**:
     * {@see Subscription::casts()} führt `disk_quota_enforced` mit drei Werten
     * — ja, nein, nicht nachgesehen —, und genau diese Spalte fehlte im Block,
     * bis dieser Wächter sie gemeldet hat. Sie steht hier namentlich, damit
     * eine spätere Umbenennung nicht bloss den Zähler senkt.
     */
    public function test_the_column_that_this_guard_found_is_declared(): void
    {
        $source = (string) file_get_contents($this->root().'/app/Models/Subscription.php');

        self::assertContains('disk_quota_enforced', $this->castColumns($source));
        self::assertTrue($this->declares($source, 'disk_quota_enforced'));
    }
}
