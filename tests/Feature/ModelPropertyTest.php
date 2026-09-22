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

    /**
     * Wieviele Zeitpunkte mindestens zusammenkommen müssen.
     *
     * Gemessen am 22. September 2026: 26 Carbon-Eigenschaften ausserhalb der
     * Zeitstempel. Dieselbe Begründung wie bei {@see self::AT_LEAST} — die
     * Zahl soll nicht festschreiben, sondern den Fall abfangen, dass der
     * Ausdruck ins Leere läuft.
     */
    private const CARBON_AT_LEAST = 18;

    /**
     * Die Zeitpunkte, die ein Modell als `Carbon` führt.
     *
     * **`created_at`, `updated_at` und `deleted_at` stehen nicht darunter**:
     * Die castet Eloquent von sich aus, und ein Modell, das sie zusätzlich in
     * `casts()` nennt, schriebe eine Selbstverständlichkeit auf.
     *
     * @return list<string>
     */
    private function carbonProperties(string $source): array
    {
        preg_match_all(
            '/^\s*\*\s*@property(?:-read)?\s+Carbon(?:\|null)?\s+\$(\w+)/m',
            $source,
            $treffer,
        );

        return array_values(array_diff($treffer[1], ['created_at', 'updated_at', 'deleted_at']));
    }

    /**
     * Und die Gegenrichtung: Was der Block als `Carbon` führt, muss das Modell
     * auch casten.
     *
     * **Der Anlass ist ein Abnahmelauf und keine rote CI** — und das ist der
     * Unterschied zu dem Wächter darüber. Am 22. September 2026 stand in Punkt
     * 6 von `docs/133` ein `$n->notified_at->toIso8601String()`; auf dem
     * Server kam *„Call to a member function toIso8601String() on string"*
     * zurück. {@see FindingNotification} führte `@property Carbon
     * $notified_at` und castete die Spalte nicht.
     *
     * **Die Richtung entscheidet, wer es merkt.** Fehlt die `@property`-Zeile
     * zu einem Cast, sieht larastan eine Zeichenkette und macht die CI rot —
     * teuer, aber laut. Fehlt der Cast zu einer `@property`-Zeile, glaubt
     * larastan dem Block, die Prüfung ist **grün**, und der Aufruf scheitert
     * erst dort, wo jemand ihn wirklich abschickt.
     *
     * > **Eine Zusage, der die statische Prüfung glaubt, ohne dass etwas sie
     * > hält, ist gefährlicher als eine fehlende: Sie macht nichts rot, sie
     * > macht etwas grün.**
     *
     * **Was dieser Wächter nicht kann:** Er nimmt den Block als Massstab und
     * nicht die Migration. Ein Modell, das eine Zeitspalte weder als
     * `Carbon` führt noch castet, ist für ihn in Ordnung — dort ist es dann
     * eine Zeichenkette, und beide Seiten sagen dasselbe. Das ist der
     * gleiche Zuschnitt wie oben: eine Richtung, ganz, statt beider halb.
     */
    public function test_every_carbon_property_is_actually_cast(): void
    {
        $befunde = [];
        $geprueft = 0;

        foreach (glob($this->root().'/app/Models/*.php') ?: [] as $model) {
            $source = (string) file_get_contents($model);
            $casts = $this->castColumns($source);

            foreach ($this->carbonProperties($source) as $column) {
                $geprueft++;

                if (in_array($column, $casts, true)) {
                    continue;
                }

                $befunde[] = sprintf(
                    '%s führt %s als Carbon, castet die Spalte aber nicht — larastan glaubt dem Block, '
                    .'und zur Laufzeit steht dort eine Zeichenkette.',
                    basename($model, '.php'),
                    $column,
                );
            }
        }

        self::assertSame([], $befunde, implode("\n", $befunde));

        self::assertGreaterThanOrEqual(
            self::CARBON_AT_LEAST,
            $geprueft,
            sprintf(
                'Nur %d Carbon-Eigenschaften gefunden. Entweder liest der Ausdruck den Block nicht mehr, '
                .'oder die Modelle schreiben ihn anders — geprüft hat dieser Wächter dann nichts.',
                $geprueft,
            ),
        );
    }

    /**
     * Die Spalte, die diesen Wächter gebraucht hat.
     *
     * Namentlich und nicht bloss als Zähler, aus demselben Grund wie oben:
     * Eine spätere Umbenennung soll auffallen und nicht einfach die Zahl
     * senken.
     */
    public function test_the_column_that_the_acceptance_run_found_is_cast(): void
    {
        $source = (string) file_get_contents($this->root().'/app/Models/FindingNotification.php');

        self::assertContains('notified_at', $this->carbonProperties($source));
        self::assertContains('notified_at', $this->castColumns($source));
    }
}
