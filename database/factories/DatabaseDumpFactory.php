<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DatabaseEngine;
use App\Enums\DumpKind;
use App\Enums\DumpStatus;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseDump>
 */
class DatabaseDumpFactory extends Factory
{
    protected $model = DatabaseDump::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'database_name' => 'p1001_shop',
            'storage_name' => 'p1001-shop-'.fake()->unique()->numberBetween(100000, 999999),

            // Siehe {@see DatabaseFactory::definition()} — der Vorgabewert der
            // Spalte erreicht das Modell im Speicher nicht.
            'engine' => DatabaseEngine::MariaDb,

            'kind' => DumpKind::Export,
            'status' => DumpStatus::Ready,
            'bytes' => 4096,
        ];
    }

    /** Eine Sicherung aus PostgreSQL — siehe {@see DatabaseFactory::postgres()}. */
    public function postgres(): self
    {
        return $this->state(fn (): array => ['engine' => DatabaseEngine::Postgres]);
    }

    /** Siehe {@see DatabaseFactory::forSubscription()} — `for()` gehört der Basisklasse. */
    public function forDatabase(Database $database): self
    {
        return $this->state(fn (): array => [
            'subscription_id' => $database->subscription_id,
            'database_id' => $database->id,
            'database_name' => $database->name,
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => ['status' => DumpStatus::Pending, 'bytes' => null]);
    }

    public function failed(string $reason = 'Zu wenig Platz'): self
    {
        return $this->state(fn (): array => [
            'status' => DumpStatus::Failed,
            'bytes' => null,
            'last_error' => $reason,
        ]);
    }
}
