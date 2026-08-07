<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Tls\DnsCredentialInput;
use SrvPanel\Agent\Acme\Dns\Providers;
use Tests\TestCase;

/**
 * Jeder Anbieterschlüssel in der Oberfläche zeigt auf einen, den es gibt.
 *
 * **Warum es diesen Wächter braucht.** Das Formular für die DNS-Zugangsdaten
 * schaltet seine Felder am Anbieter um — RFC 2136 braucht Nameserver und
 * Schlüssel, IPv64.net ein Token. Die Schlüssel stehen dafür als Zeichenketten
 * im Markup (`const IPV64 = 'ipv64'`), und eine Zeichenkette, die auf einen
 * Anbieter zeigt, den es nicht gibt, ist genau der Fehler, an dem dieses
 * Projekt am häufigsten verloren hat: Das Feld erscheint nie, niemand merkt es,
 * und der Betreiber hält den Anbieter für kaputt.
 *
 * Der Fehler wäre still in beide Richtungen — ein Tippfehler im Markup zeigt
 * das falsche Formular, ein umbenannter Schlüssel im Agenten gar keines.
 *
 * **Seit dem 7. August 2026 gilt dasselbe für die Kommandozeile**, und dort war
 * der Fehler schon eingetreten: `srvpanel dns` kannte nur RFC 2136, während das
 * Formular längst sieben Anbieter bediente.
 */
final class DnsProviderReachTest extends TestCase
{
    /** Wo die Schlüssel im Markup stehen. */
    public const COMPONENT = 'resources/js/Components/DnsCredentials.vue';

    /** Und wo die Angaben der Kommandozeile stehen. */
    public const COMMAND = 'app/Console/Commands/DnsCredentials.php';

    /**
     * Ein Satz Angaben je Anbieter — die Form, nicht die Gültigkeit.
     *
     * Sie sind die des Formulars: `zones` ist ein Textfeld, kein Feld je Zone.
     * Wer einen Anbieter baut, trägt hier eine Zeile ein; wer es vergisst,
     * bekommt den Satz aus dem Durchgang zu lesen statt einen Zugriff auf einen
     * fehlenden Schlüssel.
     *
     * **Ohne `@var`, und das ist kein Vergessen.** PHPStan liest die Form
     * dieser Sammlung genauer, als eine Angabe sie beschreiben könnte, und
     * weist jede zurück, die weiter ist — `array<string, array<string, mixed>>`
     * ist kein Untertyp der Formen, die hier wirklich stehen.
     */
    private const INPUTS = [
        Providers::RFC2136 => [
            'server' => '192.0.2.53',
            'zones' => 'example.de',
            'key_name' => 'srvpanel-key',
            'secret' => 'Z2VoZWlt',
        ],
        Providers::IPV64 => ['token' => 'ein-token-mit-genug-zeichen'],
        Providers::HETZNER => ['token' => 'ein-token-mit-genug-zeichen'],
        Providers::CLOUDFLARE => ['token' => 'ein-token-mit-genug-zeichen'],
        Providers::NETCUP => [
            'customer_number' => '12345',
            'api_key' => 'schluessel-abc',
            'api_password' => 'passwort-xyz',
            'zones' => 'example.de',
        ],
        Providers::IONOS => ['api_key' => 'praefix.geheimnis'],
        Providers::DESEC => ['token' => 'ein-token-mit-genug-zeichen'],
    ];

    public function test_every_provider_key_in_the_form_exists(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::COMPONENT);

        // Die Schlüssel stehen als Konstanten am Kopf der Komponente, damit sie
        // hier zu finden sind und nicht verstreut im Markup.
        preg_match_all("/^const [A-Z0-9_]+ = '([a-z0-9]+)'$/m", $source, $matches);

        $keys = array_values(array_unique($matches[1]));

        $this->assertNotSame([], $keys, sprintf(
            'In %s stehen keine Anbieterschlüssel mehr als Konstanten — dann prüft dieser Test nichts.',
            self::COMPONENT,
        ));

        $unknown = array_values(array_diff($keys, Providers::keys()));

