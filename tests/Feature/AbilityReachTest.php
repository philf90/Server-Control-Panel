<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Ein Knopf, den der Betrachter nicht drücken darf, wird nicht gezeigt.
 *
 * **Der Befund kam vom Betreiber.** In der Sicht eines Kunden stand auf
 * `/subscriptions` der Knopf „Abonnement anlegen" und in jeder Zeile
 * „Bearbeiten". Beides ist einem Kunden verwehrt, beides endete mit einem
 * nackten „403 This action is unauthorized". Die Autorisierung war richtig —
 * die Route trägt `can:create`, und sie hat abgewiesen. Falsch war die
 * Auskunft davor: Ein Knopf ist ein Angebot, und einer, der nur ablehnen kann,
 * ist eine Falle.
 *
 * CLAUDE.md sagt „Autorisierung sitzt an der Aktion, nicht im Menü". Das ist
 * die Regel für das **Durchsetzen** und war nie eine Erlaubnis, jedem alles
 * anzubieten. Die Kehrseite fehlte: Wer eine Aktion zeigt, fragt vorher
 * dieselbe Policy, die sie später abweist.
 *
 * **Die Vorlage stand schon da.** `Subscriptions/Show` gatterte „Domain
 * anlegen" über `mayAddDomain` — richtig gedacht und für genau eine der sechs
 * Aktionen dieser Seite gemacht. Wieder eine Seite, die es richtig macht, und
 * die nächste nicht; wieder kein Werkzeug, das danach fragt.
 *
 * Geprüft wird beides:
 *
 *   1. **Mechanisch:** Jede Fähigkeit, die ein Template unter `can.` abfragt,
 *      wird vom Controller auch geschickt — und jede geschickte wird benutzt.
 *      Eine Fahne, die nie ankommt, ist in Vue `undefined` und damit falsch:
 *      Der Knopf verschwindet dann für **alle**, lautlos.
 *   2. **Am laufenden Panel:** Ein Kunde bekommt für die Aktionen des
 *      Betreibers `false`.
 */
final class AbilityReachTest extends TestCase
{
    use RefreshDatabase;
    use WithoutPhpComments;

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

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    /**
     * Die Schlüssel einer `'can' => [ … ]`-Ablage, egal wie tief sie steht.
     *
     * Auch die je Zeile: `Page::from()` bildet jede Zeile mit einer eigenen
     * Ablage ab, und die Seite fragt sie als `row.can.update`. Für diesen Test
     * ist beides dieselbe Zusage.
     *
     * @return list<string>
     */
    private function abilitiesIn(string $php): array
    {
        $keys = [];

        preg_match_all("/'can'\s*=>\s*\[/", $php, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[0] as $start) {
            $offen = 1;
            $i = (int) $start[1] + strlen((string) $start[0]);
            $von = $i;
            $laenge = strlen($php);

            while ($offen > 0 && $i < $laenge) {
                $offen += match ($php[$i]) {
                    '[' => 1,
                    ']' => -1,
                    default => 0,
                };

                $i++;
            }

            preg_match_all("/'([a-zA-Z][a-zA-Z0-9_]*)'\s*=>/", substr($php, $von, $i - $von - 1), $namen);
            $keys = array_merge($keys, $namen[1]);
        }

        return array_values(array_unique($keys));
    }

    public function test_every_ability_a_page_asks_for_is_sent(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->controllers() as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            preg_match_all(
                "/Inertia::render\(\s*'([^']+)'(.*?)(?=Inertia::render\(|\z)/su",
                $source,
                $renders,
                PREG_SET_ORDER,
            );

            foreach ($renders as $render) {
                $sent = $this->abilitiesIn($render[2]);
                $page = dirname(__DIR__, 2).'/resources/js/Pages/'.$render[1].'.vue';

                if (! is_file($page)) {
                    continue;
                }

                preg_match_all('/\bcan\.([a-zA-Z][a-zA-Z0-9_]*)/', (string) file_get_contents($page), $used);
                $used = array_values(array_unique($used[1]));

                if ($sent === [] && $used === []) {
                    continue;
                }

                $checked++;

                foreach (array_diff($used, $sent) as $missing) {
                    $found[] = sprintf(
                        '%s fragt `can.%s` — %s schickt es nicht',
                        'resources/js/Pages/'.$render[1].'.vue',
                        $missing,
                        $this->relative($path),
                    );
                }

                foreach (array_diff($sent, $used) as $unused) {
                    $found[] = sprintf(
                        '%s schickt `can.%s` an %s — dort fragt es niemand',
                        $this->relative($path),
                        $unused,
                        'resources/js/Pages/'.$render[1].'.vue',
                    );
                }
            }
        }

        $this->assertGreaterThan(1, $checked, 'Es werden kaum Fähigkeiten gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Fähigkeit und Abfrage passen nicht zusammen:\n  %s\n\n".
            "Eine Fahne, die nie ankommt, ist in Vue `undefined` — der Knopf verschwindet dann für\n".
            "**alle**, ohne dass etwas meldet. Eine, die niemand abfragt, ist eine Zusage ins Leere.\n".
            'Beides ist dasselbe Muster: eine Zeichenkette, die auf etwas verweist, das es nicht gibt.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Ein Kunde mit einem Abonnement — der Fall aus der Meldung.
     *
     * @return array{0: Account, 1: Subscription}
     */
    private function customerWithSubscription(): array
    {
        $customer = Customer::factory()->create();
        $plan = Plan::factory()->create();

        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
        ]);

        // Ein Kundenkonto erreicht die Abonnements seines Kunden — dafür
        // braucht es keine Zuweisung, das gilt erst für Zusatzbenutzer.
        $account = Account::factory()->customer($customer)->withoutTwoFactor()->create();

