<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use SrvPanel\Agent\Client;
use Tests\TestCase;
use Tests\Unit\SourceKeyFilterTest;

/**
 * Der Administrator sieht die Updates-Seite und dreht nicht an ihr.
 *
 * ## Warum diese Seite einen eigenen Wächter braucht
 *
 * `RoleGateTest` misst Seiten, die dem Administrator **ganz** verschlossen
 * sind — dort ist die Antwort ein 403, und ein Test hat leichtes Spiel. Die
 * Updates-Seite ist der erste Fall der anderen Art: **dieselbe Seite, zwei
 * Leser** (`docs/81 §3` Frage 2). Sie öffnet sich über `inspect-server`,
 * gedreht wird über `operate-server`, und der Unterschied steht nicht im
 * Statuscode, sondern im Payload.
 *
 * **Und nichts hielt ihn.** `AbilityReachTest` prüft die Ablage `can`, die
 * eine Seite über ihr eigenes Objekt schickt; diese Seite fragt die geteilte
 * `abilities`. Beim Bau am 27. August 2026 war der Zustand richtig und von
 * keinem Wächter erreicht.
 *
 * > **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
 * > stimmt, nur durch Glück getrennt.**
 *
 * ## Was hier nicht gemessen wird, und warum
 *
 * Die Schlüsselspalte hängt an `sources`, und `sources` kommt vom Agenten.
 * {@see Client} ist `final`, in der CI läuft kein Agent, und
 * die Seite trägt den Ausfall zu Recht: `sources` ist dann `null`, und ein
 * `assertNull` darauf beliebe grün, ganz gleich, ob der Filter läuft.
 *
 * > **Eine Behauptung, die auch dann hält, wenn der Gegenstand fehlt, misst
 * > nicht ihn, sondern sein Fehlen.**
 *
 * Der Filter selbst steht deshalb als Rechnung in
 * {@see SourceKeyFilterTest} — dort mit einem Prüfkörper, den
 * dieser Test nicht herstellen kann. Hier wird gemessen, was ohne Agenten
 * echt ist: der Anteil für den Neustart und die Türen selbst.
 */
final class InspectOnlyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Griffe der Seite, die dem Betreiber gehören.
     *
     * `/server/reboot` steht dabei, obwohl es nicht unter `/updates` liegt:
     * Der Knopf sitzt auf dieser Seite, und die Grenze gilt dem Knopf und
     * nicht dem Pfad.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const OPERATOR_ACTIONS = [
        ['put', '/updates/sources'],
        ['post', '/updates/install'],
        ['put', '/updates/unattended'],
        ['post', '/server/reboot'],
    ];

    public function test_an_administrator_reaches_the_page(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->get('/updates')
            ->assertOk();
    }

    /**
     * Der Anteil für den Neustart fehlt ihm — und dem Betreiber nicht.
     *
     * **Beide Richtungen, und das ist hier keine Höflichkeit.** Ein Payload,
     * der `reboot` überhaupt nie schickte, bestünde die erste Hälfte genauso.
     */
    public function test_the_reboot_prompt_is_the_operators_alone(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->get('/updates')
            ->assertInertia(fn (AssertableInertia $seite) => $seite->where('reboot', null));

        $this->actingAs(Account::factory()->admin()->create())
            ->get('/updates')
            ->assertInertia(fn (AssertableInertia $seite) => $seite->has('reboot.hostname'));
    }

    /**
     * Die geteilte Ablage sagt ihm, was er darf — und die Seite liest sie.
     *
     * Ohne diese Behauptung hinge die ganze Anzeige an einem Schlüssel, dessen
     * Namen niemand prüft: Ein `abilties` im Payload oder ein `operate_server`
     * in der Vorlage wäre wortlos immer `false`, und die Seite sähe für den
     * Betreiber aus wie für den Administrator.
     */
    public function test_the_shared_bag_names_the_ability_the_page_reads(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->get('/updates')
            ->assertInertia(fn (AssertableInertia $seite) => $seite
                ->where('abilities.operate-server', false)
                ->where('abilities.inspect-server', true));

        $this->actingAs(Account::factory()->admin()->create())
            ->get('/updates')
            ->assertInertia(fn (AssertableInertia $seite) => $seite
                ->where('abilities.operate-server', true)
                ->where('abilities.inspect-server', true));

        $this->assertStringContainsString(
            "['operate-server']",
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Updates/Index.vue'),
            'Die Seite liest einen anderen Schlüssel als den, der geschickt wird — '
            .'und ein unbekannter Schlüssel ist wortlos `false`.',
        );
    }

    public function test_an_administrator_is_refused_on_every_action_of_the_page(): void
    {
        $administrator = Account::factory()->administrator()->create();

        foreach (self::OPERATOR_ACTIONS as [$verb, $pfad]) {
            $this->actingAs($administrator)
                ->{$verb}($pfad)
                ->assertForbidden();
        }
    }

    /**
     * Und die Gegenprobe: Den Betreiber weist die Tür nicht ab.
     *
     * **Gemessen wird „nicht 403" und nicht „200".** Diese Griffe brauchen
     * einen Rumpf und einen Agenten; ohne beides antworten sie mit 302 oder
     * 422, und das ist hier richtig. Verlangte der Test einen Erfolg, prüfte
     * er den Agenten und nicht die Tür.
     */
    public function test_an_operator_is_not_refused_on_any_of_them(): void
    {
        $operator = Account::factory()->admin()->create();

        foreach (self::OPERATOR_ACTIONS as [$verb, $pfad]) {
            $antwort = $this->actingAs($operator)->{$verb}($pfad);

            $this->assertNotSame(403, $antwort->getStatusCode(), sprintf(
                'Der Betreiber wird an %s %s abgewiesen — dann trennt diese Tür nicht die Rollen, '
                .'sondern schliesst für alle.',
                strtoupper($verb),
                $pfad,
            ));
        }
    }

    /**
     * Nachsehen darf der Administrator — das ist der Sinn von `inspect-server`.
     *
     * Diese Hälfte bricht still: Wer beim Aufräumen `/updates/refresh` zu den
     * Griffen des Betreibers schiebt, nimmt dem Administrator die einzige
     * Handlung, die er auf dieser Seite hat, und die Seite bleibt sichtbar.
     */
    public function test_an_administrator_may_look_again(): void
    {
        $antwort = $this->actingAs(Account::factory()->administrator()->create())
            ->post('/updates/refresh');

        $this->assertNotSame(403, $antwort->getStatusCode());
    }
}
