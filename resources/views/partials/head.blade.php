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

{{-- ------------------------------------------------------------------ --
 | Title / description
 --}}
<title>{{ $seo->resolvedTitle() }} — {{ config('app.name') }}</title>

<meta name="description" content="{{ $seo->resolvedDescription() }}" />
<meta name="author" content="{{ $seo->authorName ?? config('portfolio.author.name') }}" />
<meta name="color-scheme" content="light dark" />

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
