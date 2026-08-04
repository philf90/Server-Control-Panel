<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use Tests\TestCase;

/**
 * Die Regeln, die am Domainmodell selbst hängen.
 *
 * Drei davon sind Wächter über Bezüge, die sonst niemand prüft: dass das
 * DocumentRoot kein reserviertes Verzeichnis des Schemas trifft, dass die
 * Zählregeln der Typen mit dem übereinstimmen, was der Betreiber im
 * Kontingentformular liest, und dass `subscriptions.main_domain` eine
 * Abschrift bleibt und keine zweite Wahrheit wird.
 */
final class DomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Diese Tests prüfen Modellregeln, nicht die Mandantenklammer — die
        // hat ihren eigenen Durchgang in DomainTenancyTest.
        app(Tenancy::class)->allowAll();
    }

    /**
     * Den Rückbau anstossen, ohne den Agenten.
     *
     * `withdraw()` ist privat und die Stelle, an der die Abschrift fallen
     * muss — geprüft wird ihre Wirkung, wie in `SubscriptionCleanupTest`.
     */
    private function withdraw(Subscription $subscription): void
    {
        $method = new \ReflectionMethod(Lifecycle::class, 'withdraw');

        $method->invoke(app(Lifecycle::class), $subscription);
    }

    /** @return list<array{0:string}> */
    public static function validDocumentRoots(): array
    {
        return [
            ['httpdocs'],
            ['beispiel.de'],
            ['httpdocs/shop'],
            ['beispiel.de/public'],
            ['app_2/public'],
            ['a-b/c-d/e'],
        ];
    }

    #[DataProvider('validDocumentRoots')]
    public function test_accepts_document_root(string $value): void
    {
        $this->assertTrue(Domain::isValidDocumentRoot($value));
    }

    /** @return list<array{0:string}> */
    public static function rejectedDocumentRoots(): array
    {
        return [
            [''],
            ['/'],
            ['/etc/nginx'],
            ['httpdocs/'],
            ['httpdocs//shop'],
            ['../../etc'],
            ['httpdocs/../../etc'],
            ['..'],
            ['.ssh'],
            ['.env'],
            ['httpdocs/.git'],
            ['httpdocs; rm -rf /'],
            ['httpdocs shop'],
            ["httpdocs\nroot /etc;"],
            ['a/b/c/d/e/f/g/h/i'],
        ];
    }

    #[DataProvider('rejectedDocumentRoots')]
    public function test_rejects_document_root(string $value): void
    {
        $this->assertFalse(Domain::isValidDocumentRoot($value));
    }

    /**
     * **Der Wächter über den Bezug, der sonst keiner wäre.**
     *
     * Die reservierten Verzeichnisse stehen im Agenten, weil sie aus dem
     * Verzeichnisschema kommen. Dieser Test fragt die Liste dort ab, statt sie
     * abzuschreiben — käme morgen ein Verzeichnis dazu, wäre die Prüfung
     * hier von selbst mit dabei. Was auf dem Spiel steht, ist konkret: Ein
     * DocumentRoot auf `logs` liefert die Zugriffsprotokolle des Kunden über
     * HTTP aus, eines auf `.ssh` seine Schlüssel.
     */
    public function test_no_reserved_directory_of_the_scheme_can_be_a_document_root(): void
    {
        $reserved = SubscriptionProvision::reservedDirectories();

        $this->assertNotSame([], $reserved, 'Ohne reservierte Verzeichnisse prüft dieser Test nichts.');
        $this->assertContains('logs', $reserved);
        $this->assertContains('conf', $reserved);

        foreach ($reserved as $directory) {
            $this->assertFalse(
                Domain::isValidDocumentRoot($directory),
                sprintf('%s gehört zum Schema aus §4.5 und darf kein DocumentRoot sein.', $directory),
            );
        }

        // Und die Gegenrichtung: Das eine Verzeichnis, das ausgeliefert wird,
        // muss durchgehen — sonst hätte die Hauptdomain kein Ziel.
        $this->assertTrue(Domain::isValidDocumentRoot(SubscriptionProvision::DOCUMENT_ROOT));
    }

    /**
     * Die Zählregeln der Typen und der Text, den der Betreiber dazu liest,
     * müssen dasselbe sagen.
     *
     * {@see Quota::hint()} verspricht im Formular „Zählt Haupt- und
     * Addon-Domains. Aliasse zählen nicht mit." Stünde die Zählung im Dienst
     * anders, wäre der Hinweis eine falsche Auskunft an der Stelle, an der
     * jemand ein Paket schnürt.
     */
    public function test_types_count_towards_the_quota_the_hint_promises(): void
    {
        $this->assertSame(Quota::Domains, DomainType::Main->countsTowards());
        $this->assertSame(Quota::Domains, DomainType::Addon->countsTowards());
        $this->assertSame(Quota::Subdomains, DomainType::Subdomain->countsTowards());
        $this->assertNull(DomainType::Alias->countsTowards());

        $this->assertStringContainsString('Aliasse zählen nicht mit', Quota::Domains->hint());
    }

    public function test_the_main_domain_cannot_be_removed_on_its_own(): void
    {
        $this->assertFalse(DomainType::Main->removable());
        $this->assertNotContains(DomainType::Main, DomainType::creatable());
    }

    public function test_alias_and_redirect_serve_no_php(): void
    {
        $subscription = Subscription::factory()->create();
        $main = Domain::factory()->main()->for($subscription)->create();

        $alias = Domain::factory()->alias($main)->create();
        $redirect = Domain::factory()->for($subscription)->redirect('https://beispiel.de')->create();

        $this->assertTrue($main->servesPhp());
        $this->assertFalse($alias->servesPhp());
        $this->assertFalse($redirect->servesPhp());
        $this->assertTrue($redirect->isRedirect());
    }

    /**
     * Der absolute Pfad entsteht aus der Konstante des Agenten.
     *
     * Er wird nur angezeigt — an den Agenten geht der relative Teil. Stünde
     * `/var/www/vhosts` hier als Literal, wäre es der zweite Ort für dieselbe
     * Angabe, und der eine, der beim Umzug nachgezogen wird, ist
     * erfahrungsgemäss nicht dieser.
     */
    public function test_absolute_document_root_uses_the_scheme_of_the_agent(): void
    {
        $subscription = Subscription::factory()->create(['name' => 'beispiel.de']);
        $domain = Domain::factory()->main()->for($subscription)->create(['name' => 'beispiel.de']);

        $this->assertSame(
            SubscriptionProvision::VHOSTS.'/beispiel.de/'.SubscriptionProvision::DOCUMENT_ROOT,
            $domain->absoluteDocumentRoot(),
        );

        $alias = Domain::factory()->alias($domain)->create();

        $this->assertNull($alias->absoluteDocumentRoot());
    }

    /**
     * Zwei Abonnements, derselbe Name — die Datenbank sagt nein.
     *
     * Das ist die letzte Schicht und die einzige, die auch dann greift, wenn
     * jemand am Dienst vorbei schreibt. Ohne sie stünden zwei Server-Blöcke
     * mit demselben `server_name` in der Konfiguration, und nginx liefert
     * wortlos den ersten aus — ein Mandantenübergriff, der keine einzige
     * Rechteprüfung berührt.
     */
    public function test_a_domain_name_is_unique_across_the_whole_server(): void
    {
        Domain::factory()->create(['name' => 'beispiel.de']);

        $this->expectException(QueryException::class);

        Domain::factory()->create(['name' => 'beispiel.de']);
    }

    /**
     * Die Abschrift in `subscriptions.main_domain` folgt der Hauptdomain.
     *
     * Und zwar bei jedem der drei Ereignisse, nach denen sie falsch sein
     * könnte: anlegen, umbenennen, entfernen.
     */
    public function test_the_main_domain_is_copied_to_the_subscription(): void
    {
        $subscription = Subscription::factory()->create();

        $this->assertNull($subscription->main_domain);

        $domain = Domain::factory()->main()->for($subscription)->create(['name' => 'beispiel.de']);

        $this->assertSame('beispiel.de', $subscription->refresh()->main_domain);

        $domain->update(['name' => 'beispiel-neu.de']);

        $this->assertSame('beispiel-neu.de', $subscription->refresh()->main_domain);

        $domain->delete();

        $this->assertNull($subscription->refresh()->main_domain);
    }

    /**
     * Auch der Rückbau leert die Abschrift — er ist der vierte Weg.
     *
     * **Der Test darüber hat drei Ereignisse geprüft und den einen Weg
     * übersehen, der nicht über das Modell geht.** `Lifecycle::withdraw()`
     * löschte die Domains mit einem Massenlöschen über den Erbauer, und das
     * feuert keine Modellereignisse. Ein gekündigtes Abonnement behielt seine
     * Hauptdomain damit für immer.
     *
     * Sichtbar wurde es erst auf dem Zielserver, und nicht als schiefe
     * Anzeige, sondern als Absturz: `subscriptions.main_domain` war in P1 als
     * eindeutig angelegt worden, und der zweite Abnahmelauf lief mit
     * demselben Namen in den Index.
     *
     *     Duplicate entry 'abnahme-web-2.invalid'
     *     for key 'subscriptions_main_domain_unique'
     */
    public function test_withdrawing_a_subscription_clears_the_copy(): void
    {
        $subscription = Subscription::factory()->create();
        Domain::factory()->main()->for($subscription)->create(['name' => 'beispiel.de']);

        $this->assertSame('beispiel.de', $subscription->refresh()->main_domain);

        $this->withdraw($subscription);

        $zeile = DB::table('subscriptions')->where('id', $subscription->id)->first();

        $this->assertNotNull($zeile, 'Das Abonnement ist hart fort — es soll weich gelöscht bleiben.');
        $this->assertNull($zeile->main_domain, implode(' ', [
            'Das gekündigte Abonnement hält seine Hauptdomain fest.',
            'Ein Massenlöschen über den Erbauer feuert keine Modellereignisse,',
            'und an einem davon hängt die Abschrift.',
        ]));
    }

    /**
     * Und derselbe Name lässt sich danach wieder vergeben.
     *
     * Das ist die Frage, an der der Abnahmelauf auf dem Server hängen blieb,
     * und sie ist die Umkehrung der P3-Entscheidung: Domains haben keine
     * weiche Löschung, **damit** ein Name wieder frei wird. Eine Abschrift,
     * die ihn festhält, nimmt diese Entscheidung zurück.
     */
    public function test_the_name_is_free_again_after_a_teardown(): void
    {
        $erstes = Subscription::factory()->create();
        Domain::factory()->main()->for($erstes)->create(['name' => 'beispiel.de']);

        $this->withdraw($erstes);

        $zweites = Subscription::factory()->create();

        // Ohne den Fix wirft das eine UniqueConstraintViolationException —
        // aus dem `saved`-Ereignis heraus, mitten im Lebenslauf.
        Domain::factory()->main()->for($zweites)->create(['name' => 'beispiel.de']);

        $this->assertSame('beispiel.de', $zweites->refresh()->main_domain);
    }

    /**
     * `main_domain` ist eine Abschrift und trägt deshalb keinen eindeutigen
     * Index.
     *
     * **Zwei Uniques, die gleich aussehen und es nicht sind.** In P1 wurden
     * `system_user` und `main_domain` nebeneinander als eindeutig angelegt.
     * Für den Systembenutzer ist das richtig: Ein weich gelöschtes Abonnement
     * verbraucht seinen `p1000`, weil die UID sonst wiederverwendet würde.
     * Für die Hauptdomain gilt das Gegenteil — ein Domainname soll nach dem
     * Löschen wieder frei sein.
     *
     * Der Test prüft beide, damit aus „das eine ist weg" nicht versehentlich
     * „beide sind weg" wird.
     */
    public function test_the_copy_is_not_a_second_authority(): void
    {
        $indizes = collect(Schema::getIndexes('subscriptions'));

        $haupt = $indizes->first(
            static fn (array $index): bool => $index['columns'] === ['main_domain'],
        );

        $this->assertNotNull($haupt, 'Der Index auf main_domain ist ganz verschwunden — die Übersicht sucht darüber.');
        $this->assertFalse($haupt['unique'], implode(' ', [
            'main_domain ist wieder eindeutig.',
            'Die Zuständigkeit für „diesen Namen gibt es einmal" liegt bei domains.name;',
            'ein zweiter Index fügt keine Regel hinzu, sondern eine Stelle, an der',
            'dieselbe Regel anders ausfällt — hier gegen weich gelöschte Abonnements.',
        ]));

        $benutzer = $indizes->first(
            static fn (array $index): bool => $index['columns'] === ['system_user'],
        );

        $this->assertNotNull($benutzer);
        $this->assertTrue($benutzer['unique'], implode(' ', [
            'system_user ist nicht mehr eindeutig.',
            'Der muss verbraucht bleiben: Wird die UID neu vergeben, gehören Dateien',
            'auf dem Dateisystem plötzlich jemand anderem.',
        ]));
    }

    /** Eine Zusatzdomain rührt die Abschrift nicht an. */
    public function test_an_addon_domain_does_not_touch_the_copy(): void
    {
        $subscription = Subscription::factory()->create();
        Domain::factory()->main()->for($subscription)->create(['name' => 'beispiel.de']);

        Domain::factory()->for($subscription)->create(['name' => 'zweite.de']);

        $this->assertSame('beispiel.de', $subscription->refresh()->main_domain);
    }

    /**
     * Die Abschrift lässt sich nicht am Bestand vorbei setzen.
     *
     * `main_domain` steht nicht in `$fillable`. Ein Massenzuweisungs-Aufruf
     * mit diesem Schlüssel geht deshalb ins Leere, statt ein Abonnement mit
     * einer Hauptdomain zu hinterlassen, die es im Bestand nicht gibt.
     */
    public function test_the_copy_cannot_be_filled_directly(): void
    {
        $subscription = Subscription::factory()->create();

        $subscription->fill(['main_domain' => 'geraten.de']);
        $subscription->save();

        $this->assertNull($subscription->refresh()->main_domain);
    }

    /**
     * Die Abschrift wird auch dann nachgezogen, wenn niemand hinsieht.
     *
     * Der Arbeiter der Warteschlange und jedes Konsolenkommando laufen im
     * Grundzustand der Mandantenklammer — dort ist nichts sichtbar. Ohne die
     * ausdrückliche Ausnahme in `projectMainDomain()` träfe die
     * Aktualisierung keine Zeile, und niemand bekäme davon etwas mit.
     */
    public function test_the_copy_is_written_even_without_a_tenant(): void
    {
        $subscription = Subscription::factory()->create();

        app(Tenancy::class)->reset();

        Domain::factory()->main()->for($subscription)->create(['name' => 'ohne-mandant.de']);

        app(Tenancy::class)->allowAll();

        $this->assertSame('ohne-mandant.de', $subscription->refresh()->main_domain);
    }
}
