<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Panel\Release;
use PHPUnit\Framework\TestCase;

/**
 * Die Fassung, die das Panel nennt, ist die, die läuft.
 *
 * **Der Anlass ist ein Fehler, der zwei Jahre ausgeliefert war.**
 * `config('app.version')` las `env('SRVPANEL_VERSION', '0.1.0-dev')`, und die
 * Variable wird nirgends gesetzt — nicht im Paket, nicht in der Einrichtung,
 * nicht in der `.env`. Die Marke im Menü zeigte `0.1.0-dev`, und der Kommentar
 * daneben nennt sie „die erste Frage bei jedem Fehlerbericht".
 *
 * Niemand hat es gemerkt, weil nichts den Bezug prüfte. Genau das ist der
 * Fehler, den `CLAUDE.md` als den teuersten dieses Projekts führt: *eine
 * Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder ein
 * Werkzeug den Bezug prüft.*
 *
 * **Ein Vorgabewert für eine Variable, die niemand setzt, ist kein
 * Vorgabewert — er ist die Antwort.** Das ist die Regel, die dieser Wächter
 * durchsetzt.
 */
final class ReleaseVersionTest extends TestCase
{
    /**
     * Das Verzeichnis einer Auslieferung nennt die Fassung — und sonst keins.
     *
     * Die Muster stammen aus `packaging/install.sh`: Jede Fassung liegt unter
     * `/opt/srvpanel/releases/<fassung>`, und `current` verweist darauf.
     */
    public function test_a_release_directory_names_its_version(): void
    {
        $this->assertSame('0.5.1-rc.2', Release::of('/opt/srvpanel/releases/0.5.1-rc.2'));
        $this->assertSame('0.5.1', Release::of('/opt/srvpanel/releases/0.5.1'));
        $this->assertSame('1.0.0', Release::of('/opt/srvpanel/releases/1.0.0/'));

        // Der Quellbaum ist keine Fassung, und das Wort sagt es.
        $this->assertSame(Release::UNRELEASED, Release::of('/home/user/Server-Control-Panel'));
        $this->assertSame(Release::UNRELEASED, Release::of('/opt/srvpanel/current'));

        // **Kein `v` davor.** Das trägt der Git-Tag, das Verzeichnis nicht —
        // wer hier lockert, lässt jeden Verzeichnisnamen durchgehen.
        $this->assertSame(Release::UNRELEASED, Release::of('/opt/srvpanel/releases/v0.5.1'));
    }

    /**
     * Und die Fassung kommt **nicht** aus einer Umgebungsvariable.
     *
     * Die Gegenrichtung, und sie ist die eigentliche Regel. Ein `env()` an
     * dieser Stelle sieht in jeder Durchsicht harmlos aus — es wird erst
     * dadurch falsch, dass niemand die Variable setzt, und das sieht man der
     * Zeile nicht an.
     *
     * **Geprüft wird deshalb die Zeile und nicht der Wert.** Ein Test gegen
     * `config('app.version')` wäre grün, sobald irgendjemand die Variable in
     * seiner eigenen `.env` setzt — und rot auf jedem Server, der das nicht
     * tut. Er prüfte damit die Umgebung des Prüfers.
     */
    public function test_the_version_does_not_come_from_an_unset_variable(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/app.php');

        $this->assertMatchesRegularExpression(
            "/'version' => .*Release::version\(\)/",
            $config,
            "config('app.version') liest die Fassung woanders her. Sie steht im Namen des ".
            'Verzeichnisses, in dem die Anwendung liegt — das ist die einzige Quelle, die '.
            'ein Update von selbst mitzieht.',
        );

        $this->assertDoesNotMatchRegularExpression(
            "/'version' => env\(/",
            $config,
            'Die Fassung kommt aus einer Umgebungsvariable. Wird sie nirgends gesetzt — und '.
            'SRVPANEL_VERSION wurde es zwei Jahre lang nicht —, meldet das Panel seinen '.
            'Vorgabewert, und zwar sichtbar im Menü.',
        );
    }

    /**
     * Was die Fassung ausliest, wird auch von jemandem gebraucht.
     *
     * Ohne diese Richtung könnte `Release` zurückgebaut werden, ohne dass es
     * auffällt: Die Marke im Menü zeigte wieder irgendetwas, und die beiden
     * Prüfungen oben blieben grün, weil sie nur die Herkunft prüfen.
     */
    public function test_the_version_reaches_the_menu_and_the_command(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertStringContainsString(
            "config('app.version')",
            (string) file_get_contents($root.'/app/Http/Middleware/HandleInertiaRequests.php'),
            'Die Oberfläche bekommt die Fassung nicht mehr — dann steht im Menü nichts oder etwas anderes.',
        );

        $this->assertStringContainsString(
            'Release::version()',
            (string) file_get_contents($root.'/app/Console/Commands/Version.php'),
            'srvpanel:version nennt die Fassung nicht mehr aus derselben Quelle wie die Oberfläche.',
        );
    }
}
