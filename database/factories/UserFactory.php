<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
            'empresa_id' => fn (): int => DB::table('empresas')->insertGetId([
                'razon_social' => fake()->company(),
                'nombre_comercial' => fake()->company(),
                'ruc' => fake()->unique()->numerify('20#########'),
                'pais_codigo' => 'PE',
                'moneda' => 'PEN',
                'zona_horaria' => 'America/Lima',
                'hora_corte_operativo' => '21:00:00',
                'sunat_habilitado' => false,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'nombre' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password_hash' => static::$password ??= Hash::make('password'),
            'estado' => User::STATUS_ACTIVE,
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
}
