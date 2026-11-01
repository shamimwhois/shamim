<?php

namespace Database\Factories;

use App\Models\BillingProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingProfile>
 */
class BillingProfileFactory extends Factory
{
    protected $model = BillingProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'legal_name' => trim($this->faker->firstName().' '.$this->faker->lastName()),
            'company' => null,
            'tax_id' => null,
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => null,
            'address_line1' => $this->faker->streetAddress(),
            'address_line2' => null,
            'city' => 'Dhaka',
            'postal_code' => '1205',
            'country' => 'BD',
        ];
    }

    /**
     * Indicate the buyer is invoicing as a company with a tax identifier.
     */
    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'company' => $this->faker->company(),
            'tax_id' => strtoupper($this->faker->bothify('??-#######')).' '.$this->faker->numerify('####'),
            'phone' => $this->faker->numerify('+8801#########'),
        ]);
    }
}
