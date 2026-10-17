<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Documentation preview for the attributed external package example.
 *
 * Same public palette as the catalogue: `ink-*` and `signal-*`, designed for
 * both themes, in the public header's frame.
 */
new #[Title('Media Library for Laravel')] class extends Component
{
    #[Url(as: 'doc', except: 'overview')]
    public string $activeDocument = 'overview';

    /**
     * Restrict this example page to the one package represented in the catalogue.
     */
    public function mount(string $package): void
    {
        abort_unless($package === 'media-library-for-laravel', 404);
        abort_unless(in_array($this->activeDocument, ['overview', 'installation', 'adding-media', 'requirements'], true), 404);
    }

    public function showDocument(string $document): void
    {
        abort_unless(in_array($document, ['overview', 'installation', 'adding-media', 'requirements'], true), 404);

        $this->activeDocument = $document;
    }
}; ?>

@php
    $documents = [
        'overview' => __('Overview'),
        'installation' => __('Installation'),
        'adding-media' => __('Adding media'),
        'requirements' => __('Requirements'),
    ];
@endphp

<div data-test="marketplace-package">
    {{-- ================================================================== --
     | Hero — package title, the two outbound actions, compatibility line
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-14 lg:py-16">
            <nav aria-label="{{ __('Breadcrumb') }}" class="mb-8">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li><a href="{{ route('marketplace.index') }}" wire:navigate class="focus-ring transition-colors hover:text-signal-700 dark:hover:text-signal-400">{{ __('Marketplace') }}</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span class="text-ink-600 dark:text-ink-300" aria-current="page">{{ __('Media Library for Laravel') }}</span></li>
                </ol>
            </nav>

            <div class="flex flex-wrap items-start justify-between gap-6">
                <div class="min-w-0">
                    <p class="font-mono text-2xs font-medium uppercase tracking-widest text-signal-600 dark:text-signal-400">
                        {{ __('Package reference · Roundly Consulting') }}
                    </p>
                    <h1 class="mt-3 text-[clamp(2rem,4.5vw,3rem)] font-semibold leading-tight tracking-tightest text-ink-900 dark:text-ink-50">
                        {{ __('Media Library for Laravel') }}
                    </h1>
                    <p class="mt-3 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ __('A native media library for Eloquent models and ownerless assets, built on Laravel storage.') }}
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    <a
                        href="{{ route('marketplace.index') }}"
                        wire:navigate
                        class="focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border border-ink-300 px-4 py-2 text-sm font-medium text-ink-700 transition-colors hover:border-signal-500 hover:text-signal-700 dark:border-ink-700 dark:text-ink-200 dark:hover:border-signal-600 dark:hover:text-signal-400"
                    >
                        <span aria-hidden="true">&larr;</span>
                        {{ __('All packages') }}
                    </a>
                    <a
                        href="https://roundly-consulting.com/open-source/docs/media-library-for-laravel"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="focus-ring inline-flex min-h-10 items-center gap-2 rounded-full bg-signal-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-signal-700 dark:bg-signal-500 dark:hover:bg-signal-400"
                    >
                        {{ __('Official documentation') }}
                        <span aria-hidden="true">&#8599;</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | Summary + quick install
     --}}
    <section data-reveal class="shell py-12 lg:py-14">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-12">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="tag-pill border-signal-300 bg-signal-50 text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300">{{ __('Content & media') }}</span>
                    <span class="tag-pill">{{ __('External example') }}</span>
                    <span class="tag-pill">{{ __('MIT') }}</span>
                </div>

                <p class="mt-5 max-w-3xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                    {{ __('Attach files to named buckets on any model, or keep shared assets ownerless. Store them on Laravel disks and add image variants, responsive sources, private streaming and content-addressed deduplication when your application needs them.') }}
                </p>

                <ul class="mt-6 flex flex-wrap gap-2" aria-label="{{ __('Package compatibility') }}">
                    @foreach (['PHP ^8.4', 'Laravel ^12 | ^13', 'Composer'] as $requirement)
                        <li class="rounded-full border border-ink-200 bg-white px-3 py-1.5 font-mono text-2xs text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                            {{ $requirement }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <aside class="card-surface self-start p-5 shadow-soft-1" aria-label="{{ __('Quick install') }}">
                <p class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">{{ __('Quick install') }}</p>
                <div x-data="{ copied: false }" class="mt-3 rounded-lg bg-ink-900 px-4 py-4 text-ink-50 dark:border dark:border-ink-800">
                    <code x-ref="command" class="block break-all font-mono text-xs leading-relaxed text-signal-300">composer require roundly-consulting/media-library-for-laravel</code>
                    <button
                        type="button"
                        x-on:click="navigator.clipboard?.writeText($refs.command.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 1600); })"
                        class="focus-ring mt-3 inline-flex min-h-8 items-center rounded px-2 text-xs font-medium text-ink-200 transition hover:bg-white/10 hover:text-white"
                        aria-label="{{ __('Copy Composer install command') }}"
                    >
                        <span x-show="! copied">{{ __('Copy command') }}</span>
                        <span x-show="copied" x-cloak aria-live="polite">{{ __('Copied') }}</span>
                    </button>
                </div>
                <p class="mt-3 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __('Install guidance is summarized from the package maintainer’s documentation.') }}
                </p>
            </aside>
        </div>
    </section>

    {{-- ================================================================== --
     | Documentation
     --}}
    <section data-reveal class="border-t border-ink-200 py-12 dark:border-ink-800 lg:py-14">
        <div class="shell">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-500 dark:text-ink-400">{{ __('Documentation') }}</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                        {{ $documents[$activeDocument] ?? __('Overview') }}
                    </h2>
                </div>
                <a
                    href="https://roundly-consulting.com/open-source/docs/media-library-for-laravel/{{ $activeDocument === 'overview' ? '' : $activeDocument }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-full px-3 font-mono text-2xs text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                >
                    {{ __('Read source page') }}
                    <span aria-hidden="true">&#8599;</span>
                </a>
            </div>

            <div class="mt-8 grid gap-8 lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-12">
                <nav aria-label="{{ __('Package documentation') }}" class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                    <p class="mb-2 hidden font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500 lg:block">{{ __('On this page') }}</p>
                    <ul class="flex gap-2 overflow-x-auto border-b border-ink-200 pb-2 dark:border-ink-800 lg:flex-col lg:gap-1 lg:overflow-visible lg:border-0 lg:p-0">
                        @foreach ($documents as $slug => $label)
                            <li class="shrink-0 lg:w-full">
                                <button
                                    type="button"
                                    wire:click="showDocument('{{ $slug }}')"
                                    @class([
                                        'focus-ring flex min-h-10 w-full items-center rounded-full px-4 py-2 text-left text-sm transition-colors',
                                        'bg-signal-50 font-semibold text-signal-700 dark:bg-signal-950/40 dark:text-signal-300' => $activeDocument === $slug,
                                        'text-ink-600 hover:bg-ink-50 dark:text-ink-300 dark:hover:bg-ink-900' => $activeDocument !== $slug,
                                    ])
                                    @if ($activeDocument === $slug) aria-current="page" @endif
                                >
                                    <span class="min-w-0 truncate">{{ $label }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-5 hidden border-l-2 border-ink-200 pl-3 text-xs leading-relaxed text-ink-500 dark:border-ink-800 dark:text-ink-400 lg:block">
                        <p class="font-medium text-ink-700 dark:text-ink-200">{{ __('Maintained externally') }}</p>
                        <p class="mt-1">{{ __('Roundly Consulting owns the package and its official documentation.') }}</p>
                    </div>
                </nav>

                <article class="min-w-0 max-w-3xl" aria-live="polite">
                    @if ($activeDocument === 'overview')
                        <div class="space-y-7">
                            <p class="text-sm leading-relaxed text-ink-600 dark:text-ink-300 sm:text-base">
                                {{ __('The package keeps media records in one polymorphic table and stores files through Laravel’s configured filesystem. Use named buckets for model-owned uploads, or the facade for global assets such as shared logos and documents.') }}
                            </p>

                            <div class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
                                @foreach ([
                                    ['Buckets & global assets', 'Attach files to named model buckets or keep shared media ownerless.'],
                                    ['Laravel disks', 'Use any configured disk, with separate locations for originals and variants.'],
                                    ['Image variants', 'Generate image-only derivatives with Imagick or GD; queuing is opt-in.'],
                                    ['Responsive images', 'Build srcset helpers and blur-up placeholders for supported image workflows.'],
                                    ['Private delivery', 'Use temporary URLs with native presigning or the signed streaming route.'],
                                    ['Safer storage', 'Content-addressed files and guarded reference counts help protect shared originals.'],
                                ] as [$featureTitle, $featureDescription])
                                    <div class="border-t border-ink-200 pt-4 dark:border-ink-800">
                                        <h3 class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __($featureTitle) }}</h3>
                                        <p class="mt-1.5 text-sm leading-relaxed text-ink-500 dark:text-ink-400">{{ __($featureDescription) }}</p>
                                    </div>
                                @endforeach
                            </div>

                            <aside class="rounded-r-lg border-l-2 border-signal-500 bg-signal-50/70 px-4 py-3 text-sm leading-relaxed text-ink-700 dark:bg-signal-950/30 dark:text-ink-200" role="note">
                                <p class="font-semibold text-ink-900 dark:text-ink-50">{{ __('Package boundary') }}</p>
                                <p class="mt-1">{{ __('This is an attributed external package example. DevFolio does not distribute, license or support it.') }}</p>
                            </aside>
                        </div>
                    @elseif ($activeDocument === 'installation')
                        <div class="space-y-7">
                            <p class="text-sm leading-relaxed text-ink-600 dark:text-ink-300 sm:text-base">
                                {{ __('Install the package with Composer, publish its migration, then run the migration. The package registers its service provider through Laravel package discovery.') }}
                            </p>

                            <section aria-labelledby="install-composer-heading">
                                <h3 id="install-composer-heading" class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('1. Require the package') }}</h3>
                                <div x-data="{ copied: false }" class="mt-3 rounded-lg bg-ink-900 px-4 py-4 text-ink-50 dark:border dark:border-ink-800">
                                    <pre class="overflow-x-auto"><code x-ref="command" class="font-mono text-xs leading-relaxed text-signal-300">composer require roundly-consulting/media-library-for-laravel</code></pre>
                                    <button type="button" x-on:click="navigator.clipboard?.writeText($refs.command.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 1600); })" class="focus-ring mt-3 inline-flex min-h-8 items-center rounded px-2 text-xs font-medium text-ink-200 transition hover:bg-white/10 hover:text-white" aria-label="{{ __('Copy Composer install command') }}">
                                        <span x-show="! copied">{{ __('Copy command') }}</span>
                                        <span x-show="copied" x-cloak aria-live="polite">{{ __('Copied') }}</span>
                                    </button>
                                </div>
                            </section>

                            <section aria-labelledby="install-migration-heading">
                                <h3 id="install-migration-heading" class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('2. Publish and run the migration') }}</h3>
                                <div x-data="{ copied: false }" class="mt-3 rounded-lg bg-ink-900 px-4 py-4 text-ink-50 dark:border dark:border-ink-800">
                                    <pre class="overflow-x-auto"><code x-ref="command" class="font-mono text-xs leading-relaxed text-signal-300">php artisan vendor:publish --tag="media-migrations"
