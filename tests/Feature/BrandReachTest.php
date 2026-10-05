<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mail\DiagnoseReport;
use App\Mail\QuotaWarning;
use App\Mail\TestMessage;
use App\Models\Account;
use App\Models\Setting;
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

    /** Ein zweites, mit einem roten Bildpunkt — ein anderes Bild und kein anderer Name. */
    private function otherPng(): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGM4oKDwHwAEpAIAwvXz5QAAAABJRU5ErkJggg=='
        );

        return $this->file('logo.png', (string) $bytes);
    }

    /** Das Logo des Prüfstands wieder abräumen, auf dem Weg, den auch das Entfernen nimmt. */
    private function forgetTheLogoAfterwards(): void
    {
        $this->beforeApplicationDestroyed(static function (): void {
            app(Logo::class)->forget();
        });
    }

    /** Die Adresse des Logos, wie die Anmeldeseite sie bekommt. */
    private function logoAddress(): string
    {
        $this->abmelden();

        $adresse = $this->get('/login')->viewData('page')['props']['brand']['logo'];
        self::assertIsString($adresse, 'Die Anmeldeseite nennt kein Logo — dann misst dieser Fall nichts.');

        return $adresse;
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

        // Das Logo liegt unter storage/app/branding und überlebte bis zum
        // 24. September 2026 jeden Lauf — mit festem Namen, deshalb fiel es in
        // einer alten Arbeitskopie nie auf. Zurück über `Logo::forget()`, den
        // Weg, den auch das Entfernen in den Einstellungen nimmt.
        $this->forgetTheLogoAfterwards();

        $this->abmelden();

        $props = $this->get('/login')->viewData('page')['props'];
        self::assertNotNull($props['brand']['logo']);

        // Ohne Anmeldung — die Anmeldeseite hat keine.
        $bild = $this->get('/branding/logo');

        $bild->assertOk();
        $bild->assertHeader('Content-Type', 'image/png');
        $bild->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Nach dem Speichern lädt die Seite vollständig neu.
     *
     * Der Markenblock steht im Kopf des Dokuments, und eine Inertia-Antwort
     * tauscht die Seite und lässt den Kopf, wie er war. Bis zum 1. Oktober
     * 2026 blieb die Farbe deshalb bis F5 die alte (`docs/140 §0` Punkt 2).
     *
     * Gemessen mit den Kopfzeilen, die der Browser schickt, die Fassung aus
     * der Mittelschicht eingeschlossen. Ohne `X-Inertia` ist die Antwort eine
     * gewöhnliche Weiterleitung, und dieser Fall wäre derselbe wie
     * {@see self::gespeichert()}.
     */
    public function test_saving_reloads_the_whole_page(): void
    {
        $fassung = (string) app(HandleInertiaRequests::class)->version(request());

        $antwort = $this->actingAs($this->operator())
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => $fassung])
            ->put('/settings/branding', [
                'name' => 'Hoster GmbH',
                'accent_light' => '#111827',
                'accent_dark' => '#fde68a',
                'footer' => '',
            ]);

        $antwort->assertStatus(409);
        $antwort->assertHeader('X-Inertia-Location', url('/settings/general'));

        // Die Meldung liegt in der Sitzung und kommt mit dem neuen Laden an.
        self::assertSame('Die Marke ist gespeichert.', session('success'));
    }

    /**
     * „Logo entfernen" entfernt das Logo, mit dem Wert, den der Browser
     * schickt.
     *
     * Die Seite schickt Formulardaten, und Inertia 3.6.1 schreibt einen
     * Wahrheitswert darin als `"1"`. Bis zum 1. Oktober 2026 verglich der
     * Controller mit `=== true`, und kein Fall schickte `remove_logo`
     * überhaupt. Im Browser meldete die Seite Erfolg und liess das Logo
     * liegen (`docs/140 §0` Punkt 3).
     */
    public function test_the_logo_is_removed_as_the_browser_sends_it(): void
    {
        $this->forgetTheLogoAfterwards();
        $this->gespeichert(['logo' => $this->png()]);

        self::assertNotNull(app(Logo::class)->path('logo.png'), 'Vorbedingung: Das Logo liegt da.');

        $this->gespeichert(['remove_logo' => '1']);

        self::assertNull(app(Settings::class)->brand()->logo);
        self::assertNull(app(Logo::class)->path('logo.png'), 'Die Datei liegt nach „Logo entfernen" noch da.');

        $this->abmelden();
        $this->get('/branding/logo')->assertNotFound();
    }

    /**
     * Und `"0"` lässt es liegen.
     *
     * Der Gegenfall zum Fall darüber: Eine Prüfung, die nur fragt, ob der
     * Schlüssel da ist, entfernte das Logo auch hier.
     */
    public function test_a_zero_keeps_the_logo(): void
    {
        $this->forgetTheLogoAfterwards();
        $this->gespeichert(['logo' => $this->png()]);
        $this->gespeichert(['remove_logo' => '0']);

        self::assertSame('logo.png', app(Settings::class)->brand()->logo);

        $this->abmelden();
        $this->get('/branding/logo')->assertOk();
    }

    /**
     * Ein neues Logo bekommt eine neue Adresse, und dasselbe Logo behält sie.
     *
     * Die Route liefert mit `max-age=300` aus. Unter einer festen Adresse
     * zeigte ein Browser bis zu fünf Minuten lang das alte Bild, auch beim
     * Neuladen (`docs/140 §0` Punkt 4). Die Fassung kommt aus dem Inhalt:
     * Speichern ohne neues Bild lässt die Adresse stehen.
     */
    public function test_a_new_logo_gets_a_new_address(): void
    {
        $this->forgetTheLogoAfterwards();

        $this->gespeichert(['logo' => $this->png()]);
        $erste = $this->logoAddress();

        $this->gespeichert();
        self::assertSame($erste, $this->logoAddress(),
            'Dasselbe Bild hat eine neue Adresse — dann lädt jeder Besucher es nach jedem Speichern neu.');

        $this->gespeichert(['logo' => $this->otherPng()]);
        $zweite = $this->logoAddress();

        self::assertNotSame($erste, $zweite,
            'Ein neues Bild unter derselben Adresse zeigt der Zwischenspeicher bis zu fünf Minuten lang als das alte.');

        // Und die Adresse mit ihrer Fassung liefert das Bild.
        $this->get((string) parse_url($zweite, PHP_URL_PATH).'?'.parse_url($zweite, PHP_URL_QUERY))->assertOk();
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
        // Unter dem Bruch „SVG kommt durch" legt dieser Fall die Datei ab. Ohne
        // Abräumen lag sie seit dem 28. September in storage/app/branding,
        // gefunden am 1. Oktober beim Nachsehen nach einer Messung.
        $this->forgetTheLogoAfterwards();

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
     * Zahl, dem Grund, an dem sie entsteht, und seinem Ort.
     *
     * **Seit dem 3. Oktober 2026 ist der schlechteste Grund eine Tönung.** Auf
     * `#fafafb` erreicht `#cccccc` 1,54:1, auf der Tönung einer Warnung darüber
     * 1,32:1 (`docs/140 §6c`). Die Tür benutzt also die schärfere Prüfung, und
     * die Meldung nennt den Ort, weil der Hexwert einer Tönung in keinem
     * Stylesheet steht.
     */
    public function test_an_unreadable_colour_is_refused_with_its_number(): void
    {
        $antwort = $this->save(['accent_light' => '#cccccc']);

        $antwort->assertSessionHasErrors('accent_light');

        $meldung = session('errors')->first('accent_light');

        self::assertStringContainsString('1,32:1', $meldung, 'Der gemessene Wert steht in der Meldung.');
        self::assertStringContainsString('#ede8e0', $meldung, 'Und der Grund, an dem er entsteht.');
        self::assertStringContainsString('(der Tönung einer Warnung)', $meldung, 'Und sein Ort — der Hexwert einer Tönung steht in keinem Stylesheet.');
    }

    /**
     * Der Hinweis neben dem Feld rechnet dieselbe Prüfung wie das Speichern.
     *
     * Zeigte die Seite weiter die Rechnung über die Flächen allein, stünde
     * neben dem Feld „Gemessen 6:1 auf #fafafb", und eine Farbe knapp darüber
     * würde beim Speichern abgewiesen, ohne dass der Hinweis sie je knapp
     * genannt hätte. Gemessen an den Prüffarben des Laufs (`docs/140 §2`):
     * Beide entscheidet eine Tönung, und die nennt der Hinweis mit ihrem Ort.
     */
    public function test_the_hint_beside_the_field_is_the_check_that_saving_asks(): void
    {
        $this->gespeichert(['accent_light' => '#0b6e4f', 'accent_dark' => '#6ee7b7']);

        $hell = BrandSettings::verdictLight('#0b6e4f');
        $dunkel = BrandSettings::verdictDark('#6ee7b7');

        self::assertNotNull($hell['place'], 'Untergrenze: Entscheidet hier eine Fläche, trennt dieser Fall die beiden Prüfungen nicht.');
        self::assertNotNull($dunkel['place'], 'Untergrenze: Entscheidet hier eine Fläche, trennt dieser Fall die beiden Prüfungen nicht.');

        $this->actingAs($this->operator())->get('/settings/general')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contrast.light.surface', $hell['surface'])
                ->where('contrast.light.place', $hell['place'])
                ->where('contrast.dark.surface', $dunkel['surface'])
                ->where('contrast.dark.place', $dunkel['place'])
                ->etc());
    }

    /** Die gespeicherte Marke bleibt dabei, wie sie war. */
    public function test_a_refused_colour_changes_nothing(): void
    {
        $this->gespeichert();
        $this->save(['accent_light' => '#cccccc']);

        self::assertSame('#111827', app(Settings::class)->brand()->accent_light);
    }

    /**
     * Was in der Ablage steht — so, wie der nächste Lauf es liest.
     *
     * @return array<string, mixed>
     */
    private function abgelegt(): array
    {
        $wert = Setting::query()->where('key', 'brand')->first()?->value;
        self::assertIsArray($wert, 'Es steht keine Marke in der Ablage — dann misst dieser Fall nichts.');

        return $wert;
    }

    /**
     * Ein leeres Feld heisst „die Vorgabe" — durch die Tür und bis in die Ablage.
     *
     * Entschieden vom Betreiber am 3. Oktober 2026 (`docs/140 §6d`). Bis dahin
     * wies die Tür ein leeres Feld als fehlend ab, und wer zurück wollte,
     * musste die Vorgaben abschreiben.
     *
     * Vorher steht eine eigene Marke da, und das ist der Prüfkörper: Über der
     * Vorgabe gespeichert, sähe ein leeres Feld danach genauso aus wie eines,
     * das nichts bewirkt hat. Abgelegt wird **keine Angabe** und keine
     * Abschrift der Vorgabe — sonst bliebe das Panel bei ihr stehen, wenn eine
     * spätere Fassung sie ändert.
     */
    public function test_an_empty_field_means_the_default(): void
    {
        $this->gespeichert();
        $this->abmelden();
        $this->get('/login')->assertSee('<style>:root', false);
        self::assertSame('Hoster GmbH', $this->abgelegt()['name'], 'Untergrenze: Ohne eigene Marke vorher trennt dieser Fall nichts.');

        $this->gespeichert(['name' => '', 'accent_light' => '', 'accent_dark' => '']);

        $abgelegt = $this->abgelegt();
        self::assertNull($abgelegt['name'], 'Für die Vorgabe steht keine Angabe in der Ablage.');
        self::assertNull($abgelegt['accent_light'], 'Für die Vorgabe steht keine Angabe in der Ablage.');
        self::assertNull($abgelegt['accent_dark'], 'Für die Vorgabe steht keine Angabe in der Ablage.');

        $gelesen = BrandSettings::fromArray($abgelegt);
        self::assertSame(BrandSettings::DEFAULT_NAME, $gelesen->name);
        self::assertSame(BrandSettings::DEFAULT_ACCENT_LIGHT, $gelesen->accent_light);
        self::assertSame(BrandSettings::DEFAULT_ACCENT_DARK, $gelesen->accent_dark);

        $this->abmelden();
        $antwort = $this->get('/login')->assertDontSee('<style>:root', false);
        self::assertSame(BrandSettings::DEFAULT_NAME, $antwort->viewData('page')['props']['brand']['name']);
    }

    /**
     * Eine eingetippte Vorgabe ist keine eigene Angabe — auch in Grossbuchstaben.
     *
     * Wer `#3730A3` eintippt, hat dasselbe gesagt wie ein leeres Feld. Die
     * Frage danach stellt {@see BrandSettings::own()}, und sie hat nur eine
     * Antwort, wenn vorher kleingeschrieben wird.
     */
    public function test_a_default_typed_by_hand_is_no_own_value(): void
    {
        $this->gespeichert([
            'name' => BrandSettings::DEFAULT_NAME,
            'accent_light' => strtoupper(BrandSettings::DEFAULT_ACCENT_LIGHT),
            'accent_dark' => strtoupper(BrandSettings::DEFAULT_ACCENT_DARK),
        ]);

        $abgelegt = $this->abgelegt();
        self::assertNull($abgelegt['name']);
        self::assertNull($abgelegt['accent_light'], 'Eine Vorgabe in Grossbuchstaben ist dieselbe Vorgabe.');
        self::assertNull($abgelegt['accent_dark'], 'Eine Vorgabe in Grossbuchstaben ist dieselbe Vorgabe.');
    }

    /**
     * Das Feld zeigt die eigene Angabe — und bleibt leer, wo keine ist.
     *
     * Stünde im Feld der Wert, der gilt, schickte das nächste Speichern ihn
     * als eigene Angabe zurück, etwa wenn nur ein Logo dazukommt; aus „die
     * Vorgabe" würde still eine Abschrift von ihr. Die Vorgabe selbst kommt
     * daneben mit, als Platzhalter.
     */
    public function test_the_form_shows_the_own_value_and_leaves_the_default_empty(): void
    {
        $this->gespeichert(['name' => '', 'accent_light' => '#0b6e4f', 'accent_dark' => '']);

        $this->actingAs($this->operator())->get('/settings/general')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('brandSettings.name', '')
                ->where('brandSettings.accent_light', '#0b6e4f')
                ->where('brandSettings.accent_dark', '')
                ->where('brandDefaults.name', BrandSettings::DEFAULT_NAME)
                ->where('brandDefaults.accent_light', BrandSettings::DEFAULT_ACCENT_LIGHT)
                ->where('brandDefaults.accent_dark', BrandSettings::DEFAULT_ACCENT_DARK)
                ->etc());
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
            ['reason' => 'disk_over', 'label' => 'Der Speicherplatz ist ausgeschöpft.', 'detail' => '500 MB von 500 MB'],
        ]));

        Mail::assertSent(QuotaWarning::class, static function (QuotaWarning $mail): bool {
            $text = $mail->render();

            return str_contains($text, 'Hoster GmbH')
                && str_contains($text, 'Betrieben von der Hoster GmbH · Musterstadt');
        });
    }

    /**
     * Jeder Betreff beginnt mit dem Namen der Marke.
     *
     * Bis zum 1. Oktober 2026 schrieben alle drei Mails „SrvPanel —" als
     * Wort, auch die an die Kunden des Betreibers (`docs/140 §0` Punkt 6). Der
     * Fall darüber liest den Rumpf und nicht den Betreff.
     */
    public function test_every_subject_begins_with_the_name(): void
    {
        $this->gespeichert();

        $betreffe = [
            (new TestMessage('Erika Muster', 'heute'))->envelope()->subject,
            (new QuotaWarning('p1000', [['reason' => 'traffic_over', 'label' => 'l', 'detail' => 'd']]))->envelope()->subject,
            (new DiagnoseReport([['label' => 'l', 'subject' => 's', 'detail' => 'd', 'since' => 'x']]))->envelope()->subject,
        ];

        foreach ($betreffe as $betreff) {
            self::assertStringStartsWith('Hoster GmbH — ', (string) $betreff);
        }
    }

    /** Und die Testmail spricht von diesem Panel und nicht von „SrvPanel". */
    public function test_the_test_mail_speaks_of_this_panel(): void
    {
        $this->gespeichert();

        $text = (new TestMessage('Erika Muster', 'heute'))->render();

        self::assertStringContainsString('dass dieses Panel über das eingetragene Relay', $text);
        self::assertStringNotContainsString('SrvPanel', $text);
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
