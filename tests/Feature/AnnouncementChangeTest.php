<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnnouncementCategory;
use App\Models\Account;
use App\Models\Announcement;
use App\Models\AuditEvent;
use App\Support\Audit\AuditQuery;
use App\Support\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Eine Ankündigung ändern — der Rückweg und das Protokoll.
 *
 * **Dass es diese Handlung gibt, ist ein Befund des Abnahmelaufs**
 * (`docs/105 §10.4`). Bis zum 6. September 2026 kannte `/announcements` `store`
 * und `destroy` und nichts dazwischen, und `docs/103 §10` — die Liste dessen,
 * was A14 ausdrücklich *nicht* wird — nennt das Bearbeiten nicht.
 *
 * > **Eine Aufzählung dessen, was ein Merkmal nicht wird, ist nur dann eine
 * > Entscheidung, wenn das Fehlende darin steht — sonst ist sie eine Lücke mit
 * > Überschrift.**
 *
 * ## Was hier geprüft wird und was anderswo
 *
 * Die **Tür** steht in {@see AnnouncementPageTest} — sie führt die Griffe
 * dieser Seite als Datenlieferant, und die beiden neuen sind dort eingetragen.
 * Hier stehen die zwei Regeln, die es ohne das Ändern nicht gab: dass ein
 * eingetippter Zeitpunkt in der **Anzeigezone** zurückkommt, und dass eine
 * Änderung im Protokoll als Änderung erscheint.
 */
final class AnnouncementChangeTest extends TestCase
{
    use RefreshDatabase;

    private function betreiber(): Account
    {
        return Account::factory()->admin()->create();
    }

    /**
     * Was der Betreiber eintippt, kommt in der eingestellten Zone zurück.
     *
     * **Gemessen mit einem Versatz und mit einer zweiten Zone daneben.** Eine
     * Prüfzone ohne Versatz liesse eine fehlende Umrechnung wie eine gelungene
     * aussehen — dieselbe Vorsicht wie in {@see MaintenanceRoundTripTest} und
     * aus demselben Anlass:
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     *
     * **Und die Naht ist die, an der `docs/102` bezahlt hat.** Dort legte
     * `Clock::minuteToUtc()` `Y-m-d H:i:s` ab, während der Agent `Y-m-d H:i`
     * verlangte; beide Seiten waren je für sich geprüft, mit einem selbst
     * geschriebenen Wert.
     *
     * > **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst
     * > geschriebenen Wert füttern, prüfen die Naht nicht — sie prüfen zweimal
     * > denselben Prüfkörper.**
     *
     * Deshalb geht dieser Fall durch **beide** Richtungen: hinein über `PATCH`,
     * heraus über das Formular von `GET …/edit`.
     */
    public function test_a_typed_time_comes_back_in_the_display_zone(): void
    {
        foreach (['Europe/Berlin', 'Asia/Kolkata'] as $zone) {
            Clock::store($zone);
            Clock::forget();

            $ankuendigung = Announcement::factory()->create();

            $this->actingAs($this->betreiber())
                ->patch("/announcements/{$ankuendigung->id}", [
                    'category' => AnnouncementCategory::Info->value,
                    'body' => 'Der Speicher wird getauscht.',
                    'visible_from_date' => '2026-09-10',
                    'visible_from_time' => '16:00',
                    'visible_until_date' => '',
                    'visible_until_time' => '',
                    'audiences' => ['operator'],
                ])
                ->assertRedirect('/announcements');

            $werte = $this->actingAs($this->betreiber())
                ->get("/announcements/{$ankuendigung->id}/edit")
                ->viewData('page')['props']['values'];

            self::assertSame('2026-09-10', $werte['visible_from_date'], "Zone {$zone}: das Datum");
            self::assertSame('16:00', $werte['visible_from_time'], "Zone {$zone}: die Uhrzeit");

            // Die Gegenprobe: abgelegt ist es **nicht** dieselbe Zahl. Käme sie
            // roh zurück, wäre der Fall oben auch ohne jede Umrechnung grün.
            self::assertNotSame(
                '2026-09-10 16:00:00',
                $ankuendigung->refresh()->visible_from?->utc()->format('Y-m-d H:i:s'),
                "Zone {$zone}: der abgelegte Wert trägt keinen Versatz — dann misst dieser Fall nichts.",
            );
        }
    }

