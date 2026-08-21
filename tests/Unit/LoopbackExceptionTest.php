<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Curl;

/**
 * Die eine Ausnahme von „nach draussen nur über https" — und ihre Grenzen.
 *
 * **Warum es diesen Wächter gibt.** `agent/src/Acme/Curl.php` ist der einzige
 * Ort, an dem der Agent nach draussen spricht, und seine erste von vier Zusagen
 * lautete ohne Ausnahme „nur https". P7 braucht die HTTP-API von PowerDNS, und
 * die spricht kein TLS: Fassung 4.8.3 kennt für ihren Webserver weder eine
 * Option für ein Zertifikat noch eine für einen Schlüssel noch eine für einen
 * Unix-Socket (gemessen, `docs/71 §4.1`).
 *
 * > Eine Ausnahme ohne Wächter ist keine Ausnahme, sondern der neue Normalfall.
 *
 * **Der Fall, an dem die naheliegende Fassung stirbt**, steht unten als eigene
 * Zeile: `http://127.0.0.1.angreifer.invalid/` beginnt mit derselben
 * Zeichenkette wie die erlaubte Adresse und zeigt woandershin. Wer den Anfang
 * vergleicht statt den zerlegten Wirt, lässt ihn durch — dieselbe Fehlerklasse,
 * gegen die dieses Repo `AnchoredPatternTest` hat.
 *
 * **Und `[::1]` steht mit Klammern in {@see Curl::LOOPBACK}**, weil
 * `parse_url()` den Wirt so zurückgibt. Ohne die Klammern griffe die Ausnahme
 * für IPv6 nie, und zwar unauffällig: Der Code sähe gebaut aus, und der Fehler
 * fiele erst dem auf, der den Dienst auf `::1` bindet.
 */
final class LoopbackExceptionTest extends TestCase
{
    /** Der Port, mit dem der Agent die lokale API erreicht. */
    private const PORT = 8081;

    /**
     * Ohne das Argument gibt es die Ausnahme nicht — und das ist der Zustand
     * jedes bestehenden Aufrufers.
     *
     * `new Curl` ohne Argument steht an sechzehn Stellen: bei der
     * Zertifizierungsstelle und bei jedem der acht DNS-Anbieter. Keiner von
     * ihnen soll je im Klartext sprechen können, und keiner muss dafür etwas
     * tun.
     */
    #[DataProvider('klartext')]
    public function test_without_the_port_no_plain_text_is_permitted(string $url): void
    {
        $this->assertFalse(
            (new Curl)->permitted($url),
            sprintf('Ohne loopbackPort darf %s nicht durchgehen.', $url),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function klartext(): iterable
    {
        yield 'die lokale API' => ['http://127.0.0.1:8081/api/v1/servers'];
        yield 'die lokale API über IPv6' => ['http://[::1]:8081/api/v1/servers'];
        yield 'irgendetwas Fremdes' => ['http://beispiel.invalid/'];
    }

    /**
     * Mit dem Argument geht genau die eine Adresse durch — und sonst nichts.
     *
     * @param  bool  $erlaubt  Was herauskommen soll
     */
    #[DataProvider('adressen')]
    public function test_the_exception_is_exactly_as_wide_as_it_says(string $url, bool $erlaubt): void
    {
        $this->assertSame(
            $erlaubt,
            (new Curl(self::PORT))->permitted($url),
            sprintf('%s sollte %s werden.', $url, $erlaubt ? 'durchgelassen' : 'abgewiesen'),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function adressen(): iterable
    {
        // Was durchgehen muss — ohne diese Zeilen misst der Wächter nichts.
        yield 'https bleibt unberührt' => ['https://acme.example.org/directory', true];
        yield 'die lokale API' => ['http://127.0.0.1:8081/api/v1/servers', true];
        yield 'dieselbe über IPv6' => ['http://[::1]:8081/api/v1/servers', true];

        // Der Fall, an dem der Präfixvergleich stirbt.
        yield 'ein Name, der mit der Adresse beginnt' => ['http://127.0.0.1.angreifer.invalid:8081/api', false];

        // Ein Name ist kein Wert, sondern ein Versprechen auf einen.
        yield 'localhost' => ['http://localhost:8081/api', false];

        // Die Benutzerangabe verschiebt den Wirt hinter das @.
        yield 'die Adresse als Benutzername' => ['http://127.0.0.1@angreifer.invalid/api', false];
        yield 'Adresse und Port als Benutzername' => ['http://127.0.0.1:8081@angreifer.invalid/api', false];

        // Die Sprungmarke gehört nicht zum Wirt.
        yield 'die Adresse als Sprungmarke' => ['http://angreifer.invalid/#127.0.0.1:8081', false];

        // Andere Schreibweisen derselben Adresse werden nicht anerkannt: Was
        // der Agent selbst baut, baut er in der einen Form.
        yield 'hexadezimal geschrieben' => ['http://0x7f.0.0.1:8081/api', false];
        yield 'verkürzt geschrieben' => ['http://127.1:8081/api', false];
        yield 'IPv6 ohne Klammern' => ['http://::1:8081/api', false];

        // Der Port gehört zur Ausnahme und nicht die ganze Rückschleife.
        yield 'ohne Port' => ['http://127.0.0.1/api', false];
        yield 'ein anderer Port' => ['http://127.0.0.1:9999/api', false];

        // Und die übrigen Formen bleiben draussen.
        yield 'führendes Leerzeichen' => [' http://127.0.0.1:8081/api', false];
        yield 'ein anderes Schema' => ['ftp://127.0.0.1:8081/api', false];
        yield 'eine Datei' => ['file:///etc/shadow', false];
        yield 'gar keine Adresse' => ['nichts', false];
    }

    /**
     * Die Aufzählung nennt beide Adressen und keinen Namen.
     *
     * **Der Sinn dieser Prüfung ist die Klammer.** `parse_url()` gibt den Wirt
     * einer IPv6-Adresse mit Klammern zurück; ohne sie stünde hier eine
     * Aufzählung, die für IPv6 nie zutrifft, und der Wächter darüber wäre
     * grün — er prüfte ja nur, dass etwas dasteht.
     */
    public function test_the_list_holds_addresses_and_no_names(): void
    {
        $this->assertSame(['127.0.0.1', '[::1]'], Curl::LOOPBACK);

        foreach (Curl::LOOPBACK as $eintrag) {
            $this->assertSame(
                $eintrag,
                parse_url('http://'.$eintrag.':8081/x', PHP_URL_HOST),
                sprintf('%s ist nicht die Form, die parse_url zurückgibt.', $eintrag),
            );
        }
    }
}
