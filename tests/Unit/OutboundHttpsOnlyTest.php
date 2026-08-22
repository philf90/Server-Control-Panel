<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Curl;

/**
 * Nach draussen spricht der Agent nur über https — die erste von vier Zusagen.
 *
 * **Warum es diesen Wächter erst seit P7 gibt, obwohl die Regel aus P4 ist.**
 * Sie stand als Bedingung mitten in {@see Curl::send} und war damit nicht zu
 * prüfen, ohne wirklich eine Verbindung aufzubauen. Aufgefallen ist das, als
 * P7 sie brechen wollte: Für die HTTP-API von PowerDNS war eine Ausnahme für
 * die Rückschleife gebaut und wieder zurückgebaut worden (`docs/72`) — geblieben
 * ist die Naht, an der die Regel jetzt eine Frage ist.
 *
 * > **Eine Regel, die man nicht fragen kann, hat keinen Wächter — auch wenn
 * > jeder sie kennt.**
 *
 * **Die Fälle unten sind die aus dem Rückbau**, und sie stehen absichtlich
 * weiter da: Sie beschreiben, was diese Zusage *nicht* erlaubt, und der
 * nächste, der eine Ausnahme erwägt, findet sie hier statt sie neu zu suchen.
 */
final class OutboundHttpsOnlyTest extends TestCase
{
    /**
     * @param  bool  $erlaubt  Was herauskommen soll
     */
    #[DataProvider('adressen')]
    public function test_only_https_is_permitted(string $url, bool $erlaubt): void
    {
        $this->assertSame(
            $erlaubt,
            (new Curl)->permitted($url),
            sprintf('%s sollte %s werden.', $url, $erlaubt ? 'durchgelassen' : 'abgewiesen'),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function adressen(): iterable
    {
        // **Was durchgehen muss.** Ohne diese Zeilen misst der Wächter nichts:
        // eine Regel, die alles abweist, sähe genauso aus wie eine, die wirkt.
        yield 'ein Verzeichnis der Zertifizierungsstelle' => ['https://acme.example.org/directory', true];
        yield 'ein Anbieter mit Port' => ['https://dns.example.org:8443/api', true];

        // Und was nicht.
        yield 'Klartext' => ['http://beispiel.invalid/', false];
        yield 'Klartext auf der Rückschleife' => ['http://127.0.0.1:8081/api', false];
        yield 'Klartext auf der Rückschleife über IPv6' => ['http://[::1]:8081/api', false];
        yield 'localhost' => ['http://localhost:8081/api', false];
        yield 'eine Datei' => ['file:///etc/shadow', false];
        yield 'ein anderes Schema' => ['ftp://beispiel.invalid/', false];
        yield 'führendes Leerzeichen' => [' https://beispiel.invalid/', false];
        yield 'gar keine Adresse' => ['nichts', false];
        yield 'leer' => ['', false];
    }

    /**
     * {@see Curl::send} entscheidet nicht selbst, sondern fragt.
     *
     * **Der Sinn ist die Einzahl.** Stünde die Bedingung ein zweites Mal im
     * Rumpf, wäre der Wächter darüber grün und die zweite Fassung die, die beim
     * nächsten Umbau auseinanderläuft — der Fehler, an dem dieses Projekt am
     * häufigsten verloren hat.
     */
    public function test_the_rule_is_written_once(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Acme/Curl.php');

        $this->assertSame(
            1,
            substr_count($quelle, "str_starts_with(\$url, 'https://')"),
            'Die Regel steht mehr als einmal im Quelltext — oder gar nicht mehr.',
        );

        $this->assertStringContainsString(
            'if (! $this->permitted($url)) {',
            $quelle,
            'send() prüft nicht über permitted() — dann gibt es zwei Fassungen der Regel.',
        );
    }
}
