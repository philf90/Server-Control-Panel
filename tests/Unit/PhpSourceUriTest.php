<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\PhpVersions;

/**
 * Die PHP-Quelle wird gelesen und nicht gewusst — und der Leser zeigt auf die
 * Datei, die die Paketierung wirklich schreibt.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Seit dem 24. August 2026 bricht `php.version.install` ab, wenn **die Quelle,
 * die es braucht**, beim Auffrischen nicht erreichbar war (M5, `docs/81
 * §2.1b`). Welche das ist, steht nicht im Agenten: `packaging/php-source.sh`
 * trägt auf Debian `https://packages.sury.org/php/` ein und auf Ubuntu das PPA
 * von Ondřej Surý, und ein Betreiber darf einen Spiegel eintragen. Gelesen wird
 * deshalb die Datei.
 *
 * **Das ist genau die Fehlerklasse, die dieses Projekt sechsmal getroffen
 * hat:** *eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein
 * Test oder ein Werkzeug den Bezug prüft.* Benennt die Paketierung ihre Datei
 * um oder schreibt sie das Feld anders, gibt {@see PhpVersions::sourceUris()}
 * eine leere Liste zurück — und dann geschieht **nichts Sichtbares**: Der
 * Abbruch bleibt aus, `apt-get install` läuft mit den alten Listen, und der
 * Fund M5 ist stillschweigend wieder da.
 *
 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
 * > tun".**
 *
 * Der Wächter kann das nicht am Ergebnis merken — es gibt keins. Er hält
 * deshalb die beiden Seiten gegeneinander, die auseinanderlaufen können.
 */
final class PhpSourceUriTest extends TestCase
{
    /**
     * Die Dateien der Paketierung, die den Ort der PHP-Quelle nennen.
     *
     * `install.sh` steht mit dabei, obwohl es die Datei nicht schreibt: Es
     * nennt sie im Hinweistext für den Betreiber, und ein Hinweis auf eine
     * Datei, die anders heisst, schickt ihn ins Leere.
     *
     * @var list<string>
     */
    private const PACKAGING = [
        'packaging/php-source.sh',
        'packaging/scripts/php-source-postinstall.sh',
        'packaging/scripts/php-source-postremove.sh',
        'packaging/install.sh',
    ];

