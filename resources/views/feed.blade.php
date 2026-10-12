{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:dc="http://purl.org/dc/elements/1.1/">
    <channel>
        <title>{{ config('app.name') }} — Writing</title>
        <link>{{ route('blog.index') }}</link>
        <description>{{ config('portfolio.author.tagline') }}</description>
        <language>{{ str_replace('_', '-', app()->getLocale()) }}</language>
        <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>

        <atom:link href="{{ route('feed') }}" rel="self" type="application/rss+xml" />

        @foreach ($posts as $post)
            <item>
                <title>{{ $post->title }}</title>
                <link>{{ route('blog.show', $post) }}</link>
                <guid isPermaLink="true">{{ route('blog.show', $post) }}</guid>
                <pubDate>{{ $post->published_at->toRfc2822String() }}</pubDate>
                <dc:creator>{{ $post->user->name }}</dc:creator>

                @if ($post->category)
                    <category>{{ $post->category->name }}</category>
                @endif

                @foreach ($post->tags as $tag)
                    <category>{{ $tag->name }}</category>
                @endforeach

                <description><![CDATA[{{ $post->excerpt }}]]></description>
                <content:encoded><![CDATA[{!! $post->body_html !!}]]></content:encoded>
            </item>
        @endforeach
    </channel>
</rss>
