<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DatabaseStatus;
use App\Models\Database;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use SrvPanel\Agent\Db\Names;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
{
    protected $model = Database::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = 'db'.fake()->unique()->numberBetween(1, 999999);

        return [
            'subscription_id' => Subscription::factory(),

            // **Der Name entsteht mit der Regel des Agenten und nicht mit
            // einer Zeichenkette.** Eine Factory, die `p1001_shop` von Hand
            // zusammensetzt, prüft im Test etwas anderes als der Server tut —
            // und die Abweichung fiele erst auf, wenn ein echter Name die
            // Prüfung nicht besteht.
            'name' => Names::database('p1001', $label),
            'label' => $label,

            'status' => DatabaseStatus::Active,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }

    /**
     * Zu einem bestimmten Abonnement — mit dessen Präfix, nicht mit p1001.
     *
     * **Nicht `for()`.** Der Name gehört `Factory` und hat dort eine andere
     * Form; eine abgeleitete Methode mit abweichender Signatur bricht beim
     * **Laden** der Klasse und nicht beim Ausführen. `php -l` sieht davon
     * nichts, und in den Tests ist es kein Fehlschlag, sondern ein Abbruch, der
     * alles Folgende verschluckt (CLAUDE.md, „ein Name, der der Basisklasse
     * gehört").
     */
    public function forSubscription(Subscription $subscription, string $label = 'shop'): self
    {
        return $this->state(fn (): array => [
            'subscription_id' => $subscription->id,
            'name' => Names::database((string) $subscription->system_user, $label),
            'label' => $label,
        ]);
    }

    public function provisioning(): self
    {
        return $this->state(fn (): array => ['status' => DatabaseStatus::Provisioning]);
    }

    public function removing(): self
    {
        return $this->state(fn (): array => ['status' => DatabaseStatus::Removing]);
    }
}
