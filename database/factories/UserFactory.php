<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    public function conRol(string $rol): static
    {
        return $this->state(fn (array $attributes) => [
            'rol_id' => \App\Models\Rol::firstOrCreate(['nombre' => $rol])->id,
        ]);
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
