<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CronRunStatus;
use App\Models\CronJob;
use App\Models\CronRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CronRun>
 */
class CronRunFactory extends Factory
{
    protected $model = CronRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $job = CronJob::factory();

        return [
            'cron_job_id' => $job,

            /*
             * **Der Mandant wird mitgeführt und nicht aus dem Job abgeleitet.**
             * Die Spalte gibt es, weil {@see \App\Models\Concerns\BelongsToSubscription}
             * über sie klammert; eine Factory, die sie leer liesse, baute Zeilen,
             * die niemand mehr sieht — und der Test dahinter suchte den Fehler
             * in der Klammer statt in der Factory.
             */
            'subscription_id' => fn (array $attributes): int => (int) CronJob::query()
                ->findOrFail($attributes['cron_job_id'])
                ->subscription_id,

            'started_at' => now(),
            'duration_ms' => 42,
            'exit_code' => 0,
            'status' => CronRunStatus::Ok,
            'output' => "hallo welt\n",
            'truncated' => false,
        ];
    }

    /** Ein Lauf, der fehlgeschlagen ist. */
    public function failed(): self
    {
        return $this->state(fn (): array => [
            'exit_code' => 1,
            'status' => CronRunStatus::Failed,
            'output' => "so nicht\n",
        ]);
    }

    /** Einer, der über die Frist ging — 124 ist die Auskunft von `timeout(1)`. */
    public function timedOut(): self
    {
        return $this->state(fn (): array => [
            'exit_code' => 124,
            'status' => CronRunStatus::Timeout,
            'duration_ms' => 3_600_000,
        ]);
    }

    /**
     * Einer, der gar nicht erst gelaufen ist, weil der vorige noch lief.
     *
     * **`exit_code` ist hier `null` und nicht `0`.** Eine `0` hiesse
     * „erfolgreich beendet" und wäre das Gegenteil dessen, was gemeint ist —
     * dieser Lauf hat nie stattgefunden.
     */
    public function skipped(): self
    {
        return $this->state(fn (): array => [
            'exit_code' => null,
            'status' => CronRunStatus::Skipped,
            'duration_ms' => 0,
            'output' => null,
        ]);
    }

    /** Einer mit gekappter Ausgabe — die Kennzeichnung gehört zum Zustand. */
    public function truncated(): self
    {
        return $this->state(fn (): array => [
            'output' => str_repeat('x', 1024),
            'truncated' => true,
        ]);
    }
}
