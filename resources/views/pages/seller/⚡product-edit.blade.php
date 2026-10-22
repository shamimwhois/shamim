<?php

use App\Actions\Marketplace\AttachProductScreenshot;
use App\Actions\Marketplace\CreateProduct;
use App\Actions\Marketplace\DeleteProduct;
use App\Actions\Marketplace\DetachProductScreenshot;
use App\Actions\Marketplace\PublishRelease;
use App\Actions\Marketplace\SubmitProduct;
use App\Actions\Marketplace\UpdateProduct;
use App\Enums\AccountPermission;
use App\Enums\ProductLicense;
use App\Enums\ProductStatus;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductMedia;
use App\Models\MarketplaceProductVersion;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The seller product editor.
 *
 * One component serves both routes: `$product` is null while creating and set
 * while editing, and the form is the same either way. A listing built across two
 * sittings should not change shape because it acquired an id.
 *
 * Status decides what the seller may do:
 *
 *   - Draft: editable, and "Submit for review" is the way out of it.
 *   - PendingReview / Published / Archived: readable, never editable. Rewriting a
 *     listing an operator has already read would change what they approved.
 *
 * A stranger's slug answers 404 rather than 403, so this screen cannot be used to
 * confirm that someone else's listing exists. Moderation is not missing here but
 * deliberately elsewhere: `admin/marketplace` moves listings between statuses,
 * while both writers refuse a non-owner, so an author's surface is the only one
 * this screen can be.
 *
 * The two panels below the form — screenshots and releases — follow a different
 * lock from the form itself. Rewriting copy an operator has already approved would
 * change what they signed off on, so the form closes at submission. A screenshot
 * and a release are new facts about the listing rather than a rewrite of the old
 * ones, and a seller who ships v1.0.1 has to be able to say so about a listing that
 * is already live; those panels are gated on ownership alone, which is exactly what
 * {@see AttachProductScreenshot} and {@see PublishRelease} re-check on the write.
 */
