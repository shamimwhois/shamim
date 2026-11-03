<?php

use App\Enums\ReviewStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A single marketplace listing.
 *
 * The page is a documentation surface as much as a storefront: a buyer decides
 * on compatibility, licence and release history before price. The tabs are URL
 * state (`?tab=`), so "send me the installation section" is a link rather than
 * an instruction.
 *
 * ## Purchasing
 *
 * A published listing offers checkout in the sidebar price card. Payments are
 * simulated end to end — nothing is charged to a real account — but the order
 * lifecycle is the real one: an order opens Pending and only reaches Paid when
 * the payment attempt is confirmed server-side. The installation guide stays
 * the primary answer to "can I use this"; the buy button only decides
 * "own it".
 *
 * ## Visibility
 *
 * Only published listings are public. A draft is readable by its creator and by
 * accounts that review products — the same rule as
 * {@see MarketplaceProduct::isVisibleTo()}, so the route and the page cannot
 * disagree about who may look.
 */
new #[Title('Marketplace listing')] class extends Component
{
    /** The tabs the page can render, and the only values `?tab=` may take. */
    private const array TABS = ['overview', 'installation', 'versions', 'reviews'];

    public MarketplaceProduct $product;

    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    public int $reviewRating = 5;

    public string $reviewTitle = '';

    public string $reviewBody = '';

    /**
     * Set after a review is submitted, so the page can confirm receipt without
     * implying the review is already public.
     */
    public bool $reviewSubmitted = false;

    public function mount(MarketplaceProduct $product): void
    {
        abort_unless($product->isVisibleTo(auth()->user()), 404);
        abort_unless(in_array($this->tab, self::TABS, true), 404);

        $this->product = $product->load([
            'category',
            'creator',
            'versions',
            'media',
            'approvedReviews.author',
        ])->loadCount('approvedReviews')->loadAvg('approvedReviews', 'rating');

        app(Seo::class)->set([
            'title' => $product->name,
            'description' => Str::limit($product->tagline, 155),
        ]);
    }

    /**
     * Switch tab. Re-checks the whitelist because `wire:click` is client input.
     */
    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, self::TABS, true), 404);

        $this->tab = $tab;
    }

    /**
     * Whether the sidebar may offer checkout for this listing.
     *
     * Unpublished listings have no purchase path at all, and nobody is shown
     * a buy button for their own listing — the same rules
     * {@see \App\Actions\Marketplace\PlaceOrder} enforces server-side.
     */
    #[Computed]
    public function purchasable(): bool
    {
        return $this->product->isPublished()
            && ! $this->product->isOwnedBy(auth()->user());
    }

    /**
     * Submit a review for moderation.
     *
     * A review is never published on submit: it is stored as `Pending` and only
     * starts counting toward the rating once an operator approves it.
     */
    public function submitReview(): void
    {
        $user = auth()->user();

        abort_if($user === null, 403);
        abort_unless($this->product->isPublished(), 404);
        abort_if($this->product->user_id === $user->id, 403);

        $validated = $this->validate([
            'reviewRating' => ['required', 'integer', 'between:1,5'],
            'reviewTitle' => ['nullable', 'string', 'max:120'],
            'reviewBody' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        MarketplaceReview::query()->updateOrCreate(
            [
                'marketplace_product_id' => $this->product->id,
                'user_id' => $user->id,
            ],
            [
                'rating' => $validated['reviewRating'],
                'title' => $validated['reviewTitle'] === '' ? null : $validated['reviewTitle'],
                'body' => $validated['reviewBody'],
                'status' => ReviewStatus::Pending,
            ],
        );

        $this->reset('reviewTitle', 'reviewBody');
        $this->reviewRating = 5;
        $this->reviewSubmitted = true;

        $this->product->load('approvedReviews.author')
            ->loadCount('approvedReviews')
            ->loadAvg('approvedReviews', 'rating');
    }

    /**
     * The current account's review of this product, whatever its status.
     */
    #[Computed]
    public function myReview(): ?MarketplaceReview
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return $this->product->reviews()->where('user_id', $user->id)->first();
    }

    /**
     * Whether the review form should be offered at all.
     */
    #[Computed]
    public function canReview(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $this->product->isPublished()
            && $this->product->user_id !== $user->id
            && $this->myReview === null;
    }

    /**
     * Counts per star rating, for the distribution bars.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function ratingBreakdown(): array
    {
        $counts = $this->product->approvedReviews->countBy('rating');

        $breakdown = [];

        foreach ([5, 4, 3, 2, 1] as $star) {
            $breakdown[$star] = (int) $counts->get($star, 0);
        }

        return $breakdown;
    }

    /**
     * The rendered description.
     *
     * Rendered through the application's single sanitiser, the same one that
     * produces `posts.body_html`, so a listing cannot inject markup the blog
     * would refuse.
     */
    #[Computed]
    public function descriptionHtml(): string
    {
        return app(MarkdownRenderer::class)->render($this->product->description);
    }

    /**
     * Other published listings worth a look.
     *
     * @return Collection<int, MarketplaceProduct>
     */
    #[Computed]
    public function related(): Collection
    {
        return MarketplaceProduct::query()
            ->relatedTo($this->product, 3)
            ->with(['category', 'creator', 'media'])
            ->withAvg('approvedReviews', 'rating')
            ->withCount('approvedReviews')
            ->get();
    }
}; ?>

