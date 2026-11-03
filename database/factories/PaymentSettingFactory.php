<?php

namespace Database\Factories;

use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Models\PaymentSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentSetting>
 */
class PaymentSettingFactory extends Factory
{
    /**
     * @var class-string<PaymentSetting>
     */
    protected $model = PaymentSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement(PaymentProvider::cases());

        return [
            'provider' => $provider,
            'enabled' => false,
            'mode' => PaymentMode::Test,
            'currency' => $provider->defaultCurrency(),
            'min_amount' => null,
            'max_amount' => null,
            'instructions' => null,
            'credentials' => null,
        ];
    }

    /**
     * Pick a specific provider (the table keeps one row per provider).
     *
     * Named `withProvider` because `for()` is already taken by
     * {@see Factory::for()}, which binds a relationship — a state method of
     * that name would not be an override, it would be a signature clash.
     */
    public function withProvider(PaymentProvider $provider): static
    {
        return $this->state(fn (): array => [
            'provider' => $provider,
            'currency' => $provider->defaultCurrency(),
        ]);
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => ['enabled' => true]);
    }

    public function live(): static
    {
        return $this->state(fn (): array => ['mode' => PaymentMode::Live]);
    }
}
