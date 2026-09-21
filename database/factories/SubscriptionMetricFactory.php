<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DailyMetric;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionMetric>
 */
class SubscriptionMetricFactory extends Factory
{
    protected $model = SubscriptionMetric::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),

            /*
             * **Ein fester Tag und kein `now()`.** Zwei Zeilen aus derselben
             * Factory müssen sich am Tag unterscheiden lassen, und ein Lauf um
             * Mitternacht bekäme sonst zwei verschiedene — der eindeutige
             * Schlüssel dieser Tabelle hängt am Tag.
             */
            'day' => '2026-09-20',

            'metric' => DailyMetric::Requests,
            'value' => 42,
        ];
    }
}
