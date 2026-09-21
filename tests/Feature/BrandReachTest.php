<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\QuotaWarning;
use App\Mail\TestMessage;
use App\Models\Account;
use App\Support\Brand\Logo;
use App\Support\Settings\BrandSettings;
use App\Support\Settings\MailConfiguration;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Marke des Betreibers kommt an — B6, `docs/129 §9`.
 *
 * Gemessen wird durch die **Tür** und nicht am Quelltext: Dass die Teile
 * zusammenpassen, sagt ein Wächter über Dateien; dass sie zusammen etwas tun,
 * sagt nur die Antwort des Servers.
 *
 * ## Ein Teil des Kriteriums ist nicht erfüllbar, und das steht hier
 *
 * `docs/129 §9` verlangt Logo, Farbe, Fusszeile und Absenderadresse „auf der
 * Anmeldeseite **und** in einer verschickten Mail". Eine Mail dieses Panels ist
 * reiner Text — eine Entscheidung aus P2, begründet in
 * {@see TestMessage}: HTML kann auf dem Weg verändert werden, Text
 * nicht. In reinem Text gibt es weder ein Logo noch eine Farbe.
 *
 * Gemessen wird deshalb, was eine Mail tragen **kann**: Name, Fusszeile und
 * Absender. Logo und Farbe stehen auf der Anmeldeseite.
 *
 * > **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den
 * > Verfasser.**
 */
final class BrandReachTest extends TestCase
{
    use RefreshDatabase;

    /** Ein echtes 1×1-PNG — keine leere Datei mit passendem Namen. */
    private function png(): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        return $this->file('logo.png', (string) $bytes);
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        $pfad = tempnam(sys_get_temp_dir(), 'marke');
        file_put_contents((string) $pfad, $bytes);

