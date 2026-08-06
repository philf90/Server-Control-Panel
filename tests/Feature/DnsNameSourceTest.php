<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Acme\Dns\Name;
use Tests\Support\WithoutPhpComments;

/**
 * „Wie steht ein Name auf dem Draht?" beantwortet `Name` — und sonst niemand.
 *
 * **Derselbe Wächter wie {@see HostnameSourceTest}, für dieselbe Sorte
 * Fehler.** Ein DNS-Name im Drahtformat ist eine Folge von Beschriftungen mit
 * vorangestellter Länge und einer Null am Ende; ihn zu überlesen ist die
 * Stelle, an der handgeschriebener DNS-Code danebengeht, weil ein Name in
 * einer Antwort meistens nicht ausgeschrieben dasteht, sondern als zwei Bytes,
 * die auf eine frühere Stelle zeigen (RFC 1035 §4.1.4).
 *
 * **Und es gibt inzwischen vier Stellen, die es bräuchten**: die Frage nach
 * einem TXT-Satz, die Aktualisierung einer Zone, die TSIG-Unterschrift und der
 * Doppelgänger in den Durchgängen. Vier Fassungen desselben Gedankens sind in
 * diesem Projekt der teuerste Fehler überhaupt — beim Rechnernamen ist er
 * genau viermal passiert, und deshalb steht diese Regel schon in CLAUDE.md.
 *
 * **Der Doppelgänger darf es trotzdem selbst.** {@see \Tests\Support\ScriptedExchange}
 * und die Durchgänge zu {@see \SrvPanel\Agent\Acme\Dns\Tsig} rechnen absichtlich
 * mit einer zweiten Fassung: Ein Durchgang, der dieselbe Funktion benutzt wie
 * der Prüfling, besteht auch dann, wenn beide Seiten denselben Fehler machen.
 * Deshalb prüft dieser Wächter `agent/` und nicht `tests/`.
 */
final class DnsNameSourceTest extends TestCase
{
    use WithoutPhpComments;

    /** Die Antwort selbst. */
    private const ALLOWED = [
        'agent/src/Acme/Dns/Name.php',
    ];

    /**
     * Woran eine zweite Fassung zu erkennen ist.
     *
     * Zwei Muster und nicht mehr, und beide sind mit Bedacht eng: Die
     * Zeigermarke `0xC0` kommt in DNS-Code sonst nirgends vor, und ein
     * Längenbyte vor einer Beschriftung auch nicht.
     *
     * **Die naheliegenden weiteren wären falsch.** `explode('.', …)` steht in
     * {@see \SrvPanel\Agent\DomainName} und im {@see \SrvPanel\Agent\Acme\Dns\Resolver},
     * beide zu Recht — dort wird ein Name zerlegt und nicht geschrieben. Und
     * `"\0"` steht in `Guard` und `Runner` als Prüfung auf ein Nullbyte im
     * Pfad. Ein Wächter, der beides meldet, wird beim ersten Aufräumen
     * abgeschaltet; das ist in diesem Projekt schon dreimal passiert.
     */
    private const PATTERNS = [
        '/0xC0|0xc0/',
        '/chr\(\s*(?:min\(\s*)?strlen\(\s*\$label/',
    ];

    /** @return list<string> */
    private function sources(): array
    {
        $files = [];
        $root = dirname(__DIR__, 2).'/agent/src';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertGreaterThan(30, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    public function test_only_one_place_writes_a_dns_name(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->sources() as $path) {
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);
            $source = $this->withoutComments((string) file_get_contents($path));
            $hits = 0;

            foreach (self::PATTERNS as $pattern) {
                $hits += preg_match_all($pattern, $source);
            }

            if ($hits === 0) {
                continue;
            }

            if (in_array($relative, self::ALLOWED, true)) {
                $checked += $hits;

                continue;
            }

            $found[] = sprintf('%s: %d×', $relative, $hits);
        }

        // **Die Untergrenze zählt dort, wo die Regel stehen darf.** Zieht die
        // Regel um, stünde sie sonst auf null und dieser Wächter meldete Rot
        // für genau die Ordnung, die er durchsetzen soll — dreimal passiert,
        // und die Lehre steht in CLAUDE.md.
        $this->assertGreaterThan(1, $checked, 'Die erlaubte Stelle schreibt gar keinen Namen mehr — dann ist die Liste veraltet.');

        $this->assertSame([], $found, sprintf(
            "Diese Dateien schreiben oder überlesen einen DNS-Namen selbst:\n  %s\n\n".
            "Das Drahtformat eines Namens steht in SrvPanel\\Agent\\Acme\\Dns\\Name: `encode()` schreibt,\n".
            "`canonical()` schreibt ihn so, wie eine TSIG-Unterschrift darüber rechnet, `skip()` überliest\n".
            "auch einen zusammengefassten. Eine zweite Fassung liest die folgenden Felder irgendwann um\n".
            'einige Bytes verschoben — und im Protokoll steht dann nichts, was darauf hindeutet.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Und `within()` vergleicht beschriftungsweise.
     *
     * `bösexample.de` endet auf `example.de`. Ein Vergleich als Zeichenkette
     * liesse hier eine fremde Domain in eine Zone hinein, die jemand anderem
     * gehört — und das ist bei {@see \SrvPanel\Agent\Acme\Dns\Rfc2136} die
     * Grenze zwischen zwei Kunden.
     */
    public function test_a_zone_is_not_a_suffix(): void
    {
        $this->assertTrue(Name::within('_acme-challenge.example.de', 'example.de'));
        $this->assertTrue(Name::within('example.de.', 'example.de'));
        $this->assertTrue(Name::within('EXAMPLE.DE', 'example.de'));

        $this->assertFalse(Name::within('boesexample.de', 'example.de'));
        $this->assertFalse(Name::within('example.de.evil.test', 'example.de'));
        $this->assertFalse(Name::within('example.de', ''));
    }
}
