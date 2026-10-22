<?php

namespace App\Support;

/**
 * Per-request SEO metadata for the document head.
 *
 * Why a mutable singleton rather than layout params:
 *
 * Livewire page components resolve their layout from a class-level `#[Layout]`
 * attribute, whose `params` are evaluated before `mount()` runs. Every value
 * this app needs to emit — the post title, its excerpt, its tags — depends on
 * the bound model, so it cannot be known at attribute-evaluation time.
 *
 * Rather than fight that, a page component writes its metadata here during
 * mount and the head partial reads it at render time. The container is rebound
 * per request, and the instance is reset between Livewire requests, so there is
 * no bleed between pages.
 *
 * Everything has a sensible fallback, so a page that never touches this still
 * emits a valid, complete set of head tags.
 */
class Seo
{
    public ?string $title = null;

    public ?string $description = null;

    public ?string $canonical = null;

    public string $type = 'website';

    public ?string $image = null;

    public ?string $publishedAt = null;

    public ?string $modifiedAt = null;

    public ?string $authorName = null;

    /** @var array<int, string> */
    public array $tags = [];

    /**
     * Structured data for the document head, as `@type` shaped arrays.
     *
     * A list rather than one object because a page legitimately has several things to
     * say about itself: the article it is, and the trail of pages that lead to it.
     * `partials/head` encodes the whole list into one `application/ld+json` script with
     * the hex flags that make an unescaped print of a database string safe, and emits
     * nothing at all for a page it has been told not to index.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $jsonLd = [];

    public bool $noindex = false;

    /**
     * Populate from an array, ignoring unknown keys.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    /**
     * The document title, falling back to the site name.
     */
    public function resolvedTitle(): string
    {
        return $this->title ?? config('app.name');
    }

    /**
     * The meta description, falling back to the author tagline.
     */
    public function resolvedDescription(): string
    {
        return $this->description ?? config('portfolio.author.tagline');
    }

    /**
     * The canonical URL, falling back to the current request URL.
     */
    public function resolvedCanonical(): string
    {
        return $this->canonical ?? url()->current();
    }

    /**
     * The share card image, falling back to the site default.
     */
    public function resolvedImage(): string
    {
        return $this->image ?? url('/og-image.png');
    }

    /**
     * The X/Twitter handle of the author, if published.
     */
    public function handle(): ?string
    {
        return config('portfolio.author.links.x.handle');
    }
}
