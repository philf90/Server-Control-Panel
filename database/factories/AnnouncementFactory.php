<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'category' => AnnouncementCategory::Info,

            /*
             * **Ein Satz, den ein Betreiber wirklich schriebe** — kein
             * `fake()->sentence()`. Der Bestand eines Tests wird gelesen, wenn
             * etwas schiefgeht, und dann sagt eine erfundene Wortfolge nichts
             * darüber, ob die Anzeige stimmt.
             */
            'body' => 'Wartungsfenster heute 22:00–23:00 Uhr.',

            // Ohne Fenster: sichtbar, bis jemand sie löscht.
            'visible_from' => null,
            'visible_until' => null,

            'audiences' => AnnouncementAudience::values(),
        ];
    }

    public function warning(): static
    {
        return $this->state(fn (): array => ['category' => AnnouncementCategory::Warning]);
    }

    public function incident(): static
    {
        return $this->state(fn (): array => [
            'category' => AnnouncementCategory::Incident,
            'body' => 'Der Mailversand ist gestört. Wir arbeiten daran.',
        ]);
    }

    /**
     * Nur für dieses Publikum sichtbar.
     *
     * @param  list<AnnouncementAudience>  $audiences
     */
    public function forAudiences(array $audiences): static
    {
        return $this->state(fn (): array => [
            'audiences' => array_map(static fn (AnnouncementAudience $a): string => $a->value, $audiences),
        ]);
    }
}