@php
    $product = $this->product;
    $tabs = [
        'overview' => __('Overview'),
        'installation' => __('Installation'),
        'versions' => __('Releases'),
        'reviews' => __('Reviews'),
    ];
@endphp

<div data-test="marketplace-product" class="v96">
    @assets
        {{-- Scoped type: Manrope carries the reading voice, IBM Plex Mono the
             labels and code. Hoisted to the head by Livewire, loaded only on
             the marketplace surfaces. --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @endassets

    {{-- ================================================================== --
     | Hero
     --}}
    <section class="v96-hero">
        <div class="shell relative py-12 lg:py-14">
            <nav aria-label="{{ __('Breadcrumb') }}" class="mb-8">
                <ol class="v96-mono flex flex-wrap items-center gap-2 text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('marketplace.index') }}" wire:navigate class="focus-ring transition-colors hover:text-(--v96-purple-lift)">
                            {{ __('Marketplace') }}
                        </a>
                    </li>

                    @if ($product->category)
                        <li aria-hidden="true">/</li>
                        <li>
                            <a href="{{ route('marketplace.category', ['category' => $product->category->slug]) }}" wire:navigate class="focus-ring transition-colors hover:text-(--v96-purple-lift)">
                                {{ $product->category->name }}
                            </a>
                        </li>
                    @endif

                    <li aria-hidden="true">/</li>
                    <li><span class="text-ink-600 dark:text-ink-300" aria-current="page">{{ $product->name }}</span></li>
                </ol>
            </nav>

            <div class="flex flex-wrap items-start justify-between gap-8">
                <div class="min-w-0 max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($product->category)
                            <span class="tag-pill border-[rgba(118,36,244,0.35)]! bg-(--v96-soft)! text-(--v96-purple-soft)!">
                                {{ $product->category->name }}
                            </span>
                        @endif

                        <span class="tag-pill">{{ $product->license->label() }}</span>

                        @if ($product->is_featured)
                            <span class="tag-pill">{{ __('Featured') }}</span>
                        @endif

                        @unless ($product->isPublished())
                            <span class="tag-pill border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                {{ $product->status->label() }}
                            </span>
                        @endunless
                    </div>

                    <h1 class="mt-4 text-[clamp(2rem,4.5vw,3rem)] font-extrabold leading-[1.08] tracking-tight text-ink-900 dark:text-ink-50">
                        {{ $product->name }}
                    </h1>

                    <p class="mt-3 text-[15px] leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ $product->tagline }}
                    </p>

                    <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                        <x-app.marketplace.rating :product="$product" size="lg" />

                        <span class="h-4 w-px bg-ink-200 dark:bg-ink-800" aria-hidden="true"></span>

                        <span class="text-xs text-ink-500 dark:text-ink-400">
                            {{ __('By :name', ['name' => $product->creator->name]) }}
                        </span>

                        <span class="text-xs text-ink-500 dark:text-ink-400">
                            {{ __('v:version', ['version' => $product->version]) }}
                        </span>

                        <span class="inline-flex items-center gap-1.5 text-xs text-ink-500 dark:text-ink-400">
                            <flux:icon name="arrow-down-tray" class="size-3.5" />
                            <span class="tabular-nums">{{ number_format($product->download_count) }}</span>
                            {{ __('downloads') }}
                        </span>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    <a
                        href="{{ route('marketplace.index') }}"
                        wire:navigate
                        class="focus-ring v96-btn v96-btn--ghost"
                    >
                        <span aria-hidden="true">&larr;</span>
                        {{ __('All listings') }}
                    </a>

                    @if ($product->demo_url)
                        <a
                            href="{{ $product->demo_url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="focus-ring v96-btn"
                        >
                            {{ __('Live demo') }}
                            <flux:icon name="arrow-up-right" class="size-4" />
                        </a>
                    @else
                        <button
                            type="button"
                            wire:click="showTab('installation')"
                            class="focus-ring v96-btn"
                        >
                            {{ $product->isFree() ? __('Install now') : __('Installation & licence') }}
                            <span aria-hidden="true">&rarr;</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | Tabs
     --}}
    <div class="border-b border-ink-200 dark:border-ink-800">
        <div class="shell">
            <nav aria-label="{{ __('Listing sections') }}" class="-mb-px flex gap-1 overflow-x-auto">
                @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        wire:click="showTab('{{ $key }}')"
                        @class([
                            'focus-ring v96-mono shrink-0 border-b-2 px-4 py-4 text-[13px] font-medium uppercase tracking-[0.08em] transition-colors',
                            'border-(--v96-purple) font-bold text-(--v96-ink) dark:border-(--v96-purple-lift)' => $tab === $key,
                            'border-transparent text-(--v96-body) hover:border-[#D8CCF4] hover:text-[#211A31] dark:text-ink-400 dark:hover:border-[#3A3152] dark:hover:text-ink-200' => $tab !== $key,
                        ])
                        @if ($tab === $key) aria-current="page" @endif
                    >
                        {{ $label }}
                        @if ($key === 'reviews' && $product->reviewCount() > 0)
                            <span class="ml-1 text-2xs tabular-nums opacity-60">{{ $product->reviewCount() }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>
    </div>

    {{-- ================================================================== --
     | Body
     --}}
    <div class="shell py-12 lg:py-14">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-14">
            <div class="min-w-0">
                @if ($tab === 'overview')
                    {{-- The seller's own screenshots. Each `src` is a temporary signed
                         link to the private disk, minted per render, so a saved page
                         stops loading the images an hour later rather than continuing
                         to serve them. --}}
                    @if ($product->media->isNotEmpty())
                        <section class="mb-9" aria-labelledby="gallery-heading" data-test="listing-gallery">
                            <h2 id="gallery-heading" class="sr-only">{{ __('Screenshots') }}</h2>

                            <div x-data="{ active: 0 }" class="space-y-3">
                                <div class="overflow-hidden rounded-xl border border-ink-200 bg-ink-50/60 dark:border-ink-800 dark:bg-ink-900/40">
                                    @foreach ($product->media as $index => $screenshot)
                                        <img
                                            x-show="active === {{ $index }}"
                                            x-cloak
                                            wire:key="listing-shot-{{ $screenshot->id }}"
                                            src="{{ $screenshot->url() }}"
                                            alt="{{ $screenshot->alt() }}"
                                            class="aspect-[16/9] w-full object-contain"
                                        />
                                    @endforeach
                                </div>

                                @if ($product->media->count() > 1)
                                    <ul class="flex gap-2 overflow-x-auto pb-1">
                                        @foreach ($product->media as $index => $screenshot)
                                            <li wire:key="listing-thumb-{{ $screenshot->id }}">
                                                <button
                                                    type="button"
                                                    x-on:click="active = {{ $index }}"
                                                    :aria-current="active === {{ $index }} ? 'true' : null"
                                                    class="block overflow-hidden rounded-md border transition ease-soft"
                                                    :class="active === {{ $index }} ? 'border-ink-900 dark:border-ink-50' : 'border-ink-200 opacity-70 hover:opacity-100 dark:border-ink-800'"
                                                    aria-label="{{ __('Show image :number', ['number' => $index + 1]) }}"
                                                >
                                                    <img
                                                        src="{{ $screenshot->url() }}"
                                                        alt=""
                                                        class="size-16 object-cover"
                                                        loading="lazy"
                                                    />
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </section>
                    @endif

                    <article class="prose-editorial max-w-none" aria-live="polite">
                        {!! $this->descriptionHtml !!}
                    </article>

                    @if (! empty($product->tags))
                        <div class="mt-8 flex flex-wrap items-center gap-2 border-t border-ink-200 pt-6 dark:border-ink-800">
                            <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Tagged') }}</span>
                            @foreach ($product->tags as $tag)
                                <span class="tag-pill">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif

                @elseif ($tab === 'installation')
                    <div class="space-y-8" aria-live="polite">
                        <section aria-labelledby="install-heading">
                            <h2 id="install-heading" class="text-lg font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ __('Install the package') }}
                            </h2>

                            @if ($product->install_command)
                                <div class="mt-4">
                                    <x-app.marketplace.install-command :command="$product->install_command" :label="__('Run in your project root')" />
                                </div>
                            @else
                                <p class="mt-3 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ __('The creator has not published an install command. Use the documentation link for setup instructions.') }}
                                </p>
                            @endif
                        </section>

                        <section aria-labelledby="requirements-heading">
                            <h2 id="requirements-heading" class="text-lg font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ __('Requirements') }}
                            </h2>

                            <dl class="mt-4 divide-y divide-ink-200 border-y border-ink-200 dark:divide-ink-800 dark:border-ink-800">
                                @foreach (array_filter([
                                    __('Framework') => $product->framework,
                                    __('Language') => $product->language,
                                    __('Current version') => $product->version,
                                ]) as $term => $value)
                                    <div class="grid gap-1 py-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-5">
                                        <dt class="text-sm font-medium text-ink-900 dark:text-ink-50">{{ $term }}</dt>
                                        <dd class="font-mono text-xs text-ink-600 dark:text-ink-300">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section aria-labelledby="licence-heading">
                            <h2 id="licence-heading" class="text-lg font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ __('Licence') }}
                            </h2>

                            <div class="mt-4 rounded-lg border border-ink-200 bg-ink-50/70 p-4 dark:border-ink-800 dark:bg-ink-900/40">
                                <p class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $product->license->label() }}</p>
                                <p class="mt-1 text-sm leading-relaxed text-ink-600 dark:text-ink-300">{{ $product->license->description() }}</p>
                            </div>
                        </section>
                    </div>

                @elseif ($tab === 'versions')
                    <div aria-live="polite">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <h2 class="text-lg font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ __('Release history') }}
                            </h2>

                            <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                {{ trans_choice(':count release|:count releases', $product->versions->count()) }}
                            </p>
                        </div>

                        @if ($product->versions->isEmpty())
                            <x-app.ui.empty-state
                                class="mt-6"
                                icon="cube"
                                :title="__('No releases recorded')"
                                :description="__('This listing has not published a release history yet.')"
                            />
                        @else
                            <ol class="mt-6 space-y-5">
                                @foreach ($product->versions as $release)
                                    <li class="relative rounded-lg border border-ink-200 p-5 dark:border-ink-800">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="flex items-center gap-2.5">
                                                <span class="font-mono text-sm font-semibold text-ink-900 dark:text-ink-50">v{{ $release->version }}</span>

                                                @if ($release->is_current)
                                                    <span class="rounded-full border border-signal-300 bg-signal-50 px-2 py-0.5 font-mono text-2xs uppercase tracking-widest text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300">
                                                        {{ __('Current') }}
                                                    </span>
                                                @endif
                                            </div>

                                            <time datetime="{{ $release->released_at->toDateString() }}" class="text-2xs text-ink-400 dark:text-ink-500">
                                                {{ $release->released_at->format('j M Y') }}
                                            </time>
                                        </div>

                                        @if ($release->notes() !== [])
                                            <ul class="mt-4 space-y-1.5">
                                                @foreach ($release->notes() as $note)
                                                    <li class="flex items-start gap-2 text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                                                        <span aria-hidden="true" class="mt-1.5 size-1 shrink-0 rounded-full bg-signal-500"></span>
                                                        <span>{{ $note }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>

                @else
                    <div aria-live="polite">
                        {{-- Summary --}}
                        <div class="rounded-xl border border-ink-200 p-5 dark:border-ink-800 sm:p-6">
                            @if ($product->averageRating() === null)
                                <p class="text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ __('No reviews yet. If you have used this product, your review would be the first — and it would be checked by a moderator before it appears.') }}
                                </p>
                            @else
                                <div class="grid gap-6 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center sm:gap-10">
                                    <div class="text-center sm:text-left">
                                        <p class="text-4xl font-semibold tabular-nums tracking-tightest text-ink-900 dark:text-ink-50">
                                            {{ number_format($product->averageRating(), 1) }}
                                        </p>
                                        <div class="mt-2 flex justify-center sm:justify-start">
                                            <x-app.marketplace.rating :product="$product" :show-count="false" size="lg" />
                                        </div>
                                        <p class="mt-1 text-2xs text-ink-400 dark:text-ink-500">
                                            {{ trans_choice(':count review|:count reviews', $product->reviewCount()) }}
                                        </p>
                                    </div>

                                    <ul class="space-y-1.5" role="list">
                                        @foreach ($this->ratingBreakdown as $star => $count)
                                            <li class="flex items-center gap-3">
                                                <span class="w-8 shrink-0 text-right font-mono text-2xs text-ink-500 dark:text-ink-400">{{ $star }}★</span>
                                                <span class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-ink-100 dark:bg-ink-800">
                                                    <span
                                                        class="block h-full rounded-full bg-(--v96-purple)"
                                                        style="width: {{ $product->reviewCount() > 0 ? ($count / $product->reviewCount()) * 100 : 0 }}%"
                                                    ></span>
                                                </span>
                                                <span class="w-6 shrink-0 text-right font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-500">{{ $count }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        {{-- Review form / status --}}
                        <div class="mt-8">
                            @guest
                                <div class="rounded-lg border border-ink-200 bg-ink-50/70 p-5 dark:border-ink-800 dark:bg-ink-900/40">
                                    <p class="text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                                        {{ __('Reviews are written by signed-in accounts and checked before they appear.') }}
                                    </p>
                                    <flux:button class="mt-4" variant="primary" :href="route('login')" x-on:click="$flux.modal('login').show(); $event.preventDefault()">
                                        {{ __('Sign in to review') }}
                                    </flux:button>
                                </div>
                            @else
                                @if ($this->myReview)
                                    <div class="rounded-lg border border-ink-200 bg-ink-50/70 p-5 dark:border-ink-800 dark:bg-ink-900/40">
                                        <p class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('Your review') }}</p>
                                        <p class="mt-1 text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                                            {{ $this->myReview->status->label() }} —
                                            {{ $this->myReview->status === ReviewStatus::Approved
                                                ? __('your review is published on this page.')
                                                : __('it will appear once a moderator has approved it.') }}
                                        </p>
                                    </div>
                                @elseif ($this->canReview)
                                    <form wire:submit="submitReview" class="rounded-lg border border-ink-200 p-5 dark:border-ink-800 sm:p-6">
                                        <h3 class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('Write a review') }}</h3>

                                        <p class="mt-1 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                                            {{ __('Describe what you built with it. Reviews are moderated before publication.') }}
                                        </p>

                                        @if ($reviewSubmitted)
                                            <div class="mt-4 rounded-md border border-signal-300 bg-signal-50 px-4 py-3 text-sm text-signal-800 dark:border-signal-800 dark:bg-signal-950/40 dark:text-signal-300" role="status">
                                                {{ __('Thanks — your review is queued for moderation.') }}
                                            </div>
                                        @endif

                                        <div class="mt-5">
                                            <span class="sui-label">{{ __('Rating') }}</span>

                                            <div class="mt-2 flex items-center gap-1" role="group" aria-label="{{ __('Rating out of five') }}">
                                                @for ($star = 1; $star <= 5; $star++)
                                                    <button
                                                        type="button"
                                                        wire:click="$set('reviewRating', {{ $star }})"
                                                        aria-pressed="{{ $reviewRating >= $star ? 'true' : 'false' }}"
                                                        aria-label="{{ trans_choice(':count star|:count stars', $star) }}"
                                                        class="focus-ring rounded p-0.5"
                                                    >
                                                        <flux:icon
                                                            name="star"
                                                            :variant="$reviewRating >= $star ? 'solid' : 'outline'"
                                                            class="size-6 {{ $reviewRating >= $star ? 'text-(--v96-purple-soft)' : 'text-ink-300 dark:text-ink-700' }}"
                                                        />
                                                    </button>
                                                @endfor
                                            </div>

                                            @error('reviewRating')
                                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="mt-5">
                                            <label for="review-title" class="sui-label">{{ __('Title') }} <span class="font-normal normal-case tracking-normal text-ink-400">{{ __('(optional)') }}</span></label>
                                            <input
                                                id="review-title"
                                                type="text"
                                                wire:model="reviewTitle"
                                                maxlength="120"
                                                class="focus-ring mt-2 min-h-10 w-full rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-900 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50"
                                            >
                                            @error('reviewTitle')
                                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="mt-4">
                                            <label for="review-body" class="sui-label">{{ __('Your review') }}</label>
                                            <textarea
                                                id="review-body"
                                                wire:model="reviewBody"
                                                rows="4"
                                                maxlength="2000"
                                                class="focus-ring mt-2 w-full rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm leading-relaxed text-ink-900 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50"
                                            ></textarea>
                                            @error('reviewBody')
                                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="mt-5 flex items-center gap-3">
                                            <flux:button type="submit" variant="primary">
                                                {{ __('Submit for review') }}
                                            </flux:button>

                                            <span class="text-2xs text-ink-400 dark:text-ink-500">{{ __('Minimum 20 characters') }}</span>
                                        </div>
                                    </form>
                                @endif
                            @endguest
                        </div>

                        {{-- Published reviews --}}
                        <div class="mt-10">
                            <h3 class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                                {{ __('Published reviews') }}
                            </h3>

                            @if ($product->approvedReviews->isEmpty())
                                <p class="mt-4 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ __('Nothing published yet.') }}
                                </p>
                            @else
                                <div class="mt-4">
                                    @foreach ($product->approvedReviews as $review)
                                        <x-app.marketplace.review-card :review="$review" wire:key="review-{{ $review->id }}" />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- ========================================================= --
             | Sidebar
             --}}
            <aside class="space-y-5 lg:sticky lg:top-24 lg:self-start">
                <div class="v96-card !h-auto p-5 shadow-[0_18px_36px_-28px_rgba(40,20,80,0.4)]">
                    <p class="v96-eyebrow">
                        {{ __('Price') }}
                    </p>

                    <p class="v96-price mt-3 !text-[28px]">
                        {{ $product->formattedPrice() }}
                    </p>

                    <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">
                        {{ $product->license->label() }}@unless ($product->isFree()) · {{ $product->currency }}@endunless
                    </p>

                    @if ($this->purchasable)
                        <a
                            href="{{ route('marketplace.checkout', $product) }}"
                            wire:navigate
                            class="focus-ring v96-btn mt-5 w-full"
                        >
                            {{ $product->isFree() ? __('Get for free') : __('Buy · :price', ['price' => $product->formattedPrice()]) }}
                        </a>

                        <button
                            type="button"
                            wire:click="showTab('installation')"
                            class="focus-ring v96-btn v96-btn--ghost mt-2 w-full"
                        >
                            {{ __('View installation') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="showTab('installation')"
                            class="focus-ring v96-btn mt-5 w-full"
                        >
                            {{ $product->isFree() ? __('Install now') : __('View installation') }}
                        </button>
                    @endif

                    @if ($product->demo_url)
                        <a href="{{ $product->demo_url }}" target="_blank" rel="noopener noreferrer" class="focus-ring v96-btn v96-btn--ghost mt-2 w-full">
                            {{ __('Open live demo') }}
                        </a>
                    @endif

                    <p class="mt-4 border-t border-ink-200 pt-4 text-2xs leading-relaxed text-ink-400 dark:border-ink-800 dark:text-ink-500">
                        @if ($this->purchasable)
                            {{ $product->isFree()
                                ? __('Free listing: getting it records a purchase and charges nothing.')
                                : __('You choose a payment method on the next step; nothing is captured until the payment is confirmed. Payments are simulated — no real account is charged.') }}
                        @else
                            {{ __('Preview deployment: prices and licences are shown for reference and nothing is charged.') }}
                        @endif
                    </p>
                </div>

                <div class="v96-card !h-auto p-5">
                    <p class="v96-eyebrow">
                        {{ __('Listing facts') }}
                    </p>

                    <dl class="mt-3 space-y-2.5">
                        @foreach (array_filter([
                            __('Version') => $product->version,
                            __('Released') => $product->last_released_at?->format('j M Y'),
                            __('Framework') => $product->framework,
                            __('Language') => $product->language,
                            __('Category') => $product->category?->name,
                        ]) as $term => $value)
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-xs text-ink-500 dark:text-ink-400">{{ $term }}</dt>
                                <dd class="text-right text-xs font-medium text-ink-800 dark:text-ink-200">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                @if ($product->docs_url || $product->repository_url || $product->demo_url)
                    <div class="v96-card !h-auto p-5">
                        <p class="v96-eyebrow">
                            {{ __('Links') }}
                        </p>

                        <ul class="mt-3 space-y-2">
                            @foreach (array_filter([
                                __('Documentation') => $product->docs_url,
                                __('Source repository') => $product->repository_url,
                                __('Live demo') => $product->demo_url,
                            ]) as $label => $url)
                                <li>
                                    <a
                                        href="{{ $url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="focus-ring inline-flex items-center gap-1.5 text-xs font-semibold text-(--v96-body) transition-colors hover:text-(--v96-purple-lift) dark:text-ink-300"
                                    >
                                        {{ $label }}
                                        <flux:icon name="arrow-up-right" class="size-3.5" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="v96-card !h-auto p-5">
                    <p class="v96-eyebrow">
                        {{ __('Creator') }}
                    </p>

                    <div class="mt-3 flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full border border-ink-200 bg-ink-50 text-xs font-semibold text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                            {{ Str::of($product->creator->name)->substr(0, 1)->upper() }}
                        </span>

                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $product->creator->name }}</p>
                            <p class="text-2xs text-ink-400 dark:text-ink-500">{{ __('Publishes on DevFolio') }}</p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    {{-- ================================================================== --
     | Related
     --}}
    @if ($this->related->isNotEmpty())
        <section class="v96-band border-t py-12 lg:py-14" aria-labelledby="related-heading">
            <div class="shell">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="v96-eyebrow">{{ __('Keep browsing') }}</p>

                        <h2 id="related-heading" class="mt-3 text-xl font-extrabold tracking-tight text-ink-900 dark:text-ink-50">
                            {{ __('Also in this category') }}
                        </h2>
                    </div>

                    <a href="{{ route('marketplace.index') }}" wire:navigate class="focus-ring text-xs font-semibold text-(--v96-body) transition-colors hover:text-(--v96-purple-lift) dark:text-ink-400">
                        {{ __('Browse everything') }} &rarr;
                    </a>
                </div>

                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->related as $related)
                        <x-app.marketplace.product-card :product="$related" wire:key="related-{{ $related->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <div class="v96-band border-t py-8">
        <div class="shell">
            <aside class="text-xs leading-relaxed text-ink-500 dark:text-ink-400" role="note">
                <span class="v96-mono font-semibold uppercase tracking-[0.08em] text-[#3A3148] dark:text-ink-200">{{ __('How listings work:') }}</span>
                {{ __('Every listing is reviewed by a person before it is published, and its price, licence and release history are stated by its creator. Reviews are moderated and only approved reviews count toward a rating.') }}
            </aside>
        </div>
    </div>
</div>
