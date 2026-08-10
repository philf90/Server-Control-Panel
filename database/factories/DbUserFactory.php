<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DatabaseEngine;
use App\Enums\DbUserStatus;
use App\Models\DbUser;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use SrvPanel\Agent\Db\Names;

/**
 * @extends Factory<DbUser>
 */
class DbUserFactory extends Factory
{
    protected $model = DbUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = 'u'.fake()->unique()->numberBetween(1, 999999);

        return [
            'subscription_id' => Subscription::factory(),
            'name' => Names::user('p1001', $label),
            'label' => $label,
            'host' => 'localhost',

            // Siehe {@see DatabaseFactory::definition()} — der Vorgabewert der
            // Spalte erreicht das Modell im Speicher nicht.
            'engine' => DatabaseEngine::MariaDb,

            'status' => DbUserStatus::Active,
        ];
    }

    /**
     * Eine PostgreSQL-Rolle.
     *
     * **`host` bleibt `localhost`, und das ist keine Nachlässigkeit.** In
     * MariaDB gehört der Wirt zum Benutzer; in PostgreSQL ist die Rolle
     * clusterweit eindeutig und die erlaubten Netze stehen in
     * `db_user_networks` (`docs/38 §14.3`). Die Spalte trägt die Zeile nur,
     * weil das Datenmodell eines ist — sie hier auf etwas anderes zu setzen
     * hiesse, ihr eine Bedeutung zu geben, die sie für dieses System nicht hat.
     */
    public function postgres(): self
    {
        return $this->state(fn (): array => ['engine' => DatabaseEngine::Postgres]);
    }

    /** Siehe {@see DatabaseFactory::forSubscription()} — `for()` gehört der Basisklasse. */
    public function forSubscription(Subscription $subscription, string $label = 'web'): self
    {
        return $this->state(fn (): array => [
            'subscription_id' => $subscription->id,
            'name' => Names::user((string) $subscription->system_user, $label),
            'label' => $label,
        ]);
    }

    public function locked(): self
    {
        return $this->state(fn (): array => [
            'status' => DbUserStatus::Locked,
            'locked_at' => now(),
        ]);
    }

    /** Ein Zugang von aussen (docs/36 §12) — zwei Wirte sind zwei Benutzer. */
    public function from(string $host): self
    {
        return $this->state(fn (): array => ['host' => $host]);
    }
}
