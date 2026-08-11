<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Databases as DatabasesCommand;
use App\Models\Setting;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReadsMethodSource;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * „Nein" und „ich konnte nicht nachsehen" sind zwei Antworten.
 *
 * **Der Anlass, am 11. August 2026 auf `cloudsrv24` gemessen.** `srvpanel db
 * --remote=on --bind=::` band MariaDB IPv6-only und schnitt damit das Panel von
 * seiner eigenen Datenbank ab. Der PostgreSQL-Teil desselben Laufs kam
 * unmittelbar danach an {@see Settings::postgres()}, der Leseversuch scheiterte,
 * und der `catch (Throwable) { return []; }` darin machte daraus eine leere
 * Ablage. Gemeldet wurde:
 *
 * > PostgreSQL: übersprungen — das Panel bietet es nicht an (srvpanel db --postgresql=on).
 *
 * Auf der Betreiberseite stand zur selben Zeit „Wird angeboten: ja", und das war
 * richtig. Beide lasen dieselbe Methode.
 *
 * > Ein Wert, der „nein" und „ich weiss es nicht" nicht auseinanderhält,
 * > behauptet das eine, wenn das andere gilt.
 *
 * **Das Teuerste daran war nicht die falsche Zeile, sondern ihre Plausibilität.**
 * Sie nannte sogar den Befehl zur Abhilfe. Wer ihr folgt, schaltet einen
 * Schalter ein, der längst an ist, und sucht den eigentlichen Fehler nicht.
 */
final class SettingsProbeTest extends TestCase
{
    use ReadsMethodSource;
    use RefreshDatabase;
    use WithoutPhpComments;

    /**
     * Was abgelegt ist, kommt zurück — in beiden Fassungen dasselbe.
     *
     * Die Untergrenze dieses Wächters: Ohne diesen Fall bliebe unbemerkt, wenn
     * {@see Settings::postgresOffered()} immer `null` gäbe. Dann wäre der Test
     * darunter grün und die Auskunft trotzdem wertlos.
     */
    public function test_a_stored_answer_comes_back_as_it_was_stored(): void
    {
        $settings = app(Settings::class);

        $settings->savePostgres(true);
        $this->assertTrue($settings->postgresOffered());
        $this->assertTrue($settings->postgres());

        $settings->savePostgres(false);
        $this->assertFalse($settings->postgresOffered());
        $this->assertFalse($settings->postgres());
    }

    /**
     * Keine Zeile heisst „nein" — der Grundzustand aus `docs/38 §7`.
     *
     * Ein Bestandsserver, auf dem P5b ankommt, bekommt keine zweite
     * Datenbankfläche, weil jemand ein Paket aktualisiert hat.
     */
    public function test_a_missing_row_means_no(): void
    {
        Setting::query()->delete();

        $this->assertFalse(app(Settings::class)->postgresOffered());
    }

    /**
     * **Und ein gescheiterter Leseversuch heisst gar nichts.**
     *
     * Die Tabelle wird wirklich weggenommen und nicht nachgestellt: Auf dem
     * Server war es keine Attrappe, die eine Ausnahme warf, sondern ein
     * Datenbankserver, der niemanden mehr hereinliess. Beide Male scheitert
     * derselbe Aufruf an derselben Stelle.
     *
     * `postgres()` bleibt dabei `false`, und das ist Absicht — die beiden
     * Lesestellen im Panel entscheiden über eine Kundenfläche, und die Richtung
     * im Zweifel ist die der Mandantenklammer: nichts.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): in `Settings::probe()`
     * das `return null;` im letzten `catch` auf `return [];` ändern.
     */
    public function test_a_failed_look_is_not_a_no(): void
    {
        Schema::drop('settings');

        $settings = app(Settings::class);

        $this->assertNull(
            $settings->postgresOffered(),
            'Ein gescheiterter Leseversuch kommt als „nein" zurück. Dann behauptet jede Meldung darüber '
            .'eine Absicht des Betreibers, die niemand gelesen hat.',
        );

        $this->assertFalse(
            $settings->postgres(),
            'Im Zweifel wird nichts angeboten — das ist die Richtung der Mandantenklammer.',
        );
    }

    /**
     * Und die Kommandozeile fragt die dreiwertige Fassung.
     *
     * **Sonst nützt der Unterschied nichts.** Genau diese Stelle hat die falsche
     * Meldung ausgegeben; sie ist der Grund, aus dem es
     * {@see Settings::postgresOffered()} überhaupt gibt. Ein `postgres()` hier
     * wäre der Rückfall in denselben Fehler, und er wäre grün getestet.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): in
     * `Databases::remotePostgres()` `postgresOffered()` durch `postgres()`
     * ersetzen.
     */
    public function test_the_command_line_asks_the_three_valued_question(): void
    {
        $quelle = $this->withoutComments(
            "<?php\n".(string) $this->methodSource(DatabasesCommand::class, 'remotePostgres'),
        );

        $this->assertStringContainsString('postgresOffered', $quelle,
            'Databases::remotePostgres() fragt nicht die dreiwertige Fassung — dann meldet es wieder '
            .'„das Panel bietet es nicht an", wenn es die Einstellungen nur nicht lesen konnte.');

        $this->assertStringContainsString('nicht lesbar', $quelle,
            'Der Fall „nicht nachgesehen" hat keine eigene Meldung.');
    }
}