php artisan migrate</code></pre>
                                    <button type="button" x-on:click="navigator.clipboard?.writeText($refs.command.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 1600); })" class="focus-ring mt-3 inline-flex min-h-8 items-center rounded px-2 text-xs font-medium text-ink-200 transition hover:bg-white/10 hover:text-white" aria-label="{{ __('Copy migration commands') }}">
                                        <span x-show="! copied">{{ __('Copy commands') }}</span>
                                        <span x-show="copied" x-cloak aria-live="polite">{{ __('Copied') }}</span>
                                    </button>
                                </div>
                                <p class="mt-2 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ __('The migration is publish-only: publish it before running migrate so the media table is added to your application.') }}
                                </p>
                            </section>

                            <section aria-labelledby="install-options-heading">
                                <h3 id="install-options-heading" class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('Optional published files') }}</h3>
                                <ul class="mt-3 divide-y divide-ink-200 border-y border-ink-200 dark:divide-ink-800 dark:border-ink-800">
                                    <li class="flex flex-wrap justify-between gap-x-6 gap-y-1 py-3 text-sm">
                                        <code class="font-mono text-2xs text-signal-700 dark:text-signal-400">media-config</code>
                                        <span class="text-ink-500 dark:text-ink-400">{{ __('Publish only when you need to override defaults.') }}</span>
                                    </li>
                                    <li class="flex flex-wrap justify-between gap-x-6 gap-y-1 py-3 text-sm">
                                        <code class="font-mono text-2xs text-signal-700 dark:text-signal-400">media-routes</code>
                                        <span class="text-ink-500 dark:text-ink-400">{{ __('Publish only if you want to edit the signed streaming route.') }}</span>
                                    </li>
                                </ul>
                            </section>
                        </div>
                    @elseif ($activeDocument === 'adding-media')
                        <div class="space-y-6">
                            <p class="text-sm leading-relaxed text-ink-600 dark:text-ink-300 sm:text-base">
                                {{ __('Scope additions to an owner with MediaLibrary::for($model). Each add method returns a builder; toBucket() validates and stores the file, then returns its media record.') }}
                            </p>

                            <div x-data="{ copied: false }" class="rounded-lg bg-ink-900 px-4 py-4 text-ink-50 dark:border dark:border-ink-800 sm:px-5 sm:py-5">
                                <div class="mb-3 flex items-center justify-between gap-4 border-b border-white/10 pb-3">
                                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-300">{{ __('PHP · example from maintainer docs') }}</span>
                                    <button type="button" x-on:click="navigator.clipboard?.writeText($refs.example.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 1600); })" class="focus-ring inline-flex min-h-8 shrink-0 items-center rounded px-2 text-xs font-medium text-ink-200 transition hover:bg-white/10 hover:text-white">
                                        <span x-show="! copied">{{ __('Copy') }}</span>
                                        <span x-show="copied" x-cloak aria-live="polite">{{ __('Copied') }}</span>
                                    </button>
                                </div>
                                <pre class="overflow-x-auto"><code x-ref="example" class="font-mono text-xs leading-relaxed text-ink-100">use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;

