<?php

use App\Enums\RolePlan;
use App\Support\Seo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Seller dashboard home.
 *
 * Answers the two questions a seller opens this page with:
 *   1. What can I sell, and on what terms? (plan entitlements)
 *   2. What products exist? (listing state)
 *
 * The product pipeline described in `docs/Developer Marketplace & Dig.md` §10
 * is not implemented yet, so the listing area states that plainly rather than
 * rendering an empty table that looks broken. Everything shown here is read
 * from real configuration — the plan limits come from {@see RolePlan}, not from
 * hardcoded copy.
 */
new #[Layout('layouts::seller.dashboard')] #[Title('Seller dashboard')] class extends Component
{
    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Seller dashboard',
            'description' => 'Manage your products and sales.',
            'noindex' => true,
        ]);
    }

    /**
     * The plan attached to the signed-in account, for the entitlement panel.
     */
    public function plan(): RolePlan
    {
        return auth()->user()->plan();
    }

    /**
     * The plan entitlements, as label/value rows.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function entitlements(): array
    {
        $plan = $this->plan();
        $limit = $plan->productListingLimit();
        $commission = $plan->commissionPercent();

        return [
            [
                'label' => __('Product listings'),
                'value' => $limit === null ? __('Unlimited') : (string) $limit,
            ],
            [
                'label' => __('Platform commission'),
                'value' => $commission === null
                    ? __('Not applicable')
                    : number_format($commission, 1).'%',
            ],
            [
                'label' => __('Publishing'),
                'value' => $plan->canSell() ? __('Without review') : __('Not permitted'),
            ],
        ];
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Seller"
        :title="'Welcome back, '.Str::of(auth()->user()->name)->explode(' ')->first()"
        description="Your plan, your listings, and the path from draft to published."
        :breadcrumbs="[['label' => __('Selling')]]"
    >
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" disabled>
                New product
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        {{-- ============================================================= --
         | Plan and entitlements
         --}}
        <section aria-labelledby="plan-heading">
            <h2 id="plan-heading" class="sr-only">Your plan</h2>

            <div class="grid gap-6 xl:grid-cols-3">
                <div class="sui-card p-6">
                    <div class="flex items-center justify-between gap-3">
                        <p class="sui-label">
                            {{ __('Plan') }}
                        </p>
                        <x-app.ui.role-badge size="md" />
                    </div>

                    <p class="mt-4 text-2xl font-semibold tracking-tightest text-ink-900">
                        {{ $this->plan()->label() }}
                    </p>

                    <p class="mt-2 text-sm leading-normal text-ink-500">
                        {{ $this->plan()->tagline() }}
                    </p>
                </div>

                <div class="sui-card xl:col-span-2">
                    <div class="border-b border-sui-hairline px-5 py-4">
                        <h3 class="text-sm font-semibold tracking-tight text-ink-900">
                            {{ __('Entitlements') }}
                        </h3>
                    </div>

                    <dl class="divide-y divide-sui-hairline">
                        @foreach ($this->entitlements() as $entitlement)
                            <div class="flex items-center justify-between gap-4 px-5 py-3">
                                <dt class="text-sm text-ink-600">{{ $entitlement['label'] }}</dt>
                                <dd class="text-sm font-semibold tabular-nums text-ink-900">
                                    {{ $entitlement['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </section>

        {{-- ============================================================= --
         | Plan features
         --}}
        <x-app.ui.section :padded="false">
            <div class="border-b border-sui-hairline px-5 py-4">
                <h2 id="features-heading" class="text-sm font-semibold tracking-tight text-ink-900">
                    {{ __('What your plan includes') }}
                </h2>
            </div>

            <ul class="grid gap-x-8 gap-y-3 px-5 py-5 sm:grid-cols-2">
                @foreach ($this->plan()->features() as $feature)
                    <li class="flex items-start gap-2.5 text-sm text-ink-600">
                        <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-sui-success" />
                        <span>{{ $feature }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- ============================================================= --
         | Listings — honest empty state
         --}}
        {{-- The dashed boundary and the inset fill together say "reserved, not
             yet built" — a raised card here would imply a feature that exists. --}}
        <section
            aria-labelledby="listings-heading"
            class="sui-empty rounded-xl border border-dashed border-sui-border bg-sui-well"
        >
            <div class="mx-auto grid size-11 place-items-center rounded-md border border-sui-hairline bg-sui-surface text-ink-400">
                <flux:icon name="cube" class="size-5" />
            </div>

            <h2 id="listings-heading" class="mt-4 text-sm font-semibold tracking-tight text-ink-900">
                {{ __('Product listings are not available yet') }}
            </h2>

            <p class="mx-auto mt-2 max-w-md text-sm leading-normal text-ink-500">
                {{ __('Your plan supports :n product listings. The product pipeline — drafts, review, versions and pricing — is the next phase of work, so there is nothing to manage here yet.', ['n' => $this->plan()->productListingLimit() === null ? __('unlimited') : $this->plan()->productListingLimit()]) }}
            </p>
        </section>
</x-app.ui.body>
</div>
