<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Wer eine Grenze nicht anwenden kann, hat keine Grenze.
 *
 * **Der Anlass ist ein Knopf, der genau dort fehlte, wofür er gebaut wurde.**
 * `disk_quota_enforced` kam am 10. August 2026 dazu, ohne Backfill — jedes
 * Abonnement von davor steht auf `null`, und `null` heisst „nicht
 * nachgesehen". Die Oberfläche hängte den Knopf „Grenze erneut anwenden" an
 * `=== false`, also an eine **Messung**. Auf `cloudsrv24` gab es die für die
 * beiden Abonnements nie: Sie waren angelegt worden, bevor es die Spalte gab.
 *
 * Das Ergebnis war ein Panel, das eine Speichergrenze zeigte, sie nicht
 * anwandte und keinen Weg anbot, das zu ändern — ausser die Grenze zu
 * *ändern*, weil `SubscriptionController::update()` `subscription.quota` nur
 * bei einem abweichenden Wert einreiht (`docs/41 §3`).
 *
 * > **Ein Knopf, der an einer Messung hängt, fehlt dort, wo nie gemessen
 * > wurde.**
 *
 * Das ist derselbe Fehler wie der Nullfall in `docs/36 §14`, nur andersherum:
 * Dort sah eine fehlende Auskunft aus wie eine Erlaubnis, hier wie ein
 * erledigter Zustand. Der dreiwertige Wert war richtig — falsch war, eine
 * **Handlung** an genau einem seiner drei Werte aufzuhängen.
 *
 * ## Zwei Wächter, weil es zwei Wege gibt, das kaputtzumachen
 *
 * Die Seite kann den Knopf verstecken, und der Controller kann ihn abweisen.
 * Ein Test, der nur eine Seite prüft, bleibt bei genau der Hälfte der Fehler
 * grün — dieselbe Form wie {@see AgentAnswerReachTest}.
 */
final class QuotaActionReachTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function page(): string
    {
        $file = dirname(__DIR__, 2).'/resources/js/Pages/Subscriptions/Show.vue';

        $this->assertFileExists($file);

        $source = (string) file_get_contents($file);

        /*
         * **Ohne Kommentare gelesen, und das ist hier keine Vorsichtsmassnahme,
         * sondern Erfahrung.** Über jeder dieser Zeilen steht ein Absatz, der
         * die alte Bedingung wörtlich zitiert — ein Wächter, der ihn mitliest,
         * prüft die Erklärung statt des Codes. Genau daran sind in derselben
         * Woche vier Wächter gescheitert.
         *
         * Zeilenkommentare bleiben stehen: `//` steht auch in jeder URL, und
         * ein Ausdruck, der sie entfernt, frisst den halben Quelltext
         * ({@see \Tests\Support\WithoutPhpComments}). Für `.vue` gibt es hier
         * keinen Parser, also wird die Regel andersherum gesichert — dieser
         * Baustein trägt keine Zeilenkommentare.
         */
        $withoutComments = preg_replace(['~<!--.*?-->~s', '~/\*.*?\*/~s'], '', $source);

        $this->assertIsString($withoutComments);

        return $withoutComments;
    }

    /**
     * Der Knopf steht bei jedem Zustand ausser „ja".
     *
     * Geprüft wird die **Bedingung** und nicht ihre Wirkung: Ob ein `v-if`
     * greift, beantwortet nur ein Browser, und der läuft hier nicht. Was sich
     * prüfen lässt, ist, woran der Knopf hängt — und der Fehler war genau das.
     */
    public function test_the_button_is_offered_in_every_state_but_yes(): void
    {
        $code = $this->page();

        $this->assertStringContainsString(
            'quotaActionable && props.can.update',
            $code,
            'Der Knopf hängt nicht mehr an der zusammengesetzten Bedingung.',
        );

        $this->assertStringNotContainsString(
            'usage.enforced === false && props.can.update',
            $code,
            'Der Knopf hängt wieder allein am gemessenen Fehlschlag — und fehlt damit jedem '
            .'Abonnement, über dessen Grenze nie etwas gemessen wurde.',
        );

        $this->assertStringContainsString(
            'props.usage.enforced === null && props.usage.limit_mb !== null',
            $code,
            'Der Fall „nicht nachgesehen" wird nicht mehr gedeckt. `null` ist kein `false`, '
            .'und ein Abonnement aus der Zeit vor der Spalte hat keinen anderen Weg zu seiner '
            .'Grenze.',
        );

        $this->assertStringContainsString(
            'quotaBroken.value || quotaUnknown.value',
            $code,
            'Beide Zustände führten zum selben Knopf — jetzt nicht mehr.',
        );

        // Und der zweite Satz dazu: „erneut" wäre für einen Zustand, der nie
        // gemessen wurde, eine Behauptung über eine Vergangenheit.
        $this->assertStringContainsString('Grenze anwenden', $code);
        $this->assertStringContainsString('Grenze erneut anwenden', $code);
    }

    /**
     * Und der Weg dahinter ist offen — auch ohne Auskunft.
     *
     * **Die andere Hälfte.** Ein sichtbarer Knopf, den die Route abweist, ist
     * die Falle aus {@see AbilityReachTest}, nur in der anderen Richtung.
     */
    public function test_a_subscription_without_an_answer_can_apply_its_limit(): void
    {
        Queue::fake();

        $subscription = $this->tenancy()->withoutRestriction(
            fn (): Subscription => Subscription::factory()->create([
                'name' => 'ohne-auskunft.de',
                'system_user' => 'p1099',
                'disk_quota_enforced' => null,
            ]),
        );

        $this->actingAs($this->admin())
            ->post("/subscriptions/{$subscription->id}/quota")
            ->assertSessionHasNoErrors();

        $operation = $this->tenancy()->withoutRestriction(
            fn (): ?Operation => Operation::query()->where('task', 'subscription.quota')->latest('id')->first(),
        );

        $this->assertNotNull(
            $operation,
            'Es wurde kein subscription.quota eingereiht. Ohne diesen Vorgang bleibt die Grenze '
            .'eine Zahl im Panel.',
        );

        $this->assertSame($subscription->id, $operation->subscription_id);
    }
}