    /**
     * Jeder Pfad `…sources.list.d/php-…` in der Paketierung ist der, den der
     * Agent liest.
     */
    public function test_the_packaging_writes_the_file_the_agent_reads(): void
    {
        $found = 0;

        foreach (self::PACKAGING as $relative) {
            $path = dirname(__DIR__, 2).'/'.$relative;

            $this->assertFileExists($path, $relative.' gibt es nicht mehr.');

            preg_match_all(
                '#/etc/apt/sources\.list\.d/php[a-z0-9._-]*#',
                (string) file_get_contents($path),
                $matches,
            );

            foreach ($matches[0] as $mentioned) {
                $found++;

                $this->assertSame(PhpVersions::SOURCE_FILE, $mentioned, sprintf(
                    '%s nennt %s, der Agent liest %s. Laufen die beiden auseinander, gibt '
                    .'sourceUris() eine leere Liste zurück — und php.version.install bricht bei '
                    .'einer toten PHP-Quelle wieder nicht ab, ohne dass es jemandem auffällt.',
                    $relative,
                    $mentioned,
                    PhpVersions::SOURCE_FILE,
                ));
            }
        }

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThan(3, $found, 'Es wird kaum ein Pfad gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Und das Feld: Die Paketierung schreibt `URIs:`, und danach wird gesucht.
     *
     * Der Feldname allein wäre eine Wortsuche. Geprüft wird deshalb die
     * **Wirkung**: Der Leser bekommt genau den Block, den `php-source.sh`
     * erzeugt, und muss die Adresse daraus holen.
     */
    public function test_the_reader_understands_what_the_packaging_writes(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/php-source.sh');

        $this->assertStringContainsString('URIs: ${base}', $script,
            'php-source.sh schreibt das Feld anders — dann liest sourceUris() an ihm vorbei.');

        // Der Block aus php-source.sh, mit eingesetzten Werten. Selbst gebaut,
        // weil eine Datei von dieser Maschine genau den Fall nicht enthielte,
        // um den es geht.
        $written = "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: bookworm\nComponents: main\n"
            ."Signed-By: /usr/share/keyrings/php-sury-keyring.gpg\n";

        $this->assertSame(['https://packages.sury.org/php/'], $this->parse($written));
    }

    /**
     * Die Ubuntu-Fassung ergibt eine andere Adresse — und deshalb steht keine
     * im Quelltext.
     */
    public function test_both_distributions_are_read_from_the_file(): void
    {
        $ubuntu = "Types: deb\nURIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\nSuites: noble\n";

        $this->assertSame(['https://ppa.launchpadcontent.net/ondrej/php/ubuntu/'], $this->parse($ubuntu));
    }

    /**
     * Ein gefalteter `Signed-By:`-Block macht aus einer Fortsetzung kein Feld.
     *
     * `docs/81 §2.1` hat gemessen, dass dort ein ganzer PGP-Block stehen kann,
     * über vierzig Zeilen mit führendem Leerzeichen. Eine Fortsetzungszeile,
     * die zufällig mit `URIs:` beginnt, ist **kein** Feld — deshalb liest
     * dieser Leser nur am Zeilenanfang.
     */
    public function test_a_folded_block_does_not_become_a_field(): void
    {
        $file = "Types: deb\nURIs: https://packages.sury.org/php/\n"
            ."Signed-By:\n -----BEGIN PGP PUBLIC KEY BLOCK-----\n URIs: https://boeser.example/\n"
            ." -----END PGP PUBLIC KEY BLOCK-----\n";

        $this->assertSame(['https://packages.sury.org/php/'], $this->parse($file));
    }

    /**
     * Mehrere Stanzas und mehrere Adressen in einem Feld kommen beide mit.
     *
     * > **Ein Feld, das meistens genau einen Wert hat, ist kein Feld mit einem
     * > Wert.** Der Satz steht in `docs/81 §2.1` über die Herkunft einer
     * `Inst`-Zeile und gilt für `URIs:` genauso: deb822 erlaubt mehrere,
     * durch Leerraum getrennt.
     */
    public function test_more_than_one_address_survives(): void
    {
        $file = "Types: deb\nURIs: https://eins.example/php/  https://zwei.example/php/\nSuites: noble\n"
            ."\nTypes: deb\nURIs: https://drei.example/php/\nSuites: noble\n";

        $this->assertSame([
            'https://eins.example/php/',
            'https://zwei.example/php/',
            'https://drei.example/php/',
        ], $this->parse($file));
    }

    /**
     * Keine Datei heisst „keine eigene Quelle" — und das ist richtig so.
     *
     * Auf Debian 13 kommt PHP 8.4 aus der Distribution; `php-source.sh` steigt
     * dort aus, ohne etwas einzutragen. Der Aufrufer kann dann keine Quelle
     * beschuldigen, und genau das soll er auch nicht.
     */
    public function test_without_the_file_there_is_nobody_to_blame(): void
    {
        $this->assertSame([], PhpVersions::sourceUris(sys_get_temp_dir().'/gibt-es-nicht-'.bin2hex(random_bytes(6))));
    }

    /**
     * Den Inhalt in eine Datei legen und lesen lassen — der Pfad wird nicht
     * gebaut, sondern der Vorgabewert überschrieben.
     *
     * @return list<string>
     */
    private function parse(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'srvpanel-quelle-');

        try {
            file_put_contents($path, $contents);

            return PhpVersions::sourceUris($path);
        } finally {
            @unlink($path);
        }
    }
}
