<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Providers;

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
 */
final class DnsProviderReachTest extends TestCase
{
    /** Wo die Schlüssel im Markup stehen. */
    public const COMPONENT = 'resources/js/Components/DnsCredentials.vue';

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
}