$media = MediaLibrary::for($user);
$media->add($request->file('avatar'))->toBucket('avatar');
$media->addFromUrl('https://example.com/poster.png')->toBucket('gallery');</code></pre>
                            </div>

                            <h3 class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('Other documented input sources') }}</h3>
                            <ul class="grid gap-2 sm:grid-cols-2">
                                @foreach ([__('Request upload by field name'), __('Remote URL'), __('File on a Laravel disk'), __('String or base64 data'), __('Readable stream'), __('Local path or UploadedFile')] as $source)
                                    <li class="flex items-start gap-2.5 rounded-lg border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                                        <span class="mt-0.5 font-mono text-2xs font-semibold text-signal-600 dark:text-signal-400">+</span>
                                        <span>{{ $source }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <aside class="rounded-r-lg border-l-2 border-amber-400 bg-amber-50/70 px-4 py-3 text-sm leading-relaxed text-amber-900 dark:bg-amber-950/20 dark:text-amber-100" role="note">
                                <p class="font-semibold">{{ __('Validate uploads at the boundary') }}</p>
                                <p class="mt-1">{{ __('The package documentation describes MIME, size and image-dimension validation before storage. Review the upload-safety guide before accepting untrusted files.') }}</p>
                            </aside>
                        </div>
                    @else
                        <div class="space-y-6">
                            <p class="text-sm leading-relaxed text-ink-600 dark:text-ink-300 sm:text-base">
                                {{ __('The package’s published requirements distinguish core storage from optional image processing and URL fetching.') }}
                            </p>

                            <dl class="divide-y divide-ink-200 border-y border-ink-200 dark:divide-ink-800 dark:border-ink-800">
                                <div class="grid gap-1 py-4 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-5">
                                    <dt class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('PHP') }}</dt>
                                    <dd class="font-mono text-xs text-ink-600 dark:text-ink-300">8.4+</dd>
                                </div>
                                <div class="grid gap-1 py-4 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-5">
                                    <dt class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('Laravel') }}</dt>
                                    <dd class="font-mono text-xs text-ink-600 dark:text-ink-300">12 or 13</dd>
                                </div>
                                <div class="grid gap-1 py-4 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-5">
                                    <dt class="text-sm font-semibold text-ink-900 dark:text-ink-50">ext-fileinfo</dt>
                                    <dd class="text-sm leading-relaxed text-ink-600 dark:text-ink-300">{{ __('Required to inspect MIME types from file contents.') }}</dd>
                                </div>
                                <div class="grid gap-1 py-4 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-5">
                                    <dt class="text-sm font-semibold text-ink-900 dark:text-ink-50">ext-imagick or ext-gd</dt>
                                    <dd class="text-sm leading-relaxed text-ink-600 dark:text-ink-300">{{ __('Needed only when generating image variants; basic media storage does not require either.') }}</dd>
                                </div>
                                <div class="grid gap-1 py-4 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-5">
                                    <dt class="text-sm font-semibold text-ink-900 dark:text-ink-50">ext-curl</dt>
                                    <dd class="text-sm leading-relaxed text-ink-600 dark:text-ink-300">{{ __('Used for host-name URL fetching with the package’s private-network guard.') }}</dd>
                                </div>
                                <div class="grid gap-1 py-4 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-5">
                                    <dt class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ __('Package toolkit') }}</dt>
                                    <dd class="font-mono text-xs text-ink-600 dark:text-ink-300">roundly-consulting/package-toolkit-for-laravel <span class="font-sans">{{ __('installed by Composer') }}</span></dd>
                                </div>
                            </dl>

                            <p class="text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                                {{ __('Requirements summarized from the package maintainer’s published requirements page. Verify against the current release before installing.') }}
                            </p>
                        </div>
                    @endif
                </article>
            </div>
        </div>
    </section>

    <div class="border-t border-ink-200 py-8 dark:border-ink-800">
        <div class="shell">
            <aside class="text-xs leading-relaxed text-ink-500 dark:text-ink-400" role="note">
                <span class="font-semibold text-ink-700 dark:text-ink-200">{{ __('External package:') }}</span>
                {{ __('The package name, Composer identifier, compatibility, setup steps and examples belong to Roundly Consulting. This preview is independently presented for design reference; consult the official documentation for current details.') }}
            </aside>
        </div>
    </div>
</div>