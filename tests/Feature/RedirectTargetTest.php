<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Wer weiterleitet, nennt das Ziel. `back()` kann es hier nicht wissen.
 *
 * **Der Befund.** Wer im Konto die Darstellung umstellte, landete auf der
 * Übersicht. Gespeichert war richtig — man stand danach nur woanders. Dasselbe
 * galt für „Konto gespeichert", „Passwort geändert" und alle drei Antworten
 * der Mailprüfung: sechs Stellen, ein Fehler.
 *
 * **Warum `back()` in diesem Panel nicht zurückführt.** Drei Dinge, die
 * einzeln jedes für sich richtig sind:
 *
 *   1. Der Vhost schickt `Referrer-Policy: no-referrer` — das Panel gibt nicht
 *      preis, von welcher Adresse jemand kam (`agent/src/Ops/PanelVhost.php`).
 *      Der Browser sendet damit kein `Referer`.
 *   2. `back()` fragt zuerst genau dieses `Referer` und nimmt sonst die
 *      zuletzt in der Sitzung vermerkte Adresse (`UrlGenerator::previous()`).
 *   3. Vermerkt wird sie nur bei einem GET, das kein XHR ist
 *      (`StartSession::storeCurrentUrl`). Jede Navigation über Inertia ist
 *      eines — in der Sitzung steht deshalb der letzte vollständige
 *      Seitenaufruf, nach der Anmeldung die Übersicht.
 *
 * Also fiel `back()` auf `/` durch. **Beim Entwickeln fällt das nicht auf:**
 * Ohne nginx gibt es die Kopfzeile aus (1) nicht, der Browser schickt ein
 * `Referer`, und alles stimmt. Der Fehler entsteht erst auf dem Zielserver —
 * dieselbe Sorte Lücke wie die Vorlagen, die deshalb als Text geprüft werden.
 *
 * Und wieder dasselbe Muster: eine Zeichenkette, die auf etwas verweist, ohne
 * dass jemand den Bezug prüft. „Zurück" ist eine Adresse, die niemand kennt.
 *
 * Der alte Test hat das nicht gemerkt, weil er `assertRedirect()` **ohne Ziel**
 * aufrief — eine Zusicherung, die nur sagt, dass überhaupt weitergeleitet
 * wird. Genau deshalb steht unten jedes Ziel ausgeschrieben.
 */
final class RedirectTargetTest extends TestCase
{
    use RefreshDatabase;
    use WithoutPhpComments;

    /** Dasselbe Passwort wie in `ProfileTest` — die Schranke ist dort begründet. */
    private const PASSWORD = 'probe-passwort-nur-fuer-tests';

