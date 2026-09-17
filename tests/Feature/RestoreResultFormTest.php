<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Backups\RestoreLifecycle;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Was die Wiederherstellung berichtet, trägt je Paar **eine** Form.
 *
 * ## Der Befund, für den es diesen Wächter gibt
 *
 * Gemessen im Abnahmelauf von P8 (17. September 2026, Befund 6), auf der
 * Vorgangsseite einer gelungenen Wiederherstellung:
 *
 *     "system_user": { "alt": 1141, "neu": "p1142" }
 *
 * Dieselbe Grösse, nebeneinander, in zwei Fassungen — links eine Zahl, rechts
 * ein Name. Das Verzeichnis der Sicherung führt die **Nummer**, weil
 * {@see Lifecycle::claim()} eine vergibt; jede Anzeige dieses Panels führt den
 * **Namen**. Wer die beiden vergleichen will, muss die Umrechnung im Kopf
 * machen.
 *
 * > **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
 * > sondern eine widersprüchliche.** (`docs/91`, Befund 5)
 *
 * ## Gemessen an der Wirkung und nicht am Quelltext
 *
 * Der Wächter ruft `record()` und liest, was im Ergebnis des Vorgangs steht.
 * Ein Ausdruck über den Quelltext sagte, dass dort `Lifecycle::userName()`
 * vorkommt — nicht, dass der Wert dadurch geht.
 *
 * ## Was er nicht kann
 *
 * Er sagt nichts darüber, ob die **Seite** die Paare nebeneinanderstellt; das
 * ist eine Frage an `Operations/Show.vue`, und die rendert `result` als JSON.
 * Gemessen ist der Inhalt und nicht seine Darstellung.
 */
final class RestoreResultFormTest extends TestCase
{
    use RefreshDatabase;

    /** Die Nummer aus dem Verzeichnis wird zum Namen — beide Seiten lesen gleich. */
    public function test_the_old_system_user_is_reported_as_a_name(): void
    {
        $paar = $this->bericht()['system_user'];

        $this->assertSame(
            'p1141',
            $paar['alt'],
            'Die alte Seite trägt die nackte Nummer — dann steht neben „p1142" eine Zahl, und der Leser rechnet um.',
        );

        $this->assertSame('p1142', $paar['neu'], 'Die neue Seite trägt nicht den Namen des Abonnements.');
    }

    /**
     * **Die Regel selbst, über jedes Paar des Berichts.**
     *
     * Ohne sie hielte der Fall darüber genau eine Stelle — und die nächste
     * Zuordnung, die jemand dazuschreibt, dürfte wieder zweierlei tragen.
     *
     * `null` ist auf einer Seite erlaubt und auf beiden: Eine Sicherung, deren
     * Verzeichnis eine Angabe nicht führt, sagt etwas anderes als eine, die sie
     * mit einem Wert führt. Verboten ist nur, dass zwei **vorhandene** Werte
     * verschiedene Typen tragen.
     */
    public function test_every_pair_of_the_report_carries_one_form(): void
    {
        $paare = [];

        foreach ($this->bericht() as $schlüssel => $wert) {
            if (! is_array($wert) || ! array_key_exists('alt', $wert) || ! array_key_exists('neu', $wert)) {
                continue;
            }

            $paare[] = $schlüssel;

            if ($wert['alt'] === null || $wert['neu'] === null) {
                continue;
            }

            $this->assertSame(
                get_debug_type($wert['alt']),
                get_debug_type($wert['neu']),
                sprintf('Das Paar „%s" trägt zwei Formen: alt ist %s, neu ist %s.', $schlüssel, get_debug_type($wert['alt']), get_debug_type($wert['neu'])),
            );
        }

        // **Die Untergrenze, und sie ist hier die halbe Prüfung.** Fände die
        // Schleife kein Paar, wäre dieser Fall grün, ohne etwas angesehen zu
        // haben — und genau das täte er, wenn jemand die Schlüssel umbenennt.
        $this->assertGreaterThanOrEqual(
            2,
            count($paare),
            'Der Bericht trägt weniger als zwei Paare — dann greift dieser Ausdruck ins Leere und meldet es nicht.',
        );
    }

    /**
     * `record()` durch die Tür, mit einem Verzeichnis wie aus einer Sicherung.
     *
     * Über Reflexion und mit gelöster Mandantenklammer — aus demselben Grund
     * wie in {@see RestoreDomainsTest}: `afterSuccess()` löst sie für seinen
     * ganzen Rumpf, und ein Aufruf ohne sie misst eine Bedingung, die es im
     * Betrieb nicht gibt.
     *
     * @return array<string, mixed>
     */
    private function bericht(): array
    {
        $abo = Subscription::factory()->create(['system_user' => Lifecycle::userName(1142)]);

        SystemUser::query()->create([
            'number' => 1142,
            'subscription' => (string) $abo->name,
            'db_prefix' => 'xk7q',
            'claimed_at' => now(),
        ]);

        $vorgang = Operation::factory()->create(['type' => 'backup.restore', 'subscription_id' => $abo->id]);

        $tür = new ReflectionMethod(RestoreLifecycle::class, 'record');
        $tür->setAccessible(true);

        app(Tenancy::class)->withoutRestriction(function () use ($tür, $vorgang, $abo): void {
            $tür->invoke(
                app(RestoreLifecycle::class),
                $vorgang,
                ['subscription' => 'p8-abnahme.invalid', 'system_user' => 1141, 'db_prefix' => 'ab3z'],
                $abo,
                [],
                [],
            );
        });

        $ergebnis = $vorgang->fresh()?->result;

        $this->assertIsArray($ergebnis, 'Der Vorgang trägt kein Ergebnis.');
        $this->assertIsArray($ergebnis['restored'] ?? null, 'Das Ergebnis trägt keinen Bericht.');

        /** @var array<string, mixed> $bericht */
        $bericht = $ergebnis['restored'];

        return $bericht;
    }
}
