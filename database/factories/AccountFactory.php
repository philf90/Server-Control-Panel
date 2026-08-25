<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Auth\RecoveryCodes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use SrvPanel\Agent\Totp;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => AccountType::Admin,

            // Betreiber, weil der Typ oben Admin ist und ein Adminkonto ohne
            // Rolle seit A9 Schritt 2 keine Fähigkeit erfüllt. Ein Testkonto
            // ohne Rolle wäre kein Adminkonto, sondern ein wirkungsloses.
            'role' => AdminRole::Operator,
            'customer_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('probe-passwort-nur-fuer-tests'),
            'locale' => 'de',
            'status' => AccountStatus::Active,
        ];
    }

    /**
     * Ein Adminkonto, wie es im Betrieb aussieht: mit zweitem Faktor.
     *
     * §6.4 macht ihn für Betreiber verpflichtend, und die Middleware setzt das
     * durch — ein Admin ohne zweiten Faktor kommt nicht über die
     * Einrichtungsseite hinaus. Ein Testkonto ohne ihn wäre deshalb kein
     * Adminkonto, sondern ein halb eingerichtetes.
     */
    public function admin(): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::Admin,
            'role' => AdminRole::Operator,
            'customer_id' => null,
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_recovery_codes' => RecoveryCodes::hashAll(RecoveryCodes::generate()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Ein Adminkonto mit der schwächeren Rolle.
     *
     * **Für die Tests, die die Trennung messen.** Ohne diesen Zustand müsste
     * jeder davon `'role' => AdminRole::Administrator` selbst hinschreiben —
     * und die Zeile, die man in jedem Test wiederholt, ist die, die in einem
     * davon fehlt.
     */
    public function administrator(): static
    {
        return $this->admin()->state(fn (): array => [
            'role' => AdminRole::Administrator,
        ]);
    }

    /** Für die Tests, die genau diesen Zustand prüfen. */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
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
