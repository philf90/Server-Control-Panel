<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
