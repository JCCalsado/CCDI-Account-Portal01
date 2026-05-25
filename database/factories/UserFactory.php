<?php

namespace Database\Factories;

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
        $firstName     = fake()->firstName();
        $lastName      = fake()->lastName();
        $middleInitial = strtoupper(fake()->randomLetter());

        return [
            'last_name'         => $lastName,
            'first_name'        => $firstName,
            'middle_initial'    => $middleInitial,
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'remember_token'    => Str::random(10),
            'is_active'         => true,
            'role'              => 'student',
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

    /**
     * Create a user with student-specific fields.
     *
     * NOTE: Uses the decomposed address schema introduced in migration
     * 2026_05_11_000129_normalise_address_columns_on_users_table.php.
     * The old single `address` column no longer exists.
     */
    public function student(): static
    {
        return $this->state(function (array $attributes) {
            $year = date('Y');

            return array_merge($attributes, [
                'role'       => 'student',
                'account_id' => $year . '-' . str_pad(
                    fake()->unique()->numberBetween(1, 9999),
                    4,
                    '0',
                    STR_PAD_LEFT
                ),
                'course' => fake()->randomElement([
                    'BS Computer Science',
                    'BS Information Technology',
                    'BS Electrical Engineering Technology',
                ]),
                'year_level'                => fake()->randomElement(['1st Year', '2nd Year', '3rd Year', '4th Year']),
                'birthday'                  => fake()->dateTimeBetween('-25 years', '-18 years'),
                'phone'                     => fake()->numerify('09#########'),
                'status'                    => 'active',
                // Decomposed address — mirrors the schema after 2026_05_11 migration.
                'address_house_lot_unit'    => null,
                'address_street_name'       => fake()->streetName(),
                'address_barangay'          => 'Barangay ' . fake()->numberBetween(1, 150),
                'address_municipality_city' => fake()->city(),
                'address_province'          => fake()->randomElement([
                    'Sorsogon',
                    'Albay',
                    'Camarines Sur',
                    'Camarines Norte',
                    'Catanduanes',
                    'Masbate',
                ]),
            ]);
        });
    }
}