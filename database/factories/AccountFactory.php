<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'type' => AccountType::Admin,
            'customer_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('probe-passwort-nur-fuer-tests'),
            'locale' => 'de',
            'status' => AccountStatus::Active,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::Admin,
            'customer_id' => null,
        ]);
    }

    public function customer(?Customer $customer = null): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::Customer,
            'customer_id' => $customer?->id ?? Customer::factory(),
        ]);
    }

    public function additional(?Customer $customer = null): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::Additional,
            'customer_id' => $customer?->id ?? Customer::factory(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => AccountStatus::Disabled]);
    }
}
