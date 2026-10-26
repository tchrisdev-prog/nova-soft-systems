<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@empresa.test',
            'microsoft_oid' => null,
            'microsoft_tenant_id' => null,
            'is_active' => true,
            'last_login_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function linked(?string $tenantId = null): static
    {
        return $this->state(fn () => [
            'microsoft_oid' => (string) Str::uuid(),
            'microsoft_tenant_id' => $tenantId ?? (string) Str::uuid(),
        ]);
    }
}