new #[Layout('layouts::seller.dashboard')] #[Title('Product')] class extends Component
{
    use WithFileUploads;

    /** The product under edit. Null while creating. */
    public ?MarketplaceProduct $product = null;

    public string $name = '';
    public string $slug = '';
    public string $tagline = '';
    public string $description = '';

    /** Minor units — 1999 is 19.99. Null when the seller has cleared the field. */
    public ?int $priceCents = 0;

    public string $currency = 'USD';
    public string $license = ProductLicense::Mit->value;
    public string $version = '1.0.0';
    public ?int $categoryId = null;
    public ?string $framework = null;
    public ?string $language = null;
    public ?string $installCommand = null;
    public ?string $demoUrl = null;
    public ?string $docsUrl = null;
    public ?string $repositoryUrl = null;

    /** @var array<int, string> */
    public array $tags = [];

    /**
     * The tag being typed.
     *
     * Held apart from `$tags` so the chip list only ever contains tags the seller
     * has committed to, and a half-typed word cannot be saved with the listing.
     */
    public string $tagInput = '';

    public bool $creating = false;

    /**
     * Files Livewire has staged but this screen has not yet written to the disk.
     *
     * Untyped on purpose: a Livewire upload property holds a
     * {@see \Livewire\Features\SupportFileUploads\TemporaryUploadedFile} while it
     * is in flight, and declaring a type would fight the mechanism.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>|\Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $uploads = null;

    /** @var array<int, string> alt text keyed by media id, edited inline */
    public array $alts = [];

    public string $releaseVersion = '';
    public string $releaseNotes = '';
    public ?string $releasedAt = null;
    public bool $releaseIsCurrent = true;

    /**
     * The archive staged for a release. Same untyped reason as {@see $uploads}.
     */
    public $releaseFile = null;

    public function boot(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::CreateProduct) ?? false,
            403,
        );
    }

    public function mount(?MarketplaceProduct $product = null): void
    {
        $this->creating = $product === null;

        if (! $this->creating && ! $product->isOwnedBy(auth()->user())) {
            abort(404);
        }

        app(Seo::class)->set([
            'title' => $this->creating ? 'New listing' : 'Edit: '.$product->name,
            'description' => 'Manage your marketplace product.',
            'noindex' => true,
        ]);

        if ($this->creating) {
            return;
        }

        $this->product = $product;

        $this->name = $product->name;
        $this->slug = $product->slug;
        $this->tagline = $product->tagline;
        $this->description = $product->description;
        $this->priceCents = (int) $product->price_cents;
        $this->currency = $product->currency ?? 'USD';
        $this->license = ($product->license ?? ProductLicense::Mit)->value;
        $this->version = $product->version ?? '1.0.0';
        $this->categoryId = $product->marketplace_category_id;
        $this->framework = $product->framework;
        $this->language = $product->language;
        $this->installCommand = $product->install_command;
        $this->demoUrl = $product->demo_url;
        $this->docsUrl = $product->docs_url;
        $this->repositoryUrl = $product->repository_url;
        $this->tags = array_values((array) $product->tags);
        $this->releaseVersion = $product->version ?? '';
    }

    /**
     * The symbol for the currency currently selected in the form.
     *
     * Computed so the price field's prefix follows the currency dropdown as the
     * seller switches it. The map is the model's own, because `intl` is not
     * guaranteed to be installed.
     */
    #[Computed]
    public function currencySymbol(): string
    {
        return MarketplaceProduct::symbolFor($this->currency);
    }

    /**
     * Whether the form may be written to.
     *
     * A new listing has nothing to be locked behind yet; a saved one is editable
     * while it is a draft and while the account in front of it is the author.
     */
    public function isEditable(): bool
    {
        return $this->creating || $this->isOwnDraft();
    }

    /**
     * Whether "Submit for review" belongs on the screen.
     *
     * A listing that has not been saved yet has nothing for a reviewer to read.
     */
    public function canSubmit(): bool
    {
        return ! $this->creating && $this->isOwnDraft();
    }

    /**
     * The saved draft this account authored — the only state this screen may write.
     *
     * `status` is enum-cast, so it is compared to the case and never to a string.
     */
    public function isOwnDraft(): bool
    {
        return $this->product !== null
            && $this->product->status === ProductStatus::Draft
            && $this->product->isOwnedBy(auth()->user());
    }

    /**
     * The categories a listing may be filed under.
     *
     * Active only, in the order operators set them: a deactivated category is
     * hidden from the catalogue, so offering it here would file a listing nowhere.
     *
     * @return Collection<int, MarketplaceCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return MarketplaceCategory::query()
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * The form as its writers want it: snake_case columns, a price that is never null.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'price_cents' => $this->priceCents ?? 0,
            'currency' => $this->currency,
            'license' => $this->license,
            'version' => $this->version,
            'marketplace_category_id' => $this->categoryId,
            'framework' => $this->framework,
            'language' => $this->language,
            'install_command' => $this->installCommand,
            'demo_url' => $this->demoUrl,
            'docs_url' => $this->docsUrl,
            'repository_url' => $this->repositoryUrl,
            'tags' => $this->tags,
        ];
    }

    /**
     * What the listing still needs before it may go to review.
     *
     * Read off an unsaved model rather than a list kept here, because
     * {@see MarketplaceProduct::missingSubmissionFields()} is the very rule
     * {@see SubmitProduct} applies — the checklist on this screen and the refusal
     * in the action cannot drift apart.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function submissionGaps(): array
    {
        return (new MarketplaceProduct($this->formData()))->missingSubmissionFields();
    }

    public function addTag(): void
    {
        $tag = Str::squish($this->tagInput);
        $this->tagInput = '';

        if ($tag === '' || collect($this->tags)->contains(
            fn (string $existing): bool => mb_strtolower($existing) === mb_strtolower($tag)
        )) {
            return;
        }

        $this->tags[] = $tag;
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);

        $this->tags = array_values($this->tags);
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            // The slug column carries a real UNIQUE index and both writers suffix
            // a collision, so a blank slug is fine — but a slug that already
            // belongs to another listing is a mistake worth naming here rather
            // than quietly renaming the listing on save.
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('marketplace_products', 'slug')->ignore($this->product),
            ],
            'tagline' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priceCents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'license' => ['required', Rule::enum(ProductLicense::class)],
            'version' => 'nullable|string|max:32',
            'categoryId' => ['nullable', 'integer', 'exists:marketplace_categories,id'],
            'framework' => 'nullable|string|max:64',
            'language' => 'nullable|string|max:32',
            'installCommand' => 'nullable|string|max:500',
            'demoUrl' => 'nullable|url|max:500',
            'docsUrl' => 'nullable|url|max:500',
            'repositoryUrl' => 'nullable|url|max:500',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
        ]);

        $justCreated = $this->creating;

        try {
            if ($justCreated) {
                $this->product = app(CreateProduct::class)->handle(auth()->user(), $this->formData());
            } else {
                abort_unless($this->isEditable(), 403);
                $this->authorize('update', $this->product);

                $this->product = app(UpdateProduct::class)->handle(
                    auth()->user(),
                    $this->product,
                    $this->formData(),
                );
            }
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(
            variant: 'success',
            text: $justCreated ? __('Product created.') : __('Product updated.'),
        );

        $this->redirect(route('seller.products'));
    }

    public function submit(SubmitProduct $submitProduct): void
    {
        abort_unless($this->canSubmit(), 403);
        $this->authorize('submit', $this->product);

        try {
            $submitProduct->handle(auth()->user(), $this->product);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->product->refresh();

        Flux::toast(
            variant: 'success',
            text: __('Submitted for review.'),
        );

        $this->redirect(route('seller.products'));
    }

    public function delete(DeleteProduct $deleteProduct): void
    {
        // The same state "Submit for review" needs: a draft this account authored.
        // A listing already in the queue or in the catalogue is an operator's to
        // withdraw, and the row outlives this screen either way.
        abort_unless($this->canSubmit(), 403);
        $this->authorize('delete', $this->product);

        $deleteProduct->handle(auth()->user(), $this->product);

        Flux::toast(
            variant: 'success',
            text: __('Product deleted.'),
        );

        $this->redirect(route('seller.products'));
    }

    /**
     * Whether the account in front of this screen authored the saved listing.
     *
     * The gate for the two panels below the form. Ownership is the whole rule
     * there — unlike the form, which closes at submission — and the writers re-check
     * it, so this only decides what is shown rather than what is allowed.
     */
    public function isOwnListing(): bool
    {
        return $this->product !== null && $this->product->isOwnedBy(auth()->user());
    }

    /**
     * The listing's screenshots, in the order they were added.
     *
     * @return Collection<int, MarketplaceProductMedia>
     */
    #[Computed]
    public function screenshots(): Collection
    {
        return $this->product?->media()->get() ?? new Collection;
    }

    /**
     * Every release of the listing, newest first.
     *
     * @return Collection<int, MarketplaceProductVersion>
     */
    #[Computed]
    public function releases(): Collection
    {
        return $this->product?->versions()->get() ?? new Collection;
    }

    /**
     * The package staged for the next release, or null while the input is empty.
     */
    #[Computed]
    public function stagedPackage(): ?UploadedFile
    {
        $file = $this->uploadFiles($this->releaseFile)[0] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'alts.')) {
            $this->storeAlt((int) Str::after($name, 'alts.'));
        }
    }

    /**
     * Write whichever screenshots Livewire has just finished transferring.
     */
    public function updatedUploads(): void
    {
        $this->stageScreenshots();
    }

    public function retryUploads(): void
    {
        $this->stageScreenshots();
    }

    /**
     * Remove one screenshot, row and bytes together.
     */
    public function removeScreenshot(int $mediaId): void
    {
        abort_unless($this->isOwnListing(), 404);

        /** @var MarketplaceProductMedia|null $media */
        $media = MarketplaceProductMedia::query()
            ->where('marketplace_product_id', $this->product?->id)
            ->find($mediaId);

        abort_if($media === null, 404);

        app(DetachProductScreenshot::class)->handle(auth()->user(), $media);

        unset($this->alts[$mediaId], $this->screenshots);
    }

    /**
     * Record a release, with a package if the seller attached one.
     */
    public function publish(PublishRelease $publishRelease): void
    {
        abort_unless($this->isOwnListing(), 404);

        $this->validate([
            'releaseVersion' => ['required', 'string', 'max:32'],
            'releaseNotes' => ['nullable', 'string', 'max:10000'],
            'releasedAt' => ['nullable', 'date'],
            'releaseFile' => [
                'nullable',
                'file',
                'max:'.(int) config('marketplace.deliverable.max_upload_kilobytes', 20480),
            ],
        ]);

        try {
            $publishRelease->handle(
                auth()->user(),
                $this->product,
                [
                    'version' => $this->releaseVersion,
                    'release_notes' => $this->releaseNotes,
                    'released_at' => $this->releasedAt,
                    'is_current' => $this->releaseIsCurrent,
                ],
                $this->stagedPackage,
            );
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->product?->refresh();
        $this->reset('releaseNotes', 'releasedAt', 'releaseFile');
        $this->releaseIsCurrent = true;
        unset($this->releases, $this->stagedPackage);

        Flux::toast(variant: 'success', text: __('Release published.'));
    }

    /**
     * Point one release at a different package without touching its history.
     */
    public function replaceDeliverable(int $versionId, PublishRelease $publishRelease): void
    {
        abort_unless($this->isOwnListing(), 404);

        $this->validate([
            'releaseFile' => [
                'required',
                'file',
                'max:'.(int) config('marketplace.deliverable.max_upload_kilobytes', 20480),
            ],
        ]);

        /** @var MarketplaceProductVersion|null $release */
        $release = MarketplaceProductVersion::query()
            ->where('marketplace_product_id', $this->product?->id)
            ->find($versionId);

        abort_if($release === null, 404);

        $package = $this->stagedPackage;

        if ($package === null) {
            $this->addError('releaseFile', __('Choose the file that should take its place.'));

            return;
        }

        try {
            $publishRelease->replaceDeliverable(
                auth()->user(),
                $release,
                $package,
            );
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reset('releaseFile');
        unset($this->releases, $this->stagedPackage);

        Flux::toast(variant: 'success', text: __('File replaced.'));
    }

    /**
     * Store every staged file, then forget the staging copies.
     *
     * The cap is checked here as well as inside the writer because a refusal this
     * early can name the number the seller exceeded, and can clear the input before
     * a second upload compounds the problem.
     */
    private function stageScreenshots(): void
    {
        $files = $this->uploadFiles($this->uploads);

        if ($files === []) {
            return;
        }

        if (! $this->isOwnListing()) {
            $this->uploads = null;
            $this->addError('uploads', __('Save the listing before adding screenshots.'));

            return;
        }

        /** @var int $cap */
        $cap = (int) config('marketplace.media.max_per_listing', 8);

        if ($this->screenshots->count() + count($files) > $cap) {
            $this->uploads = null;
            $this->addError('uploads', __('A listing can carry at most :count images.', ['count' => $cap]));

            return;
        }

        $this->validate([
            'uploads' => ['array', 'max:'.$cap],
            'uploads.*' => [
                'image',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:'.(int) config('marketplace.media.max_upload_kilobytes', 4096),
                'dimensions:min_width=16,min_height=16,max_width=6000,max_height=6000',
            ],
        ], [
            'uploads.*.image' => __('Only image files can be attached to a listing.'),
            'uploads.*.dimensions' => __('That image is too small or too large to attach.'),
        ]);

        app(AttachProductScreenshot::class)->handle($this->product, auth()->user(), $files);

        $this->uploads = null;
        unset($this->screenshots);
    }

    /**
     * Save the description of one screenshot against the file it describes.
     *
     * The media is looked up through this listing rather than by id alone, so a
     * crafted field name cannot write alt text onto somebody else's image.
     */
    private function storeAlt(int $mediaId): void
    {
        if ($mediaId <= 0 || ! $this->isOwnListing()) {
            return;
        }

        /** @var MarketplaceProductMedia|null $media */
        $media = MarketplaceProductMedia::query()
            ->where('marketplace_product_id', $this->product?->id)
            ->find($mediaId);

        if ($media === null) {
            return;
        }

        /** @var string $text */
        $text = trim((string) ($this->alts[$mediaId] ?? ''));

        $media->update(['alt_text' => $text === '' ? null : Str::limit($text, 500, '')]);

        unset($this->screenshots);
    }

    /**
     * An upload property as the list of files it holds, empty when it holds none.
     *
     * Livewire hands over a single object when the input takes one file and an array
     * when it takes several, and a half-finished multi-upload can leave nulls in the
     * array. Both writers take a list, so the shape is settled once here.
     *
     * @return array<int, UploadedFile>
     */
    private function uploadFiles(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return array_values(array_filter(is_array($value) ? $value : [$value]));
    }
} ?>

<div>
    <x-app.ui.header
        :eyebrow="$creating ? 'New listing' : 'Editing'"
        :title="$creating ? __('New product listing') : $product->name"
        :description="$creating
            ? __('A draft is private to you until you submit it for review.')
            : __('Changes save while the listing is a draft. Submitting it hands the listing to an operator.')"
        :breadcrumbs="[['label' => __('Products'), 'href' => route('seller.products')], ['label' => $creating ? __('New') : __('Edit')]]"
    />

    <x-app.ui.body>
        {{-- ============================================================= --
         | Status — what the seller may still do, stated before the form
         --}}
        @unless ($creating)
            <flux:callout variant="secondary" icon="information-circle">
                <p class="text-sm leading-normal text-ink-600">
                    {{ __('Status') }}:
                    <flux:badge :color="$product->status->badgeColor()" size="sm">
                        {{ $product->status->label() }}
                    </flux:badge>
                    @if ($product->status === ProductStatus::Draft)
                        {{ __('Only you can see this draft. Submit it for review to go live.') }}
                    @elseif ($product->status === ProductStatus::PendingReview)
                        {{ __('Waiting on an operator to review before it appears in the catalogue.') }}
                    @elseif ($product->status === ProductStatus::Published)
                        {{ __('Live in the public catalogue. Editing is closed once published.') }}
                    @else
                        {{ __('Withdrawn from the catalogue; the record and its sales are kept.') }}
                    @endif
                </p>

                @unless ($this->isEditable())
                    <p class="mt-1 text-xs leading-normal text-ink-500">
                        {{ __('The form below reads as a copy of what is on file.') }}
                    </p>
                @endunless
            </flux:callout>
        @endunless

        <form wire:submit.prevent="save" class="space-y-6">

            {{-- ========================================================= --
         | Details
         --}}
            <div class="sui-card p-5">
                <div class="border-b border-sui-hairline pb-4">
                    <h3 class="text-sm font-semibold tracking-tight text-ink-900">{{ __('Details') }}</h3>
                </div>

                <div class="grid gap-4 pt-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <label for="product-name" class="sui-control-label">{{ __('Name') }}</label>
                        <input
                            id="product-name"
                            type="text"
                            wire:model="name"
                            data-test="product-name"
                            autocomplete="off"
                            class="sui-control w-full"
                        />
                        @error('name')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-slug" class="sui-control-label">{{ __('Slug') }}</label>
                        <input
                            id="product-slug"
                            type="text"
                            wire:model="slug"
                            data-test="product-slug"
                            autocomplete="off"
                            class="sui-control w-full"
                        />
                        <p class="mt-1 sui-help">
                            {{ __('Leave it blank and the address follows the name.') }}
                        </p>
                        @error('slug')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-2 pt-4">
                    <label for="product-tagline" class="sui-control-label">{{ __('Tagline') }}</label>
                    <textarea
                        id="product-tagline"
                        wire:model.blur="tagline"
                        data-test="product-tagline"
                        rows="2"
                        class="sui-control w-full resize-y"
                    ></textarea>
                    @error('tagline')
                        <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2 pt-4">
                    <label for="product-description" class="sui-control-label">{{ __('Description') }}</label>
                    <textarea
                        id="product-description"
                        wire:model.blur="description"
                        data-test="product-description"
                        rows="6"
                        class="sui-control w-full resize-y"
                    ></textarea>
                    @error('description')
                        <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- ========================================================= --
         | Pricing, licence and category
         --}}
            <div class="sui-card p-5">
                <div class="border-b border-sui-hairline pb-4">
                    <h3 class="text-sm font-semibold tracking-tight text-ink-900">{{ __('Pricing & licence') }}</h3>
                </div>

                <div class="grid gap-4 pt-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <label for="product-price" class="sui-control-label">{{ __('Price') }}</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-2xs text-ink-400">
                                {{ $this->currencySymbol }}
                            </span>
                            <input
                                id="product-price"
                                type="number"
                                min="0"
                                step="1"
                                wire:model="priceCents"
                                data-test="product-price"
                                placeholder="0"
                                class="sui-control w-full pl-8 tabular-nums"
                            />
                        </div>
                        <p class="mt-1 sui-help">
                            {{ __('Whole minor units — 1999 is :price. Leave it empty for a free listing.', [
                                'price' => $this->currencySymbol.'19.99',
                            ]) }}
                        </p>
                        @error('priceCents')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-currency" class="sui-control-label">{{ __('Currency') }}</label>
                        <select
                            id="product-currency"
                            wire:model.live="currency"
                            data-test="product-currency"
                            class="sui-control w-full"
                        >
                            @foreach (['USD', 'EUR', 'GBP', 'BDT', 'INR'] as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-2">
                        <label for="product-license" class="sui-control-label">{{ __('Licence') }}</label>
                        <select id="product-license" wire:model="license" class="sui-control w-full">
                            @foreach (ProductLicense::cases() as $lic)
                                <option value="{{ $lic->value }}">{{ $lic->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-2">
                        <label for="product-category" class="sui-control-label">{{ __('Category') }}</label>
                        <select
                            id="product-category"
                            wire:model.live="categoryId"
                            data-test="product-category"
                            class="sui-control w-full"
                        >
                            <option value="">{{ __('Uncategorised') }}</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 sui-help">
                            {{ __('Categories are how buyers browse the catalogue. Use tags for the specifics.') }}
                        </p>
                        @error('categoryId')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- ========================================================= --
         | Tags
         --}}
            <div class="sui-card p-5">
                <div class="border-b border-sui-hairline pb-4">
                    <h3 class="text-sm font-semibold tracking-tight text-ink-900">{{ __('Tags') }}</h3>
                </div>

                @if ($tags !== [])
                    <ul class="mt-4 flex flex-wrap gap-1.5">
                        @foreach ($tags as $index => $tag)
                            <li wire:key="tag-{{ $index }}">
                                @if ($this->isEditable())
                                    <button
                                        type="button"
                                        wire:click="removeTag({{ $index }})"
                                        class="tag-pill cursor-pointer"
                                        title="{{ __('Remove :tag', ['tag' => $tag]) }}"
                                    >
                                        {{ $tag }}
                                        <flux:icon name="x-mark" class="size-2.5" />
                                        <span class="sr-only">{{ __('Remove :tag', ['tag' => $tag]) }}</span>
                                    </button>
                                @else
                                    <span class="tag-pill">{{ $tag }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 sui-help">{{ __('No tags yet.') }}</p>
                @endif

                @if ($this->isEditable())
                    <div class="mt-4 flex gap-2">
                        <label for="product-tag-input" class="sr-only">{{ __('Add a tag') }}</label>
                        <input
                            id="product-tag-input"
                            type="text"
                            wire:model="tagInput"
                            wire:keydown.enter.prevent="addTag"
                            data-test="product-tag-input"
                            placeholder="{{ __('Add a tag…') }}"
                            autocomplete="off"
                            class="sui-control min-w-0 flex-1 !py-1.5 text-xs"
                        />
                        <flux:button size="xs" variant="ghost" wire:click="addTag" icon="plus">
                            <span class="sr-only">{{ __('Add tag') }}</span>
                        </flux:button>
                    </div>
                @endif

                @error('tags')
                    <p class="mt-2 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                @enderror
            </div>

            {{-- ========================================================= --
         | Metadata
         --}}
            <div class="sui-card p-5">
                <div class="border-b border-sui-hairline pb-4">
                    <h3 class="text-sm font-semibold tracking-tight text-ink-900">{{ __('Metadata') }}</h3>
                </div>

                <div class="grid gap-4 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2">
                        <label for="product-version" class="sui-control-label">{{ __('Version') }}</label>
                        <input
                            id="product-version"
                            type="text"
                            wire:model="version"
                            placeholder="1.0.0"
                            autocomplete="off"
                            class="sui-control w-full"
                        />
                        @error('version')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-framework" class="sui-control-label">{{ __('Framework') }}</label>
                        <input
                            id="product-framework"
                            type="text"
                            wire:model="framework"
                            placeholder="Laravel"
                            autocomplete="off"
                            class="sui-control w-full"
                        />
                        @error('framework')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-language" class="sui-control-label">{{ __('Language') }}</label>
                        <input
                            id="product-language"
                            type="text"
                            wire:model="language"
                            placeholder="PHP"
                            autocomplete="off"
                            class="sui-control w-full"
                        />
                        @error('language')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-install" class="sui-control-label">{{ __('Install command') }}</label>
                        <input
                            id="product-install"
                            type="text"
                            wire:model="installCommand"
                            placeholder="composer require vendor/package"
                            autocomplete="off"
                            class="sui-control w-full"
                        />
                        @error('installCommand')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-demo" class="sui-control-label">{{ __('Demo URL') }}</label>
                        <input
                            id="product-demo"
                            type="url"
                            wire:model="demoUrl"
                            placeholder="https://demo.example.com"
                            class="sui-control w-full"
                        />
                        @error('demoUrl')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-docs" class="sui-control-label">{{ __('Docs URL') }}</label>
                        <input
                            id="product-docs"
                            type="url"
                            wire:model="docsUrl"
                            placeholder="https://docs.example.com"
                            class="sui-control w-full"
                        />
                        @error('docsUrl')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="product-repo" class="sui-control-label">{{ __('Repository URL') }}</label>
                        <input
                            id="product-repo"
                            type="url"
                            wire:model="repositoryUrl"
                            placeholder="https://github.com/vendor/package"
                            class="sui-control w-full"
                        />
                        @error('repositoryUrl')
                            <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- ========================================================= --
         | Screenshots — the listing's own gallery, on the private disk
         --}}
            @if ($this->isOwnListing())
                <div class="sui-card p-5" data-test="product-screenshots">
                    <div class="border-b border-sui-hairline pb-4">
                        <h3 class="text-sm font-semibold tracking-tight text-ink-900">{{ __('Screenshots') }}</h3>
                        <p class="mt-1 text-xs leading-normal text-ink-500">
                            {{ __('A listing may carry up to :cap images, each under :max. They are stored privately and served by signed link.', [
                                'cap' => (int) config('marketplace.media.max_per_listing', 8),
                                'max' => rtrim(number_format((int) config('marketplace.media.max_upload_kilobytes', 4096) / 1024, 1, '.', ''), '.0').' MB',
                            ]) }}
                        </p>
                    </div>

                    <input
                        id="product-screenshot-input"
                        type="file"
                        x-ref="screenshots"
                        wire:model="uploads"
                        multiple
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        class="hidden"
                        aria-label="{{ __('Attach screenshots') }}"
                        data-test="screenshot-input"
                    />

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <flux:button type="button" size="sm" variant="outline" icon="photo" x-on:click="$refs.screenshots.click()">
                            {{ __('Add images') }}
                        </flux:button>

                        <span
                            wire:loading
                            wire:target="uploads, retryUploads"
                            class="font-mono text-2xs uppercase tracking-wider text-ink-400"
                            data-test="screenshot-uploading"
                        >{{ __('Uploading…') }}</span>
                    </div>

                    @error('uploads')
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg sui-well px-3 py-2" role="alert">
                            <p class="text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @if (! empty($uploads))
                                <button type="button" wire:click="retryUploads" class="text-xs font-medium text-sui-danger underline">
                                    {{ __('Try again') }}
                                </button>
                            @endif
                        </div>
                    @enderror

                    @if ($this->screenshots->isNotEmpty())
                        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($this->screenshots as $screenshot)
                                <li wire:key="screenshot-{{ $screenshot->id }}" class="rounded-lg sui-well p-2 space-y-2">
                                    <div class="relative">
                                        <img
                                            src="{{ $screenshot->url() }}"
                                            alt="{{ $screenshot->alt() }}"
                                            class="aspect-[4/3] w-full rounded-md object-cover"
                                            loading="lazy"
                                        />
                                        <button
                                            type="button"
                                            wire:click="removeScreenshot({{ $screenshot->id }})"
                                            class="absolute end-1 top-1 grid size-6 place-items-center rounded-full bg-black/60 text-white hover:bg-black/80"
                                            aria-label="{{ __('Remove this image') }}"
                                            data-test="screenshot-remove-{{ $screenshot->id }}"
                                        >
                                            <flux:icon name="x-mark" class="size-3" />
                                        </button>
                                    </div>

                                    <label for="screenshot-alt-{{ $screenshot->id }}" class="sr-only">
                                        {{ __('Alt text for this image') }}
                                    </label>
                                    <input
                                        id="screenshot-alt-{{ $screenshot->id }}"
                                        type="text"
                                        wire:model.blur="alts.{{ $screenshot->id }}"
                                        value="{{ $screenshot->alt_text }}"
                                        placeholder="{{ __('Describe this image') }}"
                                        class="sui-control w-full !py-1.5 text-xs"
                                        data-test="screenshot-alt-{{ $screenshot->id }}"
                                    />

                                    <p class="font-mono text-2xs text-ink-400">
                                        {{ $screenshot->humanSize() }}
                                        @if ($screenshot->width && $screenshot->height)
                                            · {{ $screenshot->width }}×{{ $screenshot->height }}
                                        @endif
                                    </p>
                                </li>
                            @endforeach
                        </ul>

                        <p class="mt-3 text-xs leading-normal text-ink-500">
                            {{ __(':used of :cap images used.', [
                                'used' => $this->screenshots->count(),
                                'cap' => (int) config('marketplace.media.max_per_listing', 8),
                            ]) }}
                        </p>
                    @else
                        <p class="mt-4 sui-help">{{ __('No images yet. Listings with screenshots get more attention in the catalogue.') }}</p>
                    @endif
                </div>
            @endif

            {{-- ========================================================= --
         | Releases — versions and the package a buyer downloads
         --}}
            @if ($this->isOwnListing())
                <div class="sui-card p-5" data-test="product-releases">
                    <div class="border-b border-sui-hairline pb-4">
                        <h3 class="text-sm font-semibold tracking-tight text-ink-900">{{ __('Releases') }}</h3>
                        <p class="mt-1 text-xs leading-normal text-ink-500">
                            {{ __('Each release is one entry in the listing history. Marking it current moves the listing’s version, and buyers download the file attached to the current release.') }}
                        </p>
                    </div>

                    @if ($this->releases->isNotEmpty())
                        <ul class="mt-4 divide-y divide-sui-hairline">
                            @foreach ($this->releases as $release)
                                <li wire:key="release-{{ $release->id }}" class="py-3 first:pt-0 last:pb-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs font-semibold text-ink-900">{{ $release->version }}</span>

                                        @if ($release->is_current)
                                            <flux:badge color="green" size="sm">{{ __('Current') }}</flux:badge>
                                        @endif

                                        <span class="ml-auto text-2xs text-ink-400">{{ $release->released_at->isoFormat('LL') }}</span>
                                    </div>

                                    @if ($release->release_notes)
                                        <p class="mt-1 text-xs leading-relaxed whitespace-pre-line text-ink-500">{{ Str::limit($release->release_notes, 240) }}</p>
                                    @endif

                                    <div class="mt-2 flex flex-wrap items-center gap-3">
                                        @if ($release->hasDeliverable())
                                            <p class="font-mono text-2xs text-ink-400">
                                                {{ $release->deliverableName() }} · {{ $release->humanSize() }}
                                            </p>
                                        @else
                                            <p class="font-mono text-2xs text-ink-400">{{ __('No file attached') }}</p>
                                        @endif

                                        <button
                                            type="button"
                                            wire:click="replaceDeliverable({{ $release->id }})"
                                            class="font-mono text-2xs uppercase tracking-wider text-sui-accent-ink underline decoration-dotted underline-offset-4"
                                            data-test="release-replace-{{ $release->id }}"
                                        >
                                            {{ __('Replace with staged file') }}
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 sui-help">{{ __('No releases yet. A first release is what gives the listing a download.') }}</p>
                    @endif

                    <div class="mt-5 grid gap-4 border-t border-sui-hairline pt-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="space-y-2">
                            <label for="release-version" class="sui-control-label">{{ __('Version') }}</label>
                            <input
                                id="release-version"
                                type="text"
                                wire:model="releaseVersion"
                                placeholder="1.0.0"
                                autocomplete="off"
                                class="sui-control w-full font-mono"
                                data-test="release-version"
                            />
                            @error('releaseVersion')
                                <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="space-y-2">
                            <label for="release-date" class="sui-control-label">{{ __('Released') }}</label>
                            <input
                                id="release-date"
                                type="date"
                                wire:model="releasedAt"
                                class="sui-control w-full"
                                data-test="release-date"
                            />
                            <p class="mt-1 sui-help">{{ __('Blank means today.') }}</p>
                            @error('releasedAt')
                                <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="space-y-2">
                            <span class="sui-control-label">{{ __('Deliverable') }}</span>
                            <input
                                id="release-file"
                                type="file"
                                x-ref="package"
                                wire:model="releaseFile"
                                accept=".zip,.tar,.tar.gz,.tgz,.gz,.7z"
                                class="hidden"
                                aria-label="{{ __('Attach the package buyers download') }}"
                                data-test="release-file-input"
                            />
                            <div class="flex items-center gap-2">
                                <flux:button type="button" size="xs" variant="ghost" icon="paper-clip" x-on:click="$refs.package.click()">
                                    {{ __('Choose file') }}
                                </flux:button>
                                <span wire:loading wire:target="releaseFile" class="font-mono text-2xs uppercase tracking-wider text-ink-400">
                                    {{ __('Uploading…') }}
                                </span>
                            </div>
                            <p class="mt-1 sui-help truncate">
                                {{ $this->stagedPackage?->getClientOriginalName() ?? __('None selected') }}
                            </p>
                            @error('releaseFile')
                                <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-end pb-1">
                            <label class="flex items-center gap-2 text-xs text-ink-600">
                                <input
                                    type="checkbox"
                                    wire:model="releaseIsCurrent"
                                    class="size-4 rounded sui-hairline"
                                    data-test="release-current"
                                />
                                {{ __('Make this the current release') }}
                            </label>
                        </div>

                        <div class="space-y-2 sm:col-span-2 lg:col-span-4">
                            <label for="release-notes" class="sui-control-label">{{ __('Release notes') }}</label>
                            <textarea
                                id="release-notes"
                                wire:model.blur="releaseNotes"
                                rows="3"
                                class="sui-control w-full resize-y"
                                data-test="release-notes"
                            ></textarea>
                            @error('releaseNotes')
                                <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <flux:button
                            type="button"
                            variant="outline"
                            wire:click="publish"
                            wire:loading.attr="disabled"
                            data-test="release-publish"
                        >
                            {{ __('Publish release') }}
                        </flux:button>
                    </div>
                </div>
            @endif

            {{-- ========================================================= --
         | Submission checklist — the rule the action applies, shown early
         --}}
            @if ($this->canSubmit())
                <div class="sui-card p-5">
                    <div class="border-b border-sui-hairline pb-4">
                        <h3 class="text-sm font-semibold tracking-tight text-ink-900">
                            {{ __('Before you submit') }}
                        </h3>
                    </div>

                    @if ($this->submissionGaps === [])
                        <p class="mt-4 flex items-center gap-2 text-sm text-ink-600">
                            <flux:icon name="check" class="size-4 shrink-0 text-sui-success" />
                            {{ __('This listing has everything a reviewer needs.') }}
                        </p>
                    @else
                        <p class="mt-4 text-xs leading-normal text-ink-500">
                            {{ __('A review needs the fields below. Submitting without them is refused.') }}
                        </p>

                        <ul class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($this->submissionGaps as $gap)
                                <li>
                                    <span
                                        data-test="submission-gap"
                                        class="tag-pill border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                                    >
                                        {{ $gap }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            {{-- ========================================================= --
         | Actions
         --}}
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-sui-hairline pt-6">
                @if ($this->canSubmit())
                    <div x-data="{ confirmingDelete: false }" class="mr-auto">
                        <template x-if="! confirmingDelete">
                            <flux:button
                                type="button"
                                variant="ghost"
                                wire:click="$set('confirmingDelete', true)"
                                data-test="product-delete"
                            >
                                {{ __('Delete draft') }}
                            </flux:button>
                        </template>

                        <template x-if="confirmingDelete">
                            <span class="inline-flex items-center gap-2">
                                <flux:button
                                    type="button"
                                    variant="danger"
                                    wire:click="delete"
                                    wire:loading.attr="disabled"
                                    data-test="product-delete-confirm"
                                >
                                    {{ __('Yes, delete it') }}
                                </flux:button>

                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    wire:click="$set('confirmingDelete', false)"
                                >
                                    {{ __('Keep it') }}
                                </flux:button>
                            </span>
                        </template>
                    </div>
                @endif

                <flux:button
                    type="button"
                    variant="ghost"
                    wire:navigate
                    :href="route('seller.products')"
                >
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    :disabled="! $this->isEditable()"
                    data-test="product-save"
                >
                    {{ $creating ? __('Create draft') : __('Save draft') }}
                </flux:button>

                @if ($this->canSubmit())
                    <flux:button
                        type="button"
                        variant="outline"
                        wire:click="submit"
                        wire:loading.attr="disabled"
                        data-test="product-submit"
                    >
                        {{ __('Submit for review') }}
                    </flux:button>
                @endif
            </div>
        </form>
    </x-app.ui.body>
</div>
