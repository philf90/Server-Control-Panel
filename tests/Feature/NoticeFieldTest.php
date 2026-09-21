<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Support\Notify\NotifyTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Notify\Providers;
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * Was ein Empfänger ausser Adresse und Geheimnis braucht — B1, `docs/129 §7`.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Telegram ist der erste Empfänger mit einem zweiten Feld: Die Marke des Bots
 * steht in der Adresse, der Chat nicht. Die Angabe wandert durch drei Dateien —
 * `Notify\Providers::FIELDS` nennt sie, der Controller prüft sie, die Seite
 * zeigt sie —, und jede für sich kann in Ordnung sein.
 *
 * > **Ein Feld, das der Agent verlangt und die Seite nicht zeigt, ist ein
 * > Formular, das man nicht abschicken kann.**
 *
 * ## Und die Gegenrichtung ist die, an der es wirklich schiefgeht
 *
 * Ein Feld auf der Seite, das der Agent nicht kennt, wird beim Hinterlegen
 * abgewiesen — und der Betreiber liest eine Ausnahme über eine Angabe, die das
 * Formular selbst verlangt hat.
 *
 * ## Was er nicht kann
 *
 * Ob der Hinweis unter dem Feld erklärt, **woher** man einen Chat bekommt,
 * hängt daran, was ein Betrachter erwartet — das steht als Frage hier und nicht
 * als Zusage.
 */
final class NoticeFieldTest extends TestCase
{
    use RefreshDatabase;

    private const SEITE = 'resources/js/Pages/Settings/Notices.vue';

    /** Die Felder, die das Formular immer trägt — sie stehen in keiner Liste des Agenten. */
    private const EIGENE = ['url', 'secret', 'provider'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance(NotifyTarget::class, new ScriptedNotifyTarget);
    }

    /** Jedes Feld, das der Agent verlangt, steht auf der Seite. */
    public function test_every_field_the_agent_asks_for_stands_on_the_page(): void
    {
        $quelle = $this->seite();
        $fehlend = [];

        foreach (Providers::fieldKeys() as $feld) {
            if (! str_contains($quelle, 'v-model="form.'.$feld.'"')) {
                $fehlend[] = $feld;
            }
        }

        self::assertSame([], $fehlend, sprintf(
            "Diese Felder verlangt der Agent, und %s zeigt sie nicht:\n  %s",
            self::SEITE,
            implode("\n  ", $fehlend),
        ));

        // Ohne diese Zahl wäre der Fall auch für eine leere Liste grün.
        self::assertGreaterThanOrEqual(1, count(Providers::fieldKeys()));
    }

    /**
     * Und jedes Feld auf der Seite ist eines, das der Agent kennt.
     *
     * **So entsteht der tote Eintrag wirklich:** Jemand benennt ein Feld im
     * Agenten um, trägt den neuen Namen auf der Seite nach und lässt den alten
     * stehen — die erste Richtung ist danach wieder grün.
     */
    public function test_every_field_on_the_page_is_one_the_agent_asks_for(): void
    {
        preg_match_all('/v-model="form\.([a-z][a-z0-9_]*)"/', $this->seite(), $treffer);

        $erlaubt = [...self::EIGENE, ...Providers::fieldKeys()];
        $fremd = array_values(array_unique(array_diff($treffer[1], $erlaubt)));

        self::assertSame([], $fremd, sprintf(
            "Diese Felder stehen auf %s und kennt der Agent nicht:\n  %s",
            self::SEITE,
            implode("\n  ", $fremd),
        ));

        self::assertGreaterThanOrEqual(4, count(array_unique($treffer[1])), 'Der Ausdruck greift ins Leere.');
    }

    /**
     * Gezeigt wird ein Feld nur für den Empfänger, der es braucht.
     *
     * **Und die Bedingung kommt aus der Antwort und nicht aus einem Namen im
     * Quelltext.** Ein `v-if="form.provider === 'telegram'"` wäre die zweite
     * Fassung von {@see Providers::FIELDS} — und sie bliebe stehen, wenn dort
     * ein zweiter Empfänger dasselbe Feld bekommt.
     */
    public function test_a_field_is_shown_for_the_receiver_that_needs_it(): void
    {
        $quelle = $this->seite();

        foreach (Providers::fieldKeys() as $feld) {
            self::assertStringContainsString(
                'v-if="felder.includes(\''.$feld.'\')"',
                $quelle,
                'Das Feld '.$feld.' hängt nicht an dem, was der Empfänger verlangt.',
            );
        }

        $anfang = strpos($quelle, 'const felder');

        self::assertIsInt($anfang, 'Die Berechnung heisst nicht mehr `felder` — dann prüft dieser Wächter nichts.');

        $rumpf = substr($quelle, $anfang, (int) strpos($quelle, "\n)", $anfang) - $anfang);

        self::assertStringContainsString('props.providers', $rumpf);
    }

