@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    {{-- Static pages --}}
    <url>
        <loc>{{ route('home') }}</loc>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ route('blog.index') }}</loc>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    <url>
        <loc>{{ route('about') }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>

    {{-- Posts --}}
    @foreach ($posts as $post)
        <url>
            <loc>{{ route('blog.show', $post) }}</loc>
            <lastmod>{{ $post->updated_at->toAtomString() }}</lastmod>
            <changefreq>monthly</changefreq>
            <priority>{{ $post->is_featured ? '0.9' : '0.8' }}</priority>
        </url>
    @endforeach

    {{-- Category filters --}}
    @foreach ($categories as $category)
        <url>
            <loc>{{ route('blog.index', ['category' => $category->slug]) }}</loc>
            <changefreq>weekly</changefreq>
            <priority>0.5</priority>
        </url>
    @endforeach

    {{-- Tag filters --}}
    @foreach ($tags as $tag)
        <url>
            <loc>{{ route('blog.index', ['tag' => $tag->slug]) }}</loc>
            <changefreq>weekly</changefreq>
            <priority>0.3</priority>
        </url>
    @endforeach
</urlset>
