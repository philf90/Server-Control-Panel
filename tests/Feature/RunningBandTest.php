<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Http\Middleware\ApplyTenancy;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\RunningBand;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Was der Streifen zeigt — und wen er nicht zeigt (B8, `docs/132 §2`).
 *
 * **Jede Richtung einzeln.** Ein Filter, der zu viel wegwirft, sieht von aussen
 * aus wie einer, der richtig rechnet: Beide geben eine kürzere Liste zurück.
 * Deshalb steht neben jedem „nicht dabei" ein „dabei".
 *
 * > **Ein Filter ohne Gegenprobe ist von einer leeren Antwort nicht zu
 * > unterscheiden.**
 */
final class RunningBandTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $jetzt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jetzt = Carbon::parse('2026-09-23 12:00:00');
    }

    private function konto(): Account
    {
        return Account::factory()->customer(Customer::factory()->create())->create();
    }

    /**
     * Ein Vorgang an einem Abonnement, das der Klammer bekannt ist.
     *
     * **Das Abonnement wird durchgereicht und nicht je Vorgang neu angelegt**,
     * und das ist der Kern eines Falls hier: Läge der Vorgang des Nachbarn an
     * einem fremden Abonnement, filterte ihn schon die Mandantenklammer weg —
     * und der Fall bewiese nicht, dass `account_id` etwas tut.
     *
     * > **Ein Prüfkörper, den zwei Wände halten, sagt über keine der beiden
     * > etwas.**
     *
     * @param  array<string, mixed>  $felder
     */
    private function vorgang(Account $konto, Subscription $abo, array $felder = []): Operation
    {
        return Operation::factory()->create(array_merge([
            'subscription_id' => $abo->id,
            'account_id' => $konto->id,
            'status' => OperationStatus::Running,
        ], $felder));
    }

    private function abo(Account $konto): Subscription
    {
        return Subscription::factory()->create(['customer_id' => $konto->customer_id]);
    }

    /**
     * Die Kennungen, die der Streifen zeigt.
     *
     * **Die Klammer wird vorher gesetzt**, und zwar mit demselben Aufruf, den
     * {@see ApplyTenancy} macht. Ohne ihn steht sie im
     * Grundzustand und verweigert alles — der erste Wurf dieses Wächters
     * bekam fünfmal eine leere Liste, und das sah aus wie ein Befund am
     * Prüfling.
     *
     * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit
     * > einer leeren Liste und nicht mit einem Fehler.**
     *
     * @return list<int>
     */
    private function kennungen(Account $konto): array
    {
        app(Tenancy::class)->forAccount($konto);

        return array_map(
            static fn (array $zeile): int => (int) $zeile['id'],
            app(RunningBand::class)->rows($konto, $this->jetzt),
        );
    }

    public function test_a_running_operation_is_shown(): void
    {
        $konto = $this->konto();
        $vorgang = $this->vorgang($konto, $this->abo($konto));

        self::assertSame([$vorgang->id], $this->kennungen($konto));
    }

    /** Und die des Nachbarn nicht — mit der eigenen daneben, damit die Null etwas bedeutet. */
    public function test_a_foreign_account_is_not_shown(): void
    {
        $konto = $this->konto();
        $fremd = $this->konto();
        $abo = $this->abo($konto);

        $eigener = $this->vorgang($konto, $abo);

        /* **Am eigenen Abonnement**, damit nur `account_id` ihn wegnehmen kann. */
        $this->vorgang($fremd, $abo);

        self::assertSame([$eigener->id], $this->kennungen($konto));
    }

    /**
     * Ein Vorgang ohne Konto erscheint bei niemandem.
     *
     * Die Zertifikatsautomatik und der Cron-Einsammler setzen `account_id` auf
     * `null` — dieselbe Null, die `docs/901` als „System" liest. Damit ist
     * Frage 4 aus `docs/92 §4` beantwortet.
     */
    public function test_an_operation_of_the_system_is_shown_to_nobody(): void
    {
        $konto = $this->konto();
        $abo = $this->abo($konto);

        $eigener = $this->vorgang($konto, $abo);
        $this->vorgang($konto, $abo, ['account_id' => null]);

        self::assertSame([$eigener->id], $this->kennungen($konto));
    }

    /**
     * Ein fertiger Vorgang bleibt eine Weile stehen — und altert dann aus.
     *
     * **Beide Seiten der Frist in einem Fall.** Ohne die zweite Hälfte wäre
     * „bleibt stehen" auch dann grün, wenn er für immer stünde; ohne die erste
     * wäre „altert aus" auch dann grün, wenn er nie erschiene.
     */
    public function test_a_finished_operation_ages_out(): void
    {
        $konto = $this->konto();

        $abo = $this->abo($konto);

        $frisch = $this->vorgang($konto, $abo, [
            'status' => OperationStatus::Succeeded,
            'finished_at' => $this->jetzt->copy()->subSeconds(RunningBand::FRESH_SECONDS - 10),
        ]);

        $alt = $this->vorgang($konto, $abo, [
            'status' => OperationStatus::Succeeded,
            'finished_at' => $this->jetzt->copy()->subSeconds(RunningBand::FRESH_SECONDS + 10),
        ]);

        $gezeigt = $this->kennungen($konto);

        self::assertContains($frisch->id, $gezeigt, 'Ein gerade fertiger Vorgang fehlt — der Ausgang ist dann nicht zu lesen.');
        self::assertNotContains($alt->id, $gezeigt, 'Ein alter Vorgang steht noch da — der Streifen vergisst nicht.');
    }

    /** Mehr als {@see RunningBand::LIMIT} Bänder füllen bei 390 px den Bildschirm. */
    public function test_the_band_is_capped(): void
    {
        $konto = $this->konto();

        $abo = $this->abo($konto);

        for ($i = 0; $i < RunningBand::LIMIT + 3; $i++) {
            $this->vorgang($konto, $abo);
        }

        self::assertCount(RunningBand::LIMIT, $this->kennungen($konto));
    }

    /** Ohne Konto ist die Liste leer — und wirft nicht. */
    public function test_without_an_account_the_list_is_empty(): void
    {
        self::assertSame([], app(RunningBand::class)->rows(null, $this->jetzt));
    }

    /**
     * Und durch die Tür: Die geteilte Eigenschaft kommt auf der Seite an.
     *
     * Ein Wächter über die Klasse sagt, dass sie richtig rechnet. Dass jemand
     * sie ruft, sagt erst die Antwort.
     *
     * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut
     * > wie keine.**
     */
    public function test_the_band_reaches_the_page(): void
    {
        $konto = $this->konto();
        $vorgang = $this->vorgang($konto, $this->abo($konto));

        $antwort = $this->actingAs($konto)->get('/');
        $antwort->assertOk();

        $props = $antwort->viewData('page')['props'];

        self::assertSame(
            [$vorgang->id],
            array_map(static fn (array $zeile): int => (int) $zeile['id'], $props['runningOperations']),
        );
    }
}