        // `test: true` — sonst weigert sich Symfony, eine Datei anzunehmen,
        // die nicht wirklich hochgeladen wurde.
        return new UploadedFile((string) $pfad, $name, null, null, true);
    }

    /**
     * Abmelden, bevor die Anmeldeseite geholt wird.
     *
     * **Ohne das misst die Hälfte dieser Fälle nichts.** Wer gerade gespeichert
     * hat, ist angemeldet, und `/login` liegt hinter `guest` — die Antwort ist
     * dann eine Weiterleitung, und ein `assertSee` daran prüft den Text
     * „Redirecting to".
     *
     * > **Ein Prüfkörper, der eine andere Antwort misst als die gemeinte,
     * > misst die falsche — und sein Rot liest sich wie ein Befund.**
     */
    private function abmelden(): void
    {
        auth()->logout();
        $this->flushSession();

        /*
         * **Und die Wache vergessen.** `actingAs()` setzt das Konto nicht nur
         * in die Sitzung, sondern auch in die aufgelöste Wache; ein blosses
         * `flushSession()` liess den Prüfstand angemeldet, und `/login`
         * antwortete weiter mit 302.
         */
        $this->app['auth']->forgetGuards();
    }

    private function operator(): Account
    {
        return Account::factory()->admin()->create();
    }

    /**
     * Speichern durch die Tür — **ohne Urteil**, weil beide Ausgänge hier
     * durchmüssen: die gelungene Ablage und die Abweisung.
     *
     * @param  array<string, mixed>  $felder
     * @return TestResponse<Response>
     */
    private function save(array $felder = []): TestResponse
    {
        return $this->actingAs($this->operator())->put('/settings/branding', array_merge([
            'name' => 'Hoster GmbH',
            'accent_light' => '#111827',
            'accent_dark' => '#fde68a',
            'footer' => 'Betrieben von der Hoster GmbH · Musterstadt',
        ], $felder));
    }

    /**
     * Speichern **und belegen, dass es durchkam**.
     *
     * Hier stand fünfmal ein blosses `assertSessionHasNoErrors()`, und das ist
     * ein Urteil über die Prüfung und keines über den Lauf: Wirft der
     * Controller danach, stehen ebenfalls keine Prüfmeldungen in der Sitzung —
     * ein 500 sieht damit aus wie ein gelungenes Speichern. Genau so ist die
     * tote Weiterleitung `settings.branding` durch jeden dieser Fälle gekommen.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     *
     * Das Ziel steht ausgeschrieben und nicht als blosses `assertRedirect()`:
     * Eine Weiterleitung „irgendwohin" wäre wieder eine Zusicherung, die den
     * Fall nicht trennt, für den es sie gibt.
     *
     * @param  array<string, mixed>  $felder
     */
    private function gespeichert(array $felder = []): void
    {
        $this->save($felder)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/general');
    }

    public function test_the_login_page_carries_name_and_footer(): void
    {
        $this->gespeichert();

        $this->abmelden();

        $antwort = $this->get('/login');
        $antwort->assertOk();

        $props = $antwort->viewData('page')['props'];

        self::assertSame('Hoster GmbH', $props['brand']['name']);
        self::assertSame('Betrieben von der Hoster GmbH · Musterstadt', $props['brand']['footer']);
    }

    /** Und der Titel des Dokuments trägt ihn auch — er steht vor dem ersten Zeichnen fest. */
    public function test_the_document_title_carries_the_name(): void
    {
        $this->gespeichert();

        $this->abmelden();

        $this->get('/login')->assertSee('<title inertia>Hoster GmbH</title>', false);
    }

    /**
     * Ohne Logo ist die Adresse `null` und nicht eine, die ins Leere zeigt.
     *
     * Eine Adresse, die 404 gibt, wäre für die Seite dasselbe wie ein Logo —
     * sie zeigte ein kaputtes Bild statt des eingebauten Zeichens.
     */
    public function test_without_a_logo_the_payload_says_so(): void
    {
        $antwort = $this->get('/login');

        self::assertNull($antwort->viewData('page')['props']['brand']['logo']);
    }

    public function test_an_uploaded_logo_is_served_without_a_login(): void
    {
        $this->gespeichert(['logo' => $this->png()]);

        $this->abmelden();

        $props = $this->get('/login')->viewData('page')['props'];
        self::assertNotNull($props['brand']['logo']);

        // Ohne Anmeldung — die Anmeldeseite hat keine.
        $bild = $this->get('/branding/logo');

        $bild->assertOk();
        $bild->assertHeader('Content-Type', 'image/png');
        $bild->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Ohne Logo ist die Adresse ein 404 und kein leeres Bild. */
    public function test_the_route_is_a_404_without_a_logo(): void
    {
        $this->get('/branding/logo')->assertNotFound();
    }

    /**
     * Ein SVG kommt nicht durch, und die Meldung sagt warum.
     *
     * Ein SVG ist ein Dokument und kein Bild: Es darf Skript enthalten, und
     * ausgeliefert vom eigenen Ursprung läuft dieses Skript in der Sitzung
     * jedes Betrachters — auf der einen Seite, die jeder ohne Konto sieht.
     */
    public function test_an_svg_is_refused(): void
    {
        $svg = $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>x()</script></svg>');

        $antwort = $this->save(['logo' => $svg]);

        $antwort->assertSessionHasErrors('logo');
        self::assertStringContainsString('Skript', session('errors')->first('logo'));
    }

    /** Und eine zu grosse Datei ebenso — mit ihrer Zahl. */
    public function test_a_file_over_the_limit_is_refused(): void
    {
        $gross = $this->file('logo.png', str_repeat('x', Logo::MAX_BYTES + 1));

        $this->save(['logo' => $gross])->assertSessionHasErrors('logo');
        self::assertStringContainsString((string) (int) (Logo::MAX_BYTES / 1024), session('errors')->first('logo'));
    }

    /**
     * Eine Farbe, unter der niemand mehr liest, wird abgewiesen — mit ihrer
     * Zahl und mit der Fläche, an der sie entsteht.
     */
    public function test_an_unreadable_colour_is_refused_with_its_number(): void
    {
        $antwort = $this->save(['accent_light' => '#cccccc']);

        $antwort->assertSessionHasErrors('accent_light');

        $meldung = session('errors')->first('accent_light');

        self::assertStringContainsString('1,54:1', $meldung, 'Der gemessene Wert steht in der Meldung.');
        self::assertStringContainsString('#fafafb', $meldung, 'Und die Fläche, an der er entsteht.');
    }

    /** Die gespeicherte Marke bleibt dabei, wie sie war. */
    public function test_a_refused_colour_changes_nothing(): void
    {
        $this->gespeichert();
        $this->save(['accent_light' => '#cccccc']);

        self::assertSame('#111827', app(Settings::class)->brand()->accent_light);
    }

    /**
     * Eine verschickte Mail trägt Name und Fusszeile.
     *
     * Logo und Farbe stehen nicht darin, und das ist keine Lücke: Diese Mails
     * sind reiner Text (siehe den Kopf dieser Klasse).
     */
    public function test_a_sent_mail_carries_name_and_footer(): void
    {
        $this->gespeichert();

        Mail::fake();
        Mail::to('kunde@example.org')->send(new QuotaWarning('p1000', [
            ['label' => 'Der belegte Platz liegt über dem Kontingent des Plans.', 'detail' => '1.024 MB von 500 MB'],
        ]));

        Mail::assertSent(QuotaWarning::class, static function (QuotaWarning $mail): bool {
            $text = $mail->render();

            return str_contains($text, 'Hoster GmbH')
                && str_contains($text, 'Betrieben von der Hoster GmbH · Musterstadt');
        });
    }

    /**
     * Und die Absenderadresse ist die des Betreibers.
     *
     * Sie steht bei den Mail-Einstellungen und nicht auf der Markenseite —
     * zwei Formulare für einen Wert wären zwei Orte, an denen er veraltet.
     * Gemessen wird, dass sie am Ende wirklich gilt.
     */
    public function test_the_sender_is_the_one_from_the_mail_settings(): void
    {
        app(Settings::class)->saveMail(new MailSettings(
            host: 'relay.example.org',
            from_address: 'panel@hoster.example',
            from_name: 'Hoster GmbH',
        ));

        self::assertTrue(MailConfiguration::apply(app(Settings::class), config()));
        self::assertSame('panel@hoster.example', config('mail.from.address'));
    }

    /** Die Vorgabe bleibt, solange niemand etwas einstellt. */
    public function test_untouched_the_panel_keeps_its_own_name(): void
    {
        $props = $this->get('/login')->viewData('page')['props'];

        self::assertSame(BrandSettings::DEFAULT_NAME, $props['brand']['name']);
        self::assertSame('', $props['brand']['footer']);
    }
}
