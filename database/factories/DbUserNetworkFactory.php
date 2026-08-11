<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DbUser;
use App\Models\DbUserNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DbUserNetwork>
 */
class DbUserNetworkFactory extends Factory
{
    protected $model = DbUserNetwork::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'db_user_id' => DbUser::factory()->postgres(),

            /*
             * **Aus `TEST-NET-3` und nicht aus `fake()->ipv4()`.** Die Zufalls-
             * adresse träfe irgendwann eine, die jemandem gehört, und ein Test,
             * dessen Erwartung in einem Log auftaucht, ist eine Adresse zu
             * viel. `203.0.113.0/24` ist für Dokumentation reserviert (RFC 5737)
             * — dieselbe Wahl wie in `docs/38 §14.1`.
             */
            'cidr' => '203.0.113.'.fake()->unique()->numberBetween(1, 254).'/32',
        ];
    }

    /** Ein ganzes Netz statt eines Rechners. */
    public function network(): self
    {
        return $this->state(fn (): array => ['cidr' => '198.51.100.0/24']);
    }
}