    /**
     * Eine Änderung steht im Protokoll als Änderung.
     *
     * **Und nicht als Löschung plus Anlage.** Genau das war der Weg, den es
     * ohne diese Handlung gab; wer später fragt, warum eine Ankündigung
     * verschwand, fände eine Löschung.
     */
    public function test_a_change_is_recorded_as_one(): void
    {
        $ankuendigung = Announcement::factory()->create([
            'category' => AnnouncementCategory::Info->value,
            'body' => 'Vorher.',
            'audiences' => ['operator'],
        ]);

        $this->actingAs($this->betreiber())->patch("/announcements/{$ankuendigung->id}", [
            'category' => AnnouncementCategory::Incident->value,
            'body' => 'Nachher.',
            'audiences' => ['operator'],
        ])->assertRedirect('/announcements');

        $eintrag = AuditEvent::query()->where('action', 'announcement.change')->sole();

        self::assertSame($ankuendigung->id, $eintrag->context['id'] ?? null);
        self::assertSame(['category', 'body'], $eintrag->context['changed'] ?? null);

        self::assertSame('info', $eintrag->context['category_before'] ?? null);
        self::assertSame('incident', $eintrag->context['category_after'] ?? null);
        self::assertSame('Vorher.', $eintrag->context['body_before'] ?? null);
        self::assertSame('Nachher.', $eintrag->context['body_after'] ?? null);

        // Weder gelöscht noch angelegt — sonst erzählte das Protokoll etwas
        // anderes, als geschehen ist.
        self::assertSame(0, AuditEvent::query()
            ->whereIn('action', ['announcement.create', 'announcement.remove'])
            ->count());
    }

    /**
     * Der Wortlaut steht zuletzt, die kurzen Tatsachen davor.
     *
     * **Gemessen an der Wirkung und nicht am Quelltext.**
     * {@see AuditQuery::details()} läuft in
     * Einfügereihenfolge und kürzt den fertigen Satz bei
     * {@see AuditQuery::DETAILS_MAX} Zeichen. Zwei Texte von
     * je 500 Zeichen schöben alles andere aus der Zeile, wenn sie vorn stünden
     * — und dann wüsste der Leser nicht einmal mehr, **welche** Ankündigung
     * gemeint war.
     *
     * **Der Prüfkörper ändert den Text und das Fenster, und das ist der Kern
     * des Falls.** Der erste Wurf änderte Text und Kategorie — und blieb grün,
     * als die Vorkehrung entfernt wurde: `fields()` führt `category` ohnehin
     * vor `body`, die natürliche Reihenfolge war also schon die richtige.
     *
     * > **Ein Prüfkörper, der auch ohne die Regel grün ist, misst die
     * > Reihenfolge einer Aufzählung und nicht die Vorkehrung.**
     *
     * `visible_from` steht in `fields()` **hinter** `body`. Ohne das Umsortieren
     * käme der Wortlaut damit zuerst — und genau das soll dieser Fall sehen.
     */
    public function test_the_wording_stands_last(): void
    {
        $ankuendigung = Announcement::factory()->create([
            'category' => AnnouncementCategory::Info->value,
            'body' => str_repeat('a', Announcement::BODY_MAX),
            'visible_from' => null,
            'audiences' => ['operator'],
        ]);

        $this->actingAs($this->betreiber())->patch("/announcements/{$ankuendigung->id}", [
            'category' => AnnouncementCategory::Info->value,
            'body' => str_repeat('b', Announcement::BODY_MAX),
            'visible_from_date' => '2026-09-10',
            'visible_from_time' => '16:00',
            'audiences' => ['operator'],
        ]);

        $schluessel = array_keys(AuditEvent::query()->where('action', 'announcement.change')->sole()->context ?? []);

        self::assertSame(
            ['id', 'changed', 'visible_from_before', 'visible_from_after', 'body_before', 'body_after'],
            $schluessel,
            'Der Wortlaut steht nicht zuletzt — dann drängt er die Kennung aus der gekürzten Zeile.',
        );
    }

    /**
     * Ein Speichern ohne Unterschied wird auch protokolliert.
     *
     * **Mit leerem `changed`.** Ein Vorgang, der stattgefunden hat und im
     * Protokoll fehlt, sieht aus wie einer, den es nicht gab — und der Leser
     * sucht dann nach einer Erklärung für eine Lücke, die keine ist.
     */
    public function test_saving_without_a_difference_is_recorded_too(): void
    {
        $ankuendigung = Announcement::factory()->create([
            'category' => AnnouncementCategory::Info->value,
            'body' => 'Unverändert.',
            'audiences' => ['operator'],
        ]);

        $this->actingAs($this->betreiber())->patch("/announcements/{$ankuendigung->id}", [
            'category' => AnnouncementCategory::Info->value,
            'body' => 'Unverändert.',
            'audiences' => ['operator'],
        ]);

        self::assertSame(
            [],
            AuditEvent::query()->where('action', 'announcement.change')->sole()->context['changed'] ?? null,
        );
    }
}
