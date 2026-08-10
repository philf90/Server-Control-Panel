<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\KeepPreviousUrl;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein Ereigniskanal ist keine Seite, zu der jemand zurückkehrt.
 *
 * **Der Anlass ist der Fehler, der die Zwischenabnahme eine Stunde gekostet
 * hat.** Laravel merkt sich jede GET-Anfrage als „vorige Seite"
 * ({@see StartSession::storeCurrentUrl()}), und eine `ValidationException`
 * leitet dorthin zurück. Der Vorgangskanal `/operations/{id}/stream` ist eine
 * GET-Anfrage — `EventSource` schickt kein `X-Requested-With` und gilt damit
 * nicht als XHR.
 *
 * Folge: **Jeder Formularfehler dieses Panels landete auf dem Ereigniskanal**,
 * sobald irgendwo ein Vorgang lief. Der Benutzer sah keine Meldung; gehörte der
 * Vorgang einem anderen Konto, sah er eine 403 ohne erkennbaren Auslöser.
 * `docs/39` Punkt 3 ist genau daran hängengeblieben, und die Meldung, die alles
 * erklärt hätte, kam nie an.
 *
 * **Warum `RedirectTargetTest` das nicht gefunden hat**, obwohl er genau diese
 * Regel durchsetzt: Er liest `back()`-Aufrufe im eigenen Code. Die
 * Weiterleitung einer `ValidationException` macht das Framework.
 *
 * > **Eine Regel mit Wächter, und daneben eine Tür, durch die dieselbe Regel
 * > gebrochen wird.**
 *
 * Das ist die Lehre über Wächter, die dieser Tag zu den bisherigen hinzufügt:
 * Ein Wächter deckt einen *Weg* ab, nicht eine *Wirkung*. Wer die Wirkung
 * meint, sucht nach dem zweiten Weg dorthin.
 */
final class PreviousUrlTest extends TestCase
{
    /**
     * Die Kennzeichnung ist die, die Laravel selbst liest.
     *
     * **Geprüft wird die Wirkung und nicht die Kopfzeile.** `ajax()` ist die
     * Frage, die `storeCurrentUrl()` stellt; welche Kopfzeile sie beantwortet,
     * ist Sache des Frameworks. Ein Test gegen `X-Requested-With` prüfte unsere
     * Umsetzung gegen sich selbst.
     */
    public function test_the_stream_does_not_look_like_a_page(): void
    {
        $request = Request::create('/operations/1/stream', 'GET');

        $this->assertFalse($request->ajax(), 'Ohne die Mittelschicht sieht die Anfrage aus wie eine Seite.');

        $reached = false;

        (new KeepPreviousUrl)->handle($request, static function (Request $passed) use (&$reached): Response {
            $reached = true;

            return new Response;
        });

        $this->assertTrue($reached, 'Die Mittelschicht reicht die Anfrage nicht weiter.');

        $this->assertTrue(
            $request->ajax(),
            'Die Anfrage gilt wieder als Seite. Dann merkt Laravel sie als „zurück", und der '.
            'nächste Formularfehler landet auf dem Ereigniskanal statt auf dem Formular.',
        );
    }

    /**
     * Und der Kanal trägt sie — vor der Rechteprüfung.
     *
     * **Die Reihenfolge gehört zur Regel.** `storeCurrentUrl()` läuft auch dann,
     * wenn `can:` abweist; stünde die Kennzeichnung dahinter, kaperte eine 403
     * auf dem Kanal weiterhin das „Zurück" der nächsten Formularseite. Das ist
     * derselbe Fehler wie „eine Prüfung, die eine Zeile zu spät läuft", den der
     * Abnahmelauf von P4 schon einmal gefunden hat.
     */
    public function test_the_route_carries_it_before_the_policy(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        $this->assertMatchesRegularExpression(
            '/->middleware\(\[KeepPreviousUrl::class, \x27can:view,operation\x27\]\)/',
            $routes,
            'Der Vorgangskanal kennzeichnet sich nicht mehr als „keine Seite" — oder erst nach '.
            'der Rechteprüfung, und dann zu spät.',
        );
    }
}
