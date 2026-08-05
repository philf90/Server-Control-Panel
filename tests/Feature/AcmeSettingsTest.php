<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Tls\AcmeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Acme\Directories;
use Tests\TestCase;

/**
 * Die beiden Angaben, ohne die TLS still nichts tut.
 *
 * **Ohne Kontaktadresse bestellt das Panel gar nichts**, und das ist Absicht:
 * Die Adresse ist die Stelle, an die Let's Encrypt schreibt, wenn ein
 * Zertifikat abzulaufen droht — sie gehört gesetzt und nicht aus dem ersten
 * Adminkonto geraten. Die Kehrseite ist ein Server, auf dem TLS aussieht, als
 * wäre es kaputt: Es passiert nichts, und nichts meldet sich. Bis das Formular
 * dafür steht, setzt sie `srvpanel tls --contact=…`.
 *
 * **Geprüft wird hier vorne und nicht erst beim Bestellen.** Eine Adresse, die
 * keine ist, fiele sonst erst auf, wenn ein Kunde eine Domain anlegt — und
 * dann als Vorgang, der ohne Zutun scheitert.
 */
final class AcmeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): AcmeSettings
    {
        return app(AcmeSettings::class);
    }

    /**
     * Ein Lauf mit Optionen fragt den Agenten nicht.
     *
     * Der Rückgabewert 0 ist hier der ganze Beweis: In diesem Container gibt es
     * keinen Agenten, und ein Aufruf von `panel.tls.ensure` endete mit 1.
     */
    public function test_the_contact_address_is_set_from_the_command_line(): void
    {
        $this->artisan('srvpanel:tls', ['--contact' => 'post@beispiel.de'])
            ->assertExitCode(0);

        $this->assertSame('post@beispiel.de', $this->settings()->contact());
    }

    public function test_an_address_that_is_none_is_not_stored(): void
    {
        $this->artisan('srvpanel:tls', ['--contact' => 'post-at-beispiel'])
            ->assertExitCode(1);

        $this->assertNull($this->settings()->contact());
    }

    /**
     * Nur die bekannten Zertifizierungsstellen.
     *
     * Der Wert geht an einen Prozess, der als root eine TLS-Verbindung
     * aufbaut. Der Agent schlägt ihn in seiner eigenen Positivliste nach
     * ({@see Directories}); dass hier dieselbe Liste befragt wird, macht aus
     * einem Tippfehler eine Fehlermeldung statt einen abgewiesenen Vorgang.
     */
    public function test_only_the_known_certificate_authorities_are_accepted(): void
    {
        $this->artisan('srvpanel:tls', ['--directory' => 'https://acme.example.org/directory'])
            ->assertExitCode(1);

        $this->artisan('srvpanel:tls', ['--directory' => Directories::PRODUCTION])
            ->assertExitCode(0);

        $this->assertSame(Directories::PRODUCTION, $this->settings()->directory());
    }

    /** Der Testbetrieb ist die Vorgabe — produktiv sind fünf Fehlversuche je Stunde die Grenze. */
    public function test_staging_is_what_stands_there_until_someone_says_otherwise(): void
    {
        $this->assertSame(Directories::STAGING, $this->settings()->directory());
        $this->assertTrue($this->settings()->staging());
        $this->assertFalse($this->settings()->configured());
    }

    /**
     * Wer eine Angabe setzt, verliert die andere nicht.
     *
     * Beide liegen unter demselben Schlüssel. Ein `updateOrCreate` mit der
     * halben Ablage löschte die andere Hälfte, und zwar lautlos: Danach steht
     * die Zertifizierungsstelle richtig da und bestellt wird trotzdem nichts,
     * weil die Kontaktadresse fehlt.
     */
    public function test_setting_one_value_keeps_the_other(): void
    {
        $this->artisan('srvpanel:tls', ['--contact' => 'post@beispiel.de'])->assertExitCode(0);
        $this->artisan('srvpanel:tls', ['--directory' => Directories::PRODUCTION])->assertExitCode(0);

        $this->assertSame('post@beispiel.de', $this->settings()->contact());
        $this->assertSame(Directories::PRODUCTION, $this->settings()->directory());

        // Und es bleibt bei einer Zeile: Der Schlüssel ist die Einstellung.
        $this->assertSame(1, Setting::query()->where('key', AcmeSettings::KEY)->count());
    }
}
