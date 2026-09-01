<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A ventSSO-alany lokális tükre — jelszó és remember-token NINCS (golden rule 1).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Élesben minden sor egy ventSSO-alany tükre — a factory is ilyet gyártson.
            'sso_issuer' => (string) config('oidc.provider_url'),
            'sso_subject' => fake()->unique()->uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