    /** @return list<string> */
    private function controllers(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Http/Controllers', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertGreaterThan(4, count($files), 'Es werden kaum Controller gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    public function test_no_controller_leaves_the_target_to_back(): void
    {
        $found = [];

        foreach ($this->controllers() as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            /*
             * Beide Schreibweisen: der Helfer `back()` und `redirect()->back()`.
             * Nur die erste kam vor — die zweite ist der naheliegende Weg, sie
             * wieder einzuführen, ohne dass etwas meldet.
             */
            if (preg_match_all('/(?<![\w>$])back\(\)|redirect\(\)\s*->\s*back\(/', $source, $treffer) > 0) {
                $found[] = sprintf(
                    '%s: %d×',
                    str_replace(dirname(__DIR__, 2).'/', '', $path),
                    count($treffer[0]),
                );
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Controller überlassen das Ziel `back()`:\n  %s\n\n".
            "In diesem Panel weiss `back()` nicht, wohin zurück ist: Der Vhost schickt\n".
            "`Referrer-Policy: no-referrer`, und Inertia navigiert über XHR — also gibt es weder\n".
            "ein `Referer` noch eine in der Sitzung vermerkte Adresse, und Laravel leitet auf `/`.\n".
            'Wer weiterleitet, nennt das Ziel: `to_route(...)`.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Jede Datei unter `app/` — der Name einer Route kann überall stehen.
     *
     * Nicht nur Controller: `HandleInertiaRequests` baut die Adresse des Logos
     * über `route('branding.logo')`, und ein toter Name dort schlägt auf
     * **jeder** Seite zu statt auf einer.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Ein genannter Routenname ist eine Route, die es gibt.
     *
     * **Der Befund.** `BrandingSettingsController::update()` leitete auf
     * `settings.branding` weiter — einen Namen, den `routes/web.php` nie
     * vergeben hat: Die Markenfelder sind in `/settings/general` gefaltet
     * worden, und die Weiterleitung blieb stehen. `to_route()` wirft dafür
     * `RouteNotFoundException`, also gab **jedes** gelungene Speichern einen
     * 500 — nachdem die Marke gespeichert war.
     *
     * **Der Test darüber war grün**, weil er `assertSessionHasNoErrors()`
     * geprüft hat: Für eine Ausnahme im Controller stehen keine Prüfmeldungen
     * in der Sitzung, und ein 500 sieht damit aus wie ein gelungener Lauf.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     *
     * Und das ist die Fehlerklasse, die dieses Repo am häufigsten trifft: eine
     * Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder
     * ein Werkzeug den Bezug prüft. Der Test oben hält, dass ein Ziel
     * **genannt** wird — über seine Existenz sagt er nichts.
     *
     * > **Ein Wächter, der prüft, dass ein Ziel genannt ist, hat nicht
     * > geprüft, dass es das Ziel gibt.**
     *
     * Gemessen wird gegen `Route::getRoutes()` und nicht gegen eine Liste in
     * diesem Test — eine zweite Liste wäre die, die veraltet.
     */
    public function test_every_named_route_the_code_reaches_for_exists(): void
    {
        $vorhanden = array_keys(Route::getRoutes()->getRoutesByName());

        $namen = [];
        $fehlen = [];

        foreach ($this->sources() as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            if (preg_match_all('/(?:to_route|route)\(\s*\'([^\']+)\'/', $source, $treffer, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($treffer[1] as $i => [$name, $_]) {
                /*
                 * **`$request->route('domain')` ist ein Parametername und kein
                 * Routenname.** Er kommt in `app/` heute nicht vor; die
                 * Ausnahme steht trotzdem hier, weil der Wächter sonst beim
                 * ersten solchen Aufruf einen Befund erfindet — und ein
                 * Wächter, der zu viel meldet, wird abgeschaltet.
                 */
                $offset = (int) $treffer[0][$i][1];

                if (preg_match('/\$\w+\s*->\s*$/D', substr($source, max(0, $offset - 40), min(40, $offset))) === 1) {
                    continue;
                }

                $namen[$name] = true;

                if (! in_array($name, $vorhanden, true)) {
                    $fehlen[] = sprintf('%s: %s', str_replace(dirname(__DIR__, 2).'/', '', $path), $name);
                }
            }
        }

        /*
         * **Die Untergrenze.** Ohne sie stünde dieser Fall grün da, sobald der
         * Ausdruck ins Leere greift — und genau das ist in diesem Repo schon
         * dreimal passiert.
         */
        $this->assertGreaterThan(
            20,
            count($namen),
            'Es werden kaum Routennamen gefunden — dann prüft dieser Fall nichts.',
        );

        $this->assertSame([], array_values(array_unique($fehlen)), sprintf(
            "Diese Stellen nennen eine Route, die es nicht gibt:\n  %s\n\n".
            "`to_route()` und `route()` werfen dafür `RouteNotFoundException` — die Seite gibt 500,\n".
            'und zwar erst, nachdem die Handlung schon geschehen ist.',
            implode("\n  ", array_unique($fehlen)),
        ));
    }

    /**
     * Und die Ziele selbst — ausgeschrieben, nicht bloss „irgendwohin".
     *
     * Ohne diese Prüfung bestünde der Test oben auch dann, wenn jemand
     * `to_route('overview')` schriebe: kein `back()`, und trotzdem stünde man
     * wieder auf der Übersicht.
     */
    public function test_saving_the_theme_stays_on_the_account_page(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/theme', ['theme' => 'light'])
            ->assertRedirect('/settings/profile');

        $this->assertSame('light', $admin->fresh()?->theme);
    }

    public function test_saving_the_account_stays_on_the_account_page(): void
    {
        $admin = Account::factory()->admin()->create(['password' => Hash::make(self::PASSWORD)]);

        $this->actingAs($admin)->patch('/settings/profile', [
            'name' => 'Neuer Name',
            'email' => $admin->email,
            'current_password' => self::PASSWORD,
        ])->assertRedirect('/settings/profile');
    }

    public function test_changing_the_password_stays_on_the_account_page(): void
    {
        $admin = Account::factory()->admin()->create(['password' => Hash::make(self::PASSWORD)]);

        $this->actingAs($admin)->put('/settings/password', [
            'current_password' => self::PASSWORD,
            'password' => 'Ein-anderes-Passwort7',
            'password_confirmation' => 'Ein-anderes-Passwort7',
        ])->assertRedirect('/settings/profile');
    }
}
