<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OperationStatus;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operation>
 */
class OperationFactory extends Factory
{
    protected $model = Operation::class;

    public function definition(): array
    {
        return [
            'subscription_id' => null,
            'account_id' => null,
            'type' => 'probe.'.fake()->word(),
            'status' => OperationStatus::Queued,
            'progress' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => OperationStatus::Running,
            'progress' => 42,
        ]);
    }
}
