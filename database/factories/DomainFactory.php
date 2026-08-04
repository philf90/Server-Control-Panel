<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\RedirectKind;
use App\Models\Domain;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // `unique()`: Der Name ist serverweit einmalig, und ein zweiter
        // gleicher Name aus der Fabrik wäre ein Test, der an der Datenbank
        // scheitert statt an dem, was er prüfen wollte.
        $name = fake()->unique()->domainName();

        return [
            'subscription_id' => Subscription::factory(),
            'name' => $name,
            'type' => DomainType::Addon,
            'status' => DomainStatus::Active,
            'document_root' => $name,
            'php_version' => '8.4',
        ];
    }

    /**
     * Die Hauptdomain eines Abonnements.
     *
     * Sie liefert aus `httpdocs` aus, nicht aus einem Verzeichnis mit ihrem
     * Namen — {@see Domain::defaultDocumentRoot()} entscheidet das, und die
     * Fabrik fragt dieselbe Stelle statt den Wert abzuschreiben.
     */
    public function main(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => DomainType::Main,
            'document_root' => Domain::defaultDocumentRoot(DomainType::Main, ''),
        ]);
    }

    public function subdomain(Domain $parent): static
    {
        $name = fake()->unique()->word().'.'.$parent->name;

        return $this->state(fn (): array => [
            'subscription_id' => $parent->subscription_id,
            'parent_domain_id' => $parent->id,
            'type' => DomainType::Subdomain,
            'name' => $name,
            'document_root' => $name,
        ]);
    }

    public function alias(Domain $parent): static
    {
        return $this->state(fn (): array => [
            'subscription_id' => $parent->subscription_id,
            'parent_domain_id' => $parent->id,
            'type' => DomainType::Alias,
            'document_root' => null,
            'php_version' => null,
        ]);
    }

    public function redirect(string $target, RedirectKind $kind = RedirectKind::Temporary): static
    {
        return $this->state(fn (): array => [
            'redirect_target' => $target,
            'redirect_kind' => $kind,
            'document_root' => null,
            'php_version' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => DomainStatus::Suspended]);
    }
}