    /** Und die Liste der Felder kommt auf der Seite an — gemessen durch die Tür. */
    public function test_the_fields_reach_the_page(): void
    {
        $antwort = $this->actingAs(Account::factory()->admin()->create())->get('/settings/notices');

        $antwort->assertOk();

        /** @var array<int, array{value: string, fields: list<string>}> $anbieter */
        $anbieter = $antwort->viewData('page')['props']['providers'];

        $gesehen = [];

        foreach ($anbieter as $eintrag) {
            if ($eintrag['fields'] !== []) {
                $gesehen[$eintrag['value']] = $eintrag['fields'];
            }
        }

        self::assertSame(Providers::FIELDS, $gesehen);
    }

    /**
     * Ohne die verlangte Angabe kommt eine Meldung am Feld und keine Ausnahme.
     *
     * **Das ist der Grund für `required_if` im Controller.** Der Agent weist
     * es ohnehin ab — aber als Ausnahme, und die landet als roter Streifen
     * oben statt als Satz am Feld.
     */
    public function test_a_receiver_that_needs_a_field_is_refused_without_it(): void
    {
        $antwort = $this->actingAs(Account::factory()->admin()->create())->put('/settings/notices', [
            'url' => 'https://api.telegram.org/bot123:ABC/sendMessage',
            'provider' => Providers::TELEGRAM,
        ]);

        $antwort->assertSessionHasErrors('chat_id');

        // Und die Gegenrichtung: mit der Angabe geht es durch.
        $ziel = new ScriptedNotifyTarget;
        $this->app?->instance(NotifyTarget::class, $ziel);

        $this->actingAs(Account::factory()->admin()->create())
            ->put('/settings/notices', [
                'url' => 'https://api.telegram.org/bot123:ABC/sendMessage',
                'provider' => Providers::TELEGRAM,
                'chat_id' => '-1001234567890',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/notices');

        self::assertSame(['chat_id' => '-1001234567890'], $ziel->stored[0]['config'] ?? null);
    }

    /**
     * Und ein Empfänger ohne Felder bekommt keines mitgeschickt.
     *
     * **Wer von Telegram auf Slack umstellt, leert das Feld nicht.** Ginge der
     * Chat trotzdem mit, wiese der Agent das ganze Hinterlegen ab — mit einer
     * Meldung über eine Angabe, die das Formular gar nicht mehr zeigt.
     */
    public function test_a_receiver_without_fields_carries_none(): void
    {
        $ziel = new ScriptedNotifyTarget;
        $this->app?->instance(NotifyTarget::class, $ziel);

        $this->actingAs(Account::factory()->admin()->create())
            ->put('/settings/notices', [
                'url' => 'https://hooks.slack.com/services/x',
                'provider' => Providers::SLACK,
                'chat_id' => '-1001234567890',
            ])
            ->assertSessionHasNoErrors();

        self::assertSame([], $ziel->stored[0]['config'] ?? null);
    }

    /**
     * Der Chat steht nicht im Protokoll.
     *
     * **Er gehört zur Adressierung wie die Adresse selbst**, und die steht
     * dort schon nicht — festgehalten wird der Rechnername. Das Protokoll darf
     * jeder Administrator lesen, und die Meldeziele gehören dem Betreiber.
     *
     * > **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
     * > Vorgangsseite.** Hier ist es kein Vorgang, sondern eine
     * > Protokollzeile — dieselbe Frage, ein anderer Ort.
     */
    public function test_the_chat_is_not_written_into_the_log(): void
    {
        $this->actingAs(Account::factory()->admin()->create())
            ->put('/settings/notices', [
                'url' => 'https://api.telegram.org/bot123:ABC/sendMessage',
                'provider' => Providers::TELEGRAM,
                'chat_id' => '-1001234567890',
            ])
            ->assertSessionHasNoErrors();

        $zeilen = AuditEvent::query()->where('action', 'settings.notices.stored')->get();

        self::assertCount(1, $zeilen, 'Ohne Zeile prüft dieser Fall nichts.');

        $inhalt = (string) json_encode($zeilen->first()?->context);

        self::assertStringNotContainsString('-1001234567890', $inhalt);
        self::assertStringNotContainsString('bot123:ABC', $inhalt);

        // Und die Gegenrichtung: Dass ein Chat gesetzt wurde, steht da.
        self::assertStringContainsString('chat_id', $inhalt);
    }

    private function seite(): string
    {
        $quelle = file_get_contents(dirname(__DIR__, 2).'/'.self::SEITE);

        self::assertIsString($quelle, self::SEITE.' gibt es nicht.');

        return $quelle;
    }
}
