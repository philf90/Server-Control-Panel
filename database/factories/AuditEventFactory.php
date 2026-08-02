<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditResult;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'action' => 'probe.'.fake()->word(),
            'result' => AuditResult::Success,
            'ip_address' => fake()->ipv4(),
        ];
    }
}
