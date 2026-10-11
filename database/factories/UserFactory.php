<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            'dui' => static::duiValido(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'estado' => User::ESTADO_ACTIVO,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function pendiente(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'estado' => User::ESTADO_PENDIENTE,
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => User::ESTADO_INACTIVO,
        ]);
    }

    public function conRol(string $rol): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([$rol]));
    }

    /**
     * DUI único con dígito verificador correcto (mismo algoritmo que App\Rules\Dui).
     */
    public static function duiValido(): string
    {
        $digitos = (string) fake()->unique()->numberBetween(10000000, 99999999);

        $suma = 0;
        for ($i = 0; $i < 8; $i++) {
            $suma += (int) $digitos[$i] * (9 - $i);
        }

        return $digitos.'-'.((10 - ($suma % 10)) % 10);
    }
}
