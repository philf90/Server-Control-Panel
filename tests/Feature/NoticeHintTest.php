<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Support\Notify\NotifyTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Notify\Providers;
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * Wer noch unter einen Eintrag fällt, steht neben der Liste — B1, `docs/129 §7`.
 *
 * ## Warum es diese Hinweise gibt
 *
 * Mattermost und Rocket.Chat nehmen Slacks `{"text": …}` an. Ein eigener
 * Schlüssel für jeden von beiden erzeugte denselben Rumpf ein zweites und ein
 * drittes Mal.
 *
 * > **Zwei Schlüssel, die denselben Rumpf erzeugen, sind ein Schlüssel und ein
 * > Hinweis.**
 *
 * ## Und warum ein Wächter darüber
 *
 * Der Satz steht im Agenten, reist durch den Controller und erscheint auf der
 * Seite — drei Dateien, und jede für sich in Ordnung. Genau dort sitzen die
 * Fehler dieses Repos.
 *
 * > **Ein Wächter über den Quelltext sagt, dass die Teile zusammenpassen,
 * > nicht dass sie zusammen etwas tun.**
 *
 * ## Was er nicht kann
 *
 * Ob Mattermost und Rocket.Chat die Meldung wirklich annehmen, sagt er nicht —
 * dieser Container erreicht keinen von beiden, und die Zusage stammt aus ihrer
 * Dokumentation. `docs/133` führt es als Punkt des Abnahmelaufs.
 *
 * > **Wissen aus zweiter Hand sieht aus wie Wissen.**
 */
final class NoticeHintTest extends TestCase
{
    use RefreshDatabase;

    private const SEITE = 'resources/js/Pages/Settings/Notices.vue';

    /** Ohne Doppel fragte die Seite beim Bauen den echten Agenten. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance(NotifyTarget::class, new ScriptedNotifyTarget);
    }

    /**
     * Kein Hinweis ohne Eintrag.
     *
     * **So entsteht der tote Eintrag wirklich:** Jemand nimmt einen Empfänger
     * aus der Liste, und der Satz daneben bietet ihn weiter an.
     */
    public function test_every_hint_points_at_a_receiver_that_exists(): void
    {
        foreach (array_keys(Providers::HINTS) as $key) {
            self::assertArrayHasKey($key, Providers::LABELS, sprintf(
                'Der Hinweis zu „%s" zeigt auf einen Empfänger, den es nicht gibt.',
                $key,
            ));
        }

        // Ohne diese Zahl wäre der Fall auch für eine leere Liste grün.
        self::assertGreaterThanOrEqual(1, count(Providers::HINTS));
    }

    /**
     * Und jeder Hinweis kommt auf der Seite an — gemessen durch die Tür.
     *
     * Ein Wächter über den Quelltext des Controllers sagte, dass die Zeile
     * dasteht; hier wird gelesen, was die Seite wirklich bekommt.
     */
    public function test_every_hint_reaches_the_page(): void
    {
        $antwort = $this->actingAs(Account::factory()->admin()->create())->get('/settings/notices');

        $antwort->assertOk();

        /** @var array<int, array{value: string, hint: string|null}> $anbieter */
        $anbieter = $antwort->viewData('page')['props']['providers'];

        $gesehen = [];

        foreach ($anbieter as $eintrag) {
            if ($eintrag['hint'] !== null) {
                $gesehen[$eintrag['value']] = $eintrag['hint'];
            }
        }

        self::assertSame(Providers::HINTS, $gesehen,
            'Was der Agent als Hinweis führt, steht auf der Seite — und nichts sonst.');
    }

    /**
     * Gezeigt wird er, **bevor** jemand wählt.
     *
     * **Das ist der ganze Zweck.** Wer „Mattermost" sucht, findet es in der
     * Liste nicht und geht — den Hinweis eines ausgewählten Eintrags sieht er
     * nie. Gemessen am Rumpf der Berechnung: Sie geht über **alle** Empfänger
     * und fragt nicht den gewählten.
     *
     * > **Ein Hinweis, der erst nach der Entscheidung erscheint, hilft dem
     * > nicht, der ihn zum Entscheiden braucht.**
     */
    public function test_the_hints_stand_before_the_choice(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::SEITE);

        $anfang = strpos($quelle, 'const hinweise');

        self::assertIsInt($anfang, 'Die Berechnung heisst nicht mehr `hinweise` — dann prüft dieser Wächter nichts.');

        $ende = strpos($quelle, "\n)", $anfang);

        self::assertIsInt($ende);

        $rumpf = substr($quelle, $anfang, $ende - $anfang);

        self::assertStringContainsString('props.providers', $rumpf);
        self::assertStringNotContainsString('form.provider', $rumpf,
            'Der Hinweis hängt am gewählten Empfänger — dann sieht ihn nur, wer ihn schon gewählt hat.');

        // Und er wird auch gerendert. Ohne diese Zeile wäre die Berechnung
        // richtig und die Seite stumm.
        self::assertStringContainsString('{{ hinweise }}', $quelle);
    }
}
