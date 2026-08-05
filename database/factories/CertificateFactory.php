<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Concerns\BelongsToSubscription;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->domainName();

        return [
            'subscription_id' => Subscription::factory(),
            'names' => [$name],
            'status' => CertificateStatus::Active,
            'source' => CertificateSource::Acme,
            'issuer' => 'Test CA',
            'not_before' => now()->subDay(),
            'not_after' => now()->addDays(90),
        ];
    }

    /**
     * Für bestimmte Namen — der übliche Fall im Test.
     *
     * @param  list<string>  $names
     */
    public function covering(array $names): self
    {
        return $this->state(fn (): array => ['names' => $names]);
    }

    /**
     * Das Zertifikat der Oberfläche.
     *
     * **`subscription_id` steht ausdrücklich auf null.** Ohne diese Zeile trüge
     * {@see BelongsToSubscription} den gerade aktiven
     * Mandanten ein, sobald es genau einen gibt — und aus dem Zertifikat des
     * Betreibers würde eines, das einem Kunden gehört.
     */
    public function forPanel(): self
    {
        return $this->state(fn (): array => [
            'subscription_id' => null,
            'source' => CertificateSource::SelfSigned,
            'issuer' => null,
        ]);
    }
}
