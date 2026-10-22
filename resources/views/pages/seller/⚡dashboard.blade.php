<?php

use App\Enums\AccountPermission;
use App\Enums\ProductStatus;
use App\Enums\RolePlan;
use App\Models\MarketplaceProduct;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Seller dashboard home.
 *
 * Answers the two questions a seller opens this page with:
 *   1. What can I sell, and on what terms? (plan entitlements)
 *   2. Where is my own work up to? (listing state)
 *
 * The listing pipeline lives on `seller.products` and its editor; this screen
 * counts the account's listings and points at them, and both the hero action and
 * the counts come from the same query the console uses. Everything shown here is
 * read from real configuration — the plan limits come from {@see RolePlan}, not
 * from hardcoded copy.
 *
 * `boot()` re-states the route's `permission:product:create` because a Livewire
 * request does not pass through that middleware. Nothing on this page reaches
 * across accounts, so the guard is about the surface rather than the data: an
 * account that cannot sell should not be shown a seller's dashboard, and
 * `entitlements()` is a public method the endpoint can call.
 */
new #[Layout('layouts::seller.dashboard')] #[Title('Seller dashboard')] class extends Component
{
    public function boot(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::CreateProduct) ?? false,
            403
        );
    }

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

    /**
     * The account's own listings, counted per status, plus the quota behind them.
     *
     * `used` counts what occupies a slot — submitted or live — because a draft
     * takes no room and an archived listing has already been given back. That is
     * the same definition {@see MarketplaceProduct::scopeOccupyingQuota()} gives
     * {@see \App\Actions\Marketplace\SubmitProduct()}, so the dashboard never
     * offers room the action would refuse to use.
     *
     * @return array{draft: int, pending: int, published: int, archived: int, limit: int|null, used: int}
     */
    #[Computed]
    public function listings(): array
    {
        $counts = $this->ownListings()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $read = fn (ProductStatus $status): int => (int) $counts->get($status->value, 0);

        return [
            'draft' => $read(ProductStatus::Draft),
            'pending' => $read(ProductStatus::PendingReview),
            'published' => $read(ProductStatus::Published),
            'archived' => $read(ProductStatus::Archived),
            'limit' => $this->plan()->productListingLimit(),
            'used' => $this->ownListings()->occupyingQuota()->count(),
        ];
    }

    /**
     * Every listing the signed-in account authored.
     */
    protected function ownListings(): Builder
    {
        return MarketplaceProduct::query()->where('user_id', auth()->id());
    }
}; ?>

<div>
    {{-- The hero, not `x-app.ui.header`: same reasoning as the other two
         dashboards. The action is the create form itself — the pipeline is live
         on `seller.products`, so the hero promises exactly what it delivers. --}}
    <x-app.ui.welcome-card
        :level="1"
        eyebrow="Seller"
        :title="'Welcome back, '.Str::of(auth()->user()->name)->explode(' ')->first()"
        description="Your plan, your listings, and the path from draft to published."
        icon="building-storefront"
    >
        <x-slot:actions>
            <flux:button :href="route('seller.products.create')" wire:navigate variant="primary" icon="plus">
                {{ __('New product') }}
            </flux:button>
            <flux:button :href="route('seller.products')" wire:navigate variant="ghost" icon="cube">
                {{ __('My listings') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.welcome-card>

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

                    <p class="mt-4 text-2xl font-semibold tracking-tightest text-sui-ink">
                        {{ $this->plan()->label() }}
                    </p>

                    <p class="mt-2 text-sm leading-normal text-ink-500">
                        {{ $this->plan()->tagline() }}
                    </p>
                </div>

                <div class="sui-card xl:col-span-2">
                    <div class="border-b border-sui-hairline px-5 py-4">
                        <h3 class="text-sm font-semibold tracking-tight text-sui-ink">
                            {{ __('Entitlements') }}
                        </h3>
                    </div>

                    <dl class="divide-y divide-sui-hairline">
                        @foreach ($this->entitlements() as $entitlement)
                            <div class="flex items-center justify-between gap-4 px-5 py-3">
                                <dt class="text-sm text-ink-600">{{ $entitlement['label'] }}</dt>
                                <dd class="text-sm font-semibold tabular-nums text-sui-ink">
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
                <h2 id="features-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
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
         | Where the listings stand
         --}}
        {{-- The tiles are the same four statuses the console filters by, and each
             one opens that console pre-filtered, so nothing here duplicates a
             screen the seller can already reach. Counts only ever describe the
             account's own listings. --}}
        <section aria-labelledby="listings-heading">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <h2 id="listings-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
                        {{ __('Your listings') }}
                    </h2>

                    <p class="mt-1 text-xs leading-normal text-ink-500">
                        @if ($this->listings['limit'] === null)
                            {{ __('Your plan lists without a cap. Drafts do not take a slot until they are submitted.') }}
                        @else
                            {{ __(':used of :limit listing slots are in use. Drafts do not take a slot until they are submitted.', [
                                'used' => $this->listings['used'],
                                'limit' => $this->listings['limit'],
                            ]) }}
                        @endif
                    </p>
                </div>

                <flux:button size="sm" variant="ghost" :href="route('seller.products')" wire:navigate icon="arrow-right">
                    {{ __('Manage products') }}
                </flux:button>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-app.ui.stat
                    :label="__('Drafts')"
                    :value="$this->listings['draft']"
                    icon="pencil-square"
                    tone="secondary"
                    :hint="__('Private to you until submitted.')"
                    :href="route('seller.products', ['status' => ProductStatus::Draft->value])"
                />
                <x-app.ui.stat
                    :label="__('In review')"
                    :value="$this->listings['pending']"
                    icon="clock"
                    tone="attention"
                    :hint="__('Waiting on an operator.')"
                    :href="route('seller.products', ['status' => ProductStatus::PendingReview->value])"
                />
                <x-app.ui.stat
                    :label="__('Published')"
                    :value="$this->listings['published']"
                    icon="check-circle"
                    tone="success"
                    :hint="__('Live in the catalogue.')"
                    :href="route('seller.products', ['status' => ProductStatus::Published->value])"
                />
                <x-app.ui.stat
                    :label="__('Archived')"
                    :value="$this->listings['archived']"
                    icon="archive-box"
                    tone="info"
                    :hint="__('Withdrawn; the record is kept.')"
                    :href="route('seller.products', ['status' => ProductStatus::Archived->value])"
                />
            </div>
        </section>
</x-app.ui.body>
</div>