        $this->assertSame([], $unknown, sprintf(
            "Diese Anbieter nennt %s, der Agent kennt sie nicht:\n  %s\n\n".
            'Das Formular schaltet daran seine Felder um. Ein Schlüssel, den es nicht gibt, zeigt nie '.
            'ein Feld — und niemand sieht, dass etwas fehlt.',
            self::COMPONENT,
            implode("\n  ", $unknown),
        ));
    }

    /**
     * Und umgekehrt: Wer benutzbar ist, hat auch ein Formular.
     *
     * Ohne diese Richtung stünde ein neuer Anbieter im Auswahlfeld, und wer ihn
     * wählte, bekäme ein leeres Formular — abgeschickt endete er in der
     * Abweisung des Servers, die von Feldern spricht, die niemand sieht.
     */
    public function test_every_usable_provider_has_a_form(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::COMPONENT);

        preg_match_all("/^const ([A-Z0-9_]+) = '([a-z0-9]+)'$/m", $source, $matches, PREG_SET_ORDER);

        $missing = [];

        foreach (Providers::available() as $key) {
            $name = null;

            foreach ($matches as $match) {
                if ($match[2] === $key) {
                    $name = $match[1];
                }
            }

            if ($name === null || ! str_contains($source, "form.provider === {$name}")) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Diese Anbieter lassen sich hinterlegen, haben in %s aber kein Formular:\n  %s",
            self::COMPONENT,
            implode("\n  ", $missing),
        ));
    }

    /**
     * Das Kommando, wo die Angabe anders heisst als das Feld.
     *
     * Zwei Namen für dieselbe Angabe sind hier die Ausnahme und stehen deshalb
     * an einer Stelle: `--key` ist kürzer als `--key-name`, und `--zone` steht
     * mehrfach, wo das Formular ein Textfeld hat.
     *
     * @var array<string, string>
     */
    private const OPTION_NAMES = [
        'key_name' => 'key',
        'zones' => 'zone',
    ];

    /**
     * Und jeder benutzbare Anbieter lässt sich auch von der Kommandozeile setzen.
     *
     * **Der Fund vom 7. August 2026.** `srvpanel dns` baute die Angaben selbst
     * zusammen — und zwar ausschliesslich die von RFC 2136, weil das beim
     * Schreiben der einzige Anbieter war. Schritt 9 hat sieben gebaut, das
     * Formular verzweigt seither an ihnen, und in der Hilfe stand weiter „heute
     * geht nur rfc2136". Ein Betreiber, der IPv64 aus einem Skript setzen
     * wollte, hatte keinen Weg; gemerkt hat es niemand, weil nichts danach
     * fragt.
     *
     * **Geprüft wird gegen das, was der Agent wirklich ablegt.** Für jeden
     * Anbieter läuft ein Satz Angaben durch {@see DnsCredentialInput::config()}
     * — dieselbe Stelle, die auch das Formular prüft —, und für jeden Schlüssel
     * der geprüften Fassung muss das Kommando eine Angabe anbieten. Ein achter
     * Anbieter mit einem neuen Feld fällt damit hier auf und nicht beim ersten
     * Einrichtungsskript.
     *
     * **Warum die Sammlung hier steht und nicht abgeleitet wird:** Wer einen
     * Anbieter baut, trägt eine Zeile ein. Ein Test, der sich seine Eingaben
     * selbst ausdenkt, prüft die Regel gegen seine eigene Rechnung.
     */
    public function test_every_usable_provider_can_be_set_from_the_command_line(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::COMMAND);

        preg_match_all('/\{--([a-z0-9-]+)=/', $source, $matches);

        $options = $matches[1];

        $this->assertContains('provider', $options, 'Die Angaben des Kommandos werden nicht mehr gelesen.');

        foreach (Providers::available() as $key) {
            $this->assertArrayHasKey($key, self::INPUTS, sprintf(
                'Für %s gibt es hier keine Angaben — dann bleibt der Anbieter auf der Kommandozeile ungeprüft.',
                $key,
            ));

            foreach (array_keys(DnsCredentialInput::config(self::INPUTS[$key], $key)) as $field) {
                $option = self::OPTION_NAMES[$field] ?? str_replace('_', '-', (string) $field);

                $this->assertContains($option, $options, sprintf(
                    '%s legt für %s den Wert „%s" ab; `srvpanel dns` kennt dafür keine Angabe --%s. '.
                    'Über die Kommandozeile ist dieser Anbieter damit nicht einzurichten.',
                    'DnsCredentialInput',
                    $key,
                    $field,
                    $option,
                ));
            }
        }
    }
}
