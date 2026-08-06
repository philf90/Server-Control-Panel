<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\CertificateName;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Wo ein Platzhalter liegt — und warum nicht dort, wo er heisst.
 *
 * **Der Ablageort ist ein Dateipfad, und der landet in einer nginx-Datei.**
 * Ein Zertifikat über `*.example.de` müsste nach der bisherigen Regel in einem
 * Verzeichnis dieses Namens liegen. Ein Stern ist aber für jede Shell, für
 * `find` und für `rm` ein Muster — ein Name, der irgendwo unterwegs
 * expandiert, bezeichnet dann etwas anderes als das, was gemeint war. Und
 * gescheitert wäre es erst *nach* der erfolgreichen Bestellung: ein Zertifikat,
 * das ausgestellt ist und sich nicht ablegen lässt, ist ein verbrauchter
 * Eintrag in der Wochengrenze der Zertifizierungsstelle.
 *
 * Geprüft werden deshalb drei Zusagen: Im Pfad steht nie ein Stern, zwei
 * verschiedene Namen landen nie im selben Verzeichnis, und was weder Name noch
 * Schlüssel ist, kommt gar nicht erst durch.
 */
final class CertificateStorageTest extends TestCase
{
    private function store(): Store
    {
        return new Store('/tmp/srvpanel-test-certs');
    }

    public function test_a_wildcard_is_stored_under_a_name_without_a_star(): void
    {
        $directory = $this->store()->directory('*.example.de');

        $this->assertSame('/tmp/srvpanel-test-certs/_wildcard.example.de', $directory);
        $this->assertStringNotContainsString('*', $directory);
    }

    /**
     * Und der Schlüssel darf zurück durch dieselbe Prüfung.
     *
     * Er geht diesen Weg zweimal: einmal beim Ablegen, und einmal, wenn die
     * Anwendung ihn später wieder nennt, um den Server-Block schreiben zu
     * lassen. Wäre die Umformung nicht mehrfach anwendbar, ginge genau dieser
     * zweite Weg schief — und zwar bei jeder gesicherten Website gleichzeitig.
     */
    public function test_the_key_survives_a_second_pass(): void
    {
        $once = CertificateName::normalize('*.example.de');

        $this->assertSame('_wildcard.example.de', $once);
        $this->assertSame($once, CertificateName::normalize($once));
    }

    /**
     * Zwei verschiedene Namen, zwei verschiedene Verzeichnisse.
     *
     * **Der teure Fall steht mit in der Liste:** `example.de` und
     * `*.example.de` sind zwei Zertifikate, nicht eines. Fielen sie zusammen,
     * überschriebe das eine das andere — und welches gerade dort liegt, hinge
     * davon ab, welche Bestellung zuletzt lief.
     */
    public function test_two_different_names_never_share_a_directory(): void
    {
        $names = [
            'example.de',
            '*.example.de',
            'www.example.de',
            '*.www.example.de',
            'example.com',
            '*.example.com',
            'shop.example.de',
        ];

        $directories = [];

        foreach ($names as $name) {
            $directories[] = $this->store()->directory($name);
        }

        $this->assertCount(count($names), array_unique($directories));
    }

    /** @return list<array{0: string}> */
    public static function badNames(): array
    {
        return [
            ['*'],
            ['*.'],
            ['*.*.example.de'],
            ['_wildcard.'],
            ['_wildcard.*.example.de'],
            ['a.*.example.de'],
            ['*..example.de'],
            ['*./../../etc'],
            ['../../etc/passwd'],
            ['*.example'],
        ];
    }

    #[DataProvider('badNames')]
    public function test_what_is_neither_a_name_nor_a_key_is_refused(string $name): void
    {
        $this->expectException(AgentException::class);

        $this->store()->directory($name);
    }

    /**
     * Und die Vorlage liefert den Platzhalter aus, wenn das Panel ihn nennt.
     *
     * Das ist die Strecke, um die es geht: Das Panel nennt den Schlüssel, der
     * Agent baut daraus den Pfad, und im Server-Block steht er ohne Stern.
     */
    public function test_the_template_delivers_the_wildcard_under_its_key(): void
    {
        $root = sys_get_temp_dir().'/srvpanel-wildcard-'.bin2hex(random_bytes(6));
        mkdir($root.'/_wildcard.example.de', 0o750, true);

        foreach (['fullchain.pem', 'privkey.pem'] as $file) {
            file_put_contents($root.'/_wildcard.example.de/'.$file, "-----BEGIN-----\n");
        }

        $config = SiteTemplate::render(Site::fromArgs([
            'subscription' => 'example.de',
            'user' => 'p1001',
            'domain' => 'shop.example.de',
            'document_root' => 'httpdocs',
            'certificate' => '*.example.de',
        ]), new Store($root));

        $this->assertStringContainsString($root.'/_wildcard.example.de/fullchain.pem;', $config);
        $this->assertStringContainsString('listen 443 ssl;', $config);

        // Und nirgendwo im Block steht ein Stern — er hätte in einer Datei, die
        // als root gelesen wird, nichts zu suchen.
        $this->assertStringNotContainsString('*.example.de', $config);

        foreach (glob($root.'/*/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($root.'/_wildcard.example.de');
        @rmdir($root);
    }
}
