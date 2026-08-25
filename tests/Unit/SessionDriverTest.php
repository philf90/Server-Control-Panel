<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Die Sitzungsliste hängt am Treiber — und sagt es, statt leer zu bleiben.
 *
 * ## Der Befund
 *
 * Befund 15 aus `docs/84`. `Sessions::of()` liest `DB::table('sessions')`, und
 * gefüllt wird die Tabelle nur vom Treiber `database`. Steht `SESSION_DRIVER`
 * auf `file` oder `redis`, kommen null Zeilen zurück, der Bereich verschwindet
 * — und „keine offenen Sitzungen" ist nicht von „nicht nachgesehen" zu
 * unterscheiden.
 *
 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
 * > tun".**
 *
 * Derselbe Satz wie bei `apt-get update` in A1, diesmal an einem
 * Konfigurationswert statt an einem Rückgabewert. Und gehalten hat ihn nichts:
 * kein Wächter, keine Prüfung, kein Satz im Klassenkopf — der sonst sehr genau
 * aufschreibt, was gelesen wird und was nicht.
 *
 * ## Was hier steht und was in der CI
 *
 * Framework-frei prüfbar ist die **Naht**: dass die Auslieferung `database`
 * sagt, dass `Sessions` den Treiber überhaupt fragt, und dass die Seite die
 * Antwort trägt. Was der Browser daraus macht, misst `AccountAccessTest` nicht
 * — das ist ein Zustand, den man einstellen muss, und er gehört auf einen
 * Server.
 */
final class SessionDriverTest extends TestCase
{
    use WithoutPhpComments;

    /** Die Auslieferung sagt `database` — an beiden Stellen, die sie hat. */
    public function test_the_shipped_configuration_uses_the_database_driver(): void
    {
        $config = $this->withoutComments($this->read('config/session.php'));

        $this->assertMatchesRegularExpression(
            "/'driver'\s*=>\s*env\(\s*'SESSION_DRIVER'\s*,\s*'database'\s*\)/",
            $config,
            'config/session.php liefert nicht mehr `database` aus. Ohne diesen Treiber bleibt die '
            .'Tabelle `sessions` leer, und die Sitzungsliste zeigt nichts an.',
        );

        $this->assertStringContainsString('SESSION_DRIVER=database', $this->read('.env.example'),
            '.env.example nennt einen anderen Treiber als config/session.php — dann gilt auf einem '
            .'frisch eingerichteten Server etwas anderes als in der Vorgabe.');
    }

    /**
     * `Sessions` fragt den Treiber, statt ihn vorauszusetzen.
     *
     * Gefragt wird nach der Stelle und nicht nach dem Wort `database`: Der
     * Vergleich darf sich ändern, das Nachsehen nicht.
     */
    public function test_the_session_list_asks_which_driver_is_in_use(): void
    {
        $quelle = $this->withoutComments($this->read('app/Support/Authorization/Sessions.php'));

        $this->assertStringContainsString("config('session.driver')", $quelle,
            'Sessions fragt den Sitzungstreiber nicht mehr. Dann ist eine leere Liste wieder '
            .'nicht von „nicht nachgesehen" zu unterscheiden.');

        $this->assertStringContainsString('public static function readable(): bool', $quelle,
            'Sessions::readable() gibt es nicht mehr — die Seite kann die Frage dann nicht stellen.');
    }

    /**
     * Und die Seite trägt die Antwort.
     *
     * **Ohne diese Hälfte wäre die Frage gestellt und nicht beantwortet** —
     * genau die Fehlerklasse dieses Projekts: ein Wert, der geschrieben und nie
     * gelesen wird, ist von aussen nicht von einem zu unterscheiden, den es
     * nicht gibt.
     */
    public function test_the_page_says_so_when_it_cannot_answer(): void
    {
        $controller = $this->withoutComments($this->read('app/Http/Controllers/AccountController.php'));

        $this->assertStringContainsString("'sessionsReadable' => Sessions::readable()", $controller,
            'Der Controller schickt die Antwort nicht mit.');

        $seite = $this->read('resources/js/Pages/Accounts/Form.vue');

        $this->assertStringContainsString('sessionsReadable', $seite,
            'Die Kontenseite liest die Angabe nicht — geschrieben und nie gelesen ist so gut '
            .'wie gar nicht geschrieben.');

        $this->assertStringContainsString('SESSION_DRIVER', $seite,
            'Die Seite nennt den Grund nicht. Ein Hinweis, der nicht sagt, woran es liegt, '
            .'schickt den Leser suchen.');
    }

    private function read(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad);
    }
}
