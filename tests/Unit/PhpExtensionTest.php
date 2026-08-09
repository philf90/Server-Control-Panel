<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\PhpVersions;

/**
 * Was einer PHP-Version fehlt — aus der Ausgabe von `dpkg-query`.
 *
 * **Der Anlass ist eine Prüfung, die einen Stellvertreter gefragt hat.** Bis
 * zum 9. August 2026 hielt `php.version.install` eine Version für vollständig,
 * sobald `/usr/sbin/php-fpm8.2` dalag — geprüft wurde der Handler, gemeint war
 * der Paketsatz. Solange {@see PhpVersions::EXTENSIONS} sich nie ändert, sind
 * die beiden dasselbe. Als `pgsql` dazukam, gingen sie auseinander, und niemand
 * hätte es gemerkt: Die Operation meldet `already => true` und Erfolg, und der
 * Kunde bekommt *„could not find driver"*.
 *
 * Die Zeilen unten sind abgeschrieben und nicht ausgedacht: gemessen am
 * 9. August 2026 gegen das `dpkg-query` dieses Containers, mit vorhandenen und
 * mit unbekannten Paketen im selben Aufruf.
 */
final class PhpExtensionTest extends TestCase
{
    /**
     * Zwei bekannte, zwei unbekannte — und der Rückgabewert war 1.
     *
     * Die unbekannten stehen **nicht** in dieser Zeichenkette: dpkg meldet sie
     * auf `stderr` („no packages found matching …"). Genau deshalb wird hier
     * gegen die gewünschte Liste gerechnet und nicht gegen die Ausgabe allein.
     */
    private const OUTPUT = "php8.2-fpm installed\nphp8.2-mysql installed\n";

    /** @return list<string> */
    private function wanted(): array
    {
        return ['php8.2-fpm', 'php8.2-mysql', 'php8.2-pgsql', 'php8.2-intl'];
    }

    /**
     * Was dpkg nicht nennt, fehlt.
     */
    public function test_a_package_dpkg_does_not_mention_is_missing(): void
    {
        $this->assertSame(
            ['php8.2-pgsql', 'php8.2-intl'],
            PhpVersions::missing($this->wanted(), self::OUTPUT),
        );
    }

    /**
     * Und was es nennt, fehlt nicht.
     */
    public function test_an_installed_package_is_not_missing(): void
    {
        $this->assertSame([], PhpVersions::missing(['php8.2-fpm'], self::OUTPUT));
    }

    /**
     * **Nur `installed` zählt.**
     *
     * Ein entferntes Paket, dessen Konfiguration liegenbleibt, steht als
     * `config-files` in derselben Ausgabe — und ein abgebrochenes als
     * `half-installed`. Beides ist nicht benutzbar. Wer auf „steht in der
     * Ausgabe" prüfte statt auf den Zustand, hielte diese Fälle für in Ordnung
     * und liesse den Kunden mit einer Erweiterung zurück, die es nicht gibt.
     */
    public function test_only_installed_counts_as_present(): void
    {
        foreach (['config-files', 'half-installed', 'not-installed', 'unpacked'] as $zustand) {
            $this->assertSame(
                ['php8.2-pgsql'],
                PhpVersions::missing(['php8.2-pgsql'], 'php8.2-pgsql '.$zustand."\n"),
                sprintf('Der Zustand %s wurde für benutzbar gehalten', $zustand),
            );
        }

        $this->assertSame([], PhpVersions::missing(['php8.2-pgsql'], "php8.2-pgsql installed\n"));
    }

    /**
     * Keine Ausgabe heisst: alles fehlt.
     *
     * Der Fall, in dem dpkg keines der genannten Pakete kennt — und der Fall,
     * in dem etwas am Aufruf schiefgegangen ist. **Die sichere Richtung ist
     * dieselbe:** lieber einmal zu viel nach `apt-get` greifen als eine Lücke
     * für geschlossen halten.
     */
    public function test_no_output_means_everything_is_missing(): void
    {
        $this->assertSame($this->wanted(), PhpVersions::missing($this->wanted(), ''));
    }

    /**
     * Das Format der Frage steht neben der Auswertung.
     *
     * **Sonst laufen sie auseinander**, und zwar lautlos: Ein `-f`, das die
     * Spalten anders anordnet, ergäbe einen Parser, der nichts findet — und
     * „nichts gefunden" hiesse hier „alles fehlt", also `apt-get` bei jedem
     * Aufruf. Die beiden Felder, auf die {@see PhpVersions::missing()} zählt,
     * müssen in dieser Reihenfolge angefordert werden.
     */
    public function test_the_query_asks_for_the_two_fields_it_reads(): void
    {
        $format = implode(' ', PhpVersions::DPKG_ARGUMENTS);

        $this->assertStringContainsString('${binary:Package} ${db:Status-Status}', $format);
        $this->assertStringContainsString('-W', $format);
    }
}