        return [$account, $subscription];
    }

    public function test_a_customer_is_offered_none_of_the_operators_actions(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();

        $this->actingAs($account)
            ->get('/subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/Index')
                ->where('can.create', false)
                ->where('subscriptions.data.0.can.update', false)
                ->etc());

        $this->actingAs($account)
            ->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/Show')
                ->where('can.update', false)
                ->where('can.suspend', false)
                ->where('can.delete', false)

                /*
                 * **Und hier steht bewusst `true`.** Der Brotkrumenpfad führt
                 * auf die Kundenseite, und ein Kunde darf seinen eigenen
                 * Datensatz sehen — `CustomerPolicy::view` lässt ihn für den
                 * eigenen Kunden und dessen Untergeordnete zu. Der erste Anlauf
                 * dieses Tests erwartete `false`, weil „Kunde" nach Verwaltung
                 * klingt; die Policy sagt etwas anderes, und sie hat recht.
                 *
                 * Die Zeile steht trotzdem hier: Ein Zusatzbenutzer ohne
                 * eigenen Kunden bekommt `false`, und dann ist der Name im
                 * Pfad kein Verweis.
                 */
                ->where('can.viewCustomer', true)
                ->etc());
    }

    /**
     * Der kurze Weg zu einer neuen Domain — für den Kunden, nicht für den
     * Betreiber.
     *
     * **Der Befund kam vom Betreiber.** Ein Kunde erreichte „Domain anlegen"
     * nur über Abonnements → Name des Abonnements → einen kleinen Knopf rechts
     * im Bereich „Domains". Die Liste `/domains` gab es für ihn schon —
     * `viewAny` lässt jedes Konto durch, die Mandantenklammer schneidet zu —,
     * nur stand sie nicht im Menü und trug keinen Knopf.
     *
     * `creatable` ist beim Betreiber **leer**, und das ist Absicht: Die
     * Abkürzung führt in ein bestimmtes Abonnement, und er hat davon Hunderte.
     */
    public function test_a_customer_gets_the_short_way_to_a_new_domain(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();

        $this->actingAs($account)
            ->get('/domains')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Domains/Index')
                ->where('creatable.0.id', $subscription->id)
                ->count('creatable', 1)
                ->etc());

        // Und das Menü zeigt den Eintrag erst, wenn es ein aktives Abonnement
        // gibt — sonst führte er auf eine leere Liste ohne Knopf.
        $this->actingAs($account)
            ->get('/subscriptions')
            ->assertInertia(fn ($page) => $page->where('account.has_active_subscription', true)->etc());
    }

    public function test_a_customer_without_an_active_subscription_is_not_sent_to_domains(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();
        $subscription->forceFill(['status' => SubscriptionStatus::Suspended])->save();

        $this->actingAs($account)
            ->get('/subscriptions')
            ->assertInertia(fn ($page) => $page->where('account.has_active_subscription', false)->etc());

        // Die Liste bleibt erreichbar — sie zeigt weiter, was da ist. Nur der
        // Knopf fehlt: In einem gesperrten Abonnement entsteht keine Domain.
        $this->actingAs($account)
            ->get('/domains')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->count('creatable', 0)->etc());
    }

    public function test_the_operator_keeps_the_way_through_the_subscription(): void
    {
        $this->customerWithSubscription();
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/domains')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->count('creatable', 0)
                ->where('account.has_active_subscription', true)
                ->etc());
    }

    /**
     * Und dem Betreiber steht alles offen — sonst prüfte der Test oben nur,
     * dass überall `false` steht.
     */
    public function test_the_operator_is_offered_everything(): void
    {
        [, $subscription] = $this->customerWithSubscription();
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.create', true)
                ->where('subscriptions.data.0.can.update', true)
                ->etc());

        $this->actingAs($admin)
            ->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.update', true)
                ->where('can.suspend', true)
                ->where('can.delete', true)
                ->where('can.viewCustomer', true)
                ->etc());
    }

    /**
     * Die geteilte Ablage sagt jeder Rolle, was sie auf diesem Server darf.
     *
     * **Das ist Kriterium 4 aus `docs/82 §6`, gemessen an der Antwort und nicht
     * am Bild.** Die Navigation kam bis A9 Schritt 5 aus dem Kontotyp; seit
     * Schritt 2 die Fähigkeiten über die Rolle auflöst, sah ein Administrator
     * sieben Menüpunkte, die ihm alle einen 403 gaben.
     *
     * Gemessen wird die Ablage und nicht das Menü: Das Menü liest sie, und ein
     * Test über das Markup prüfte die Schleife statt der Auskunft.
     *
     * > **Wer eine Aktion zeigt, fragt vorher dieselbe Policy, die sie später
     * > abweist.**
     */
    public function test_the_shared_abilities_follow_the_role(): void
    {
        $this->actingAs(Account::factory()->admin()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('abilities.operate-server', true)
                ->where('abilities.manage-settings', true)
                ->etc());

        $this->actingAs(Account::factory()->administrator()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('abilities.operate-server', false)
                ->where('abilities.manage-settings', true)
                ->etc());
    }

    /**
     * Und ein Kunde bekommt die Ablage mit lauter `false`.
     *
     * **Nicht: gar keine.** Eine fehlende Ablage und eine mit lauter `false`
     * müssen für die Oberfläche dasselbe bedeuten — sonst hängt an ihrem
     * Unterschied irgendwann eine Bedingung, und die eine Hälfte davon ist
     * falsch.
     */
    public function test_a_customer_gets_the_bag_with_nothing_in_it(): void
    {
        [$account] = $this->customerWithSubscription();

        $this->actingAs($account)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('abilities.operate-server', false)
                ->where('abilities.manage-settings', false)
                ->etc());
    }
}
