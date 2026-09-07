<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CronJob;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CronJob>
 */
class CronJobFactory extends Factory
{
    protected $model = CronJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'label' => 'Nächtliche Aufräumung',

            /*
             * **Ein Befehl, der nichts tut und trotzdem einer ist.** Ein
             * `fake()->sentence()` wäre kein Befehl, und ein `rm` wäre einer,
             * den irgendwann jemand aus einem Testbestand in eine Anleitung
             * kopiert.
             */
            'command' => '/usr/bin/php /var/www/vhosts/beispiel.de/httpdocs/cron.php',

            // Täglich um 03:15 — dieselbe Zeit, die docs/51 §10.3 als Beispiel
            // für die lesbare Übersetzung nennt.
            'minute' => '15',
            'hour' => '3',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',

            'active' => true,

            /*
             * **Eine nächste Fälligkeit steht hier nicht**, und seit dem
             * 7. September 2026 gibt es sie auch als Spalte nicht mehr. Sie ist
             * ein gerechneter Wert und kein Bestandteil des Jobs; gerechnet
             * wird sie beim Lesen, aus dem Zeitplan und der Zeit der Maschine.
             */
        ];
    }

    /** Ein Job, den der Kunde selbst pausiert hat. */
    public function paused(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    /** Jede Minute — der Fall, an dem Überlappung und Kappung hängen. */
    public function everyMinute(): self
    {
        return $this->state(fn (): array => [
            'minute' => '*',
            'hour' => '*',
        ]);
    }
}
