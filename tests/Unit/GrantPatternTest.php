<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Sql;

/**
 * Die Unterstrich-Falle in `GRANT` — der teuerste Fund beim Entwurf von P5.
 *
 * **In `GRANT … ON <db>.*` ist `<db>` ein Muster und kein Name.** `_` steht dort
 * für ein beliebiges Zeichen, `%` für beliebig viele. Der naheliegende Weg,
 * einem Abonnement seine Datenbanken freizugeben, wäre gewesen:
 *
 *     GRANT ALL PRIVILEGES ON `p1001_%`.* TO 'p1001_web'@'localhost';
 *
 * Das sieht aus wie „alle Datenbanken von p1001" und ist es nicht: Es trifft
 * auch `p10012_shop`. **Damit läge ein Zugriff über die Mandantengrenze
 * hinweg vor** — genau der, den das Abnahmekriterium von P5 ausschliesst.
 *
 * Dieser Wächter prüft **zuerst, dass die Falle echt ist** und danach, dass sie
 * zugeht. Der erste Teil ist ungewöhnlich und Absicht: Eine Regel, deren Grund
 * niemand mehr nachvollziehen kann, wird beim nächsten Aufräumen entfernt.
 *
 * Nachgebildet wird `LIKE` mit `fnmatch`, wo `?` für ein Zeichen und `*` für
 * beliebig viele steht — dieselbe Semantik wie `_` und `%`. Ein Test, der eine
 * echte MariaDB braucht, liefe in diesem Container nicht (CLAUDE.md: „kein
 * nginx, kein PHP-FPM, kein Agent"); dass der Schutz eine Eigenschaft der
 * erzeugten Zeichenkette ist, ist dieselbe Bauart wie in
 * {@see SiteTemplateTest} und {@see PhpIsolationTest}.
 */
final class GrantPatternTest extends TestCase
{
    /**
     * `LIKE` als Glob: `_` → `?`, `%` → `*`.
     *
     * **Sie hiess `matches()` und hat die ganze Testsuite umgebracht.**
     * `PHPUnit\Framework\Assert::matches()` ist `final` und `static`; eine
     * Methode dieses Namens in einem Testfall bricht beim **Laden** der Klasse,
     * nicht beim Ausführen — `php -l` sieht davon nichts, und `php artisan
     * test` endete mit „Cannot override final method" und Rückgabewert 255,
     * bevor ein einziger Test lief. Nicht dieser eine stand still, sondern alle
     * vierundsiebzig Dateien.
     *
     * Das ist wortwörtlich die Falle aus CLAUDE.md: *„ein Name, der der
     * Basisklasse gehört"* — dort mit `count()` in einem Testfall und
     * `configure()` in einem Artisan-Kommando. Im selben Beitrag ist sie ein
     * zweites Mal aufgetreten, bei `DatabaseFactory::for()`; die hat ein Blick
     * in die Basisklasse gefangen, diese nicht.
     */
    private function likeHits(string $pattern, string $name): bool
    {
        $glob = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                // Maskiert: Das nächste Zeichen ist wörtlich gemeint. `fnmatch`
                // versteht `\_` genauso, aber ausgeschrieben ist es die
                // Aussage, um die es hier geht.
                $glob .= $pattern[++$i];

                continue;
            }

            $glob .= match ($char) {
                '_' => '?',
                '%' => '*',
                default => $char,
            };
        }

        return fnmatch($glob, $name);
    }

    /**
     * Erst der Nachweis, dass die Falle echt ist.
     *
     * Ohne diese beiden Zeilen wäre alles Weitere eine Vorsichtsmassnahme ohne
     * Anlass — und Vorsichtsmassnahmen ohne Anlass verschwinden.
     */
    public function test_the_trap_is_real(): void
    {
        $this->assertTrue(
            $this->likeHits('p1001_%', 'p10012_shop'),
            'Ein Muster p1001_% träfe die Datenbank eines fremden Abonnements — das ist der Grund für diesen Wächter.',
        );

        $this->assertTrue(
            $this->likeHits('p1001_shop', 'p1001Xshop'),
            'Ein unmaskierter Unterstrich macht auch aus einem Namen ein Muster.',
        );
    }

    /** Und dann, dass sie zugeht. */
    public function test_the_underscore_is_escaped(): void
    {
        $target = Sql::grantTarget('p1001_shop');

        $this->assertSame('`p1001\_shop`.*', $target);

        $pattern = trim(substr($target, 0, -2), '`');

        $this->assertTrue($this->likeHits($pattern, 'p1001_shop'), 'Die eigene Datenbank muss weiterhin getroffen werden.');
        $this->assertFalse($this->likeHits($pattern, 'p1001Xshop'), 'Maskiert darf der Unterstrich nichts anderes mehr treffen.');
        $this->assertFalse($this->likeHits($pattern, 'p10012_shop'));
    }

    /**
     * Auf ein Muster wird gar nicht erst berechtigt.
     *
     * Die Maskierung allein reichte nicht: Sie macht aus einem Namen einen
     * Namen, aber sie hindert niemanden daran, `p1001_%` zu schicken. Deshalb
     * die zweite Festlegung — es wird immer auf genau eine Datenbank
     * berechtigt, nie auf eine Menge.
     */
    public function test_a_pattern_is_refused_outright(): void
    {
        $this->expectException(AgentException::class);

        Sql::grantTarget('p1001_%');
    }

    /**
     * Der Backslash zuerst, sonst maskiert der zweite Durchgang den ersten.
     *
     * Ein Backslash kann in einem geprüften Namen nicht vorkommen. Die
     * Behauptung steht trotzdem da: Sie kostet drei Zeilen und deckt den Fall,
     * dass die Namensregel eines Tages weiter wird.
     */
    public function test_a_backslash_does_not_escape_the_escape(): void
    {
        $this->assertSame('`a\\\\b\_c`.*', Sql::grantTarget('a\\b_c'));
    }

    /** Ein Bezeichner mit Backtick wird abgewiesen und nicht verdoppelt. */
    public function test_a_backtick_is_refused_not_doubled(): void
    {
        $this->expectException(AgentException::class);

        Sql::identifier('a`b');
    }
}
