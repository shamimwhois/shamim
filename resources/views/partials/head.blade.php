@php
    /**
     * Page metadata.
     *
     * Values come from the scoped Seo instance, which page components populate
     * during mount(). Every field has a fallback so a page that declares nothing
     * still emits a complete, valid set of head tags.
     */
    $seo = app(App\Support\Seo::class);
@endphp

<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

{{--
    CSRF token for fetch-based requests.

    Livewire injects its own token into its script tag, but the editor's AI
    calls are plain `fetch()` POSTs, which do not go through Livewire and so
    need a token they can read. Without this they are rejected with a 419.
--}}
<meta name="csrf-token" content="{{ csrf_token() }}" />


{{-- ------------------------------------------------------------------ --
 | Title / description
 --}}
<title>{{ $seo->resolvedTitle() }} — {{ config('app.name') }}</title>

<meta name="description" content="{{ $seo->resolvedDescription() }}" />
<meta name="author" content="{{ $seo->authorName ?? config('portfolio.author.name') }}" />
<meta name="color-scheme" content="light dark" />

{{--
    Browser chrome (mobile address bar, task switcher) follows the applied
    theme. Dark is the product default, so the static value is the dark
    canvas; `partials/theme-boot` and the theme toggle keep it in sync with
    the applied theme afterwards.
--}}
<meta name="theme-color" content="#0d0d0c" />

{{-- ------------------------------------------------------------------ --
 | Canonical + robots
 --}}
<link rel="canonical" href="{{ $seo->resolvedCanonical() }}" />

@if ($seo->noindex)
    <meta name="robots" content="noindex, nofollow" />
@endif

{{-- ------------------------------------------------------------------ --
 | Open Graph — the social sharing contract (docs/APP-FLOW.md §5)
 --}}
<meta property="og:site_name" content="{{ config('app.name') }}" />
<meta property="og:type" content="{{ $seo->type }}" />
<meta property="og:title" content="{{ $seo->resolvedTitle() }}" />
<meta property="og:description" content="{{ $seo->resolvedDescription() }}" />
<meta property="og:url" content="{{ $seo->resolvedCanonical() }}" />
<meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}" />
<meta property="og:image" content="{{ $seo->resolvedImage() }}" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />

@if ($seo->publishedAt)
    <meta property="article:published_time" content="{{ $seo->publishedAt }}" />
@endif

@if ($seo->modifiedAt)
    <meta property="article:modified_time" content="{{ $seo->modifiedAt }}" />
@endif

@if ($seo->authorName)
    <meta property="article:author" content="{{ $seo->authorName }}" />
@endif

@foreach ($seo->tags as $tag)
    <meta property="article:tag" content="{{ $tag }}" />
@endforeach

{{-- ------------------------------------------------------------------ --
 | Twitter / X card
 --}}
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="{{ $seo->resolvedTitle() }}" />
<meta name="twitter:description" content="{{ $seo->resolvedDescription() }}" />
<meta name="twitter:image" content="{{ $seo->resolvedImage() }}" />

@if ($handle = $seo->handle())
    <meta name="twitter:creator" content="{{ $handle }}" />
    <meta name="twitter:site" content="{{ $handle }}" />
@endif

{{-- ------------------------------------------------------------------ --
 | Structured data
 |
 | The one unescaped print of dynamic content in the head, and the flags are
 | what make it safe rather than bold: JSON_HEX_TAG turns `<` and `>` into
 | `\u003C` and `\u003E`, so a title containing `</script>` cannot close the
 | element it is being written inside, and the other three do the same for the
 | quote and ampersand forms a database string is full of. The value arrives as
 | a PHP array built by the page component and is encoded here — nothing in this
 | line is interpolated from markup.
 |
 | A `noindex` page emits none. Structured data is a claim to a search engine
 | about a document it is being told not to list, and the workspace screens are
 | exactly that.
 --}}
@if ($seo->jsonLd !== [] && ! $seo->noindex)
    <script type="application/ld+json">{!! json_encode($seo->jsonLd, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
@endif

{{-- ------------------------------------------------------------------ --
 | Feeds + icons
 --}}
@unless ($seo->noindex)
    <link rel="alternate" type="application/rss+xml" title="{{ config('app.name') }} — Writing" href="{{ url('/feed.xml') }}" />
@endunless

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
@include('partials.theme-boot')
