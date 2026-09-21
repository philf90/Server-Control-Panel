<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    /**
     * @return array<string, mixed>
     *
     * **Der Hash entsteht hier aus einem echten Klartext** und ist keine
     * erfundene Zeichenkette. Sonst liefe ein Prüfstand gegen eine Zeile, die
     * kein Token der Welt trifft — und ein Fall, der „die Wache lässt die
     * falsche Marke nicht durch" behauptet, wäre grün, ohne je eine richtige
     * gesehen zu haben.
     */
    public function definition(): array
    {
        $plain = ApiToken::PREFIX.bin2hex(random_bytes(ApiToken::RANDOM_BYTES));

        return [
            'account_id' => Account::factory(),
            'name' => 'Abrechnung',
            'token_hash' => ApiToken::hashOf($plain),
            'preview' => substr($plain, 0, ApiToken::PREVIEW_LENGTH),
            'last_used_at' => null,
        ];
    }
}
