<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    protected $model = Backup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'subscription_name' => 'shop',
            'storage_name' => 'shop-'.fake()->unique()->numberBetween(100000, 999999),

            // **Der Vorgabewert der Spalte erreicht das Modell im Speicher
            // nicht** — siehe `FactoryDefaultTest`: Ein `default` in der
            // Migration gilt beim INSERT, und was die Anwendung beim Anlegen
            // mitschreibt, schreibt die Factory mit.
            'status' => BackupStatus::Ready,

            'bytes' => 1048576,
            'files' => 120,
            'entries' => 137,
            'databases' => 1,

            // Der Zustand des Abonnements zur Zeit der Sicherung. Nach Form A
            // bekommt eine Wiederherstellung beide neu, und dann ist das hier
            // die Auskunft, aus der die Zuordnung alt → neu entsteht.
            'system_user' => 1001,
            'db_prefix' => 'xk3f9a',
        ];
    }

    /** Eine Sicherung, die noch läuft — ohne Zahlen, weil sie noch keine hat. */
    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status' => BackupStatus::Pending,
            'bytes' => null,
            'files' => null,
            'entries' => null,
            'databases' => null,
        ]);
    }

    /** Und eine gescheiterte — die Zeile bleibt, damit der Versuch sichtbar ist. */
    public function failed(string $reason = 'Zu wenig Platz für die Sicherung'): self
    {
        return $this->state(fn (): array => [
            'status' => BackupStatus::Failed,
            'bytes' => null,
            'files' => null,
            'entries' => null,
            'databases' => null,
            'last_error' => $reason,
        ]);
    }

    /** Siehe {@see DatabaseFactory::forSubscription()} — `for()` gehört der Basisklasse. */
    public function forSubscription(Subscription $subscription): self
    {
        return $this->state(fn (): array => [
            'subscription_id' => $subscription->id,
            'subscription_name' => (string) $subscription->name,
        ]);
    }
}
