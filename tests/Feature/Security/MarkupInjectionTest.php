<?php

use App\Models\Comment;
use App\Models\Idea;
use App\Models\Post;
use App\Models\User;
use App\Support\MarkdownRenderer;

/*
|--------------------------------------------------------------------------
| What the sanitiser is actually for
|--------------------------------------------------------------------------
|
| `MarkdownRenderer::render()` is the path by which somebody else's text is
| printed into another person's page, and seven surfaces take it: the blog
| article, the AI Hub article, the idea, the plan, the marketplace listing, the
| work dossier, and the RSS envelope around a post body.
|
| Reading the renderer changes what a test should assert. It is not CommonMark;
| it is this application's own parser, and it escapes the entire text body at
| `MarkdownRenderer::toHtml()` before re-introducing exactly the tags its
| regular expressions built. So a hand-typed `<script>` never reaches
| `sanitize()` as markup at all — it comes out as `&lt;script&gt;`, which is
| correct and which a substring test for "alert(" reads as a failure. The
| sanitiser is the second wall, applied to the tags the parser itself emitted.
|
| Two consequences shape these tests:
|
|   - an escaped payload appearing as visible text is not a vulnerability, so
|     page-level assertions ask the DOM whether any *element* is dangerous
|     rather than asking a string whether a substring exists;
|   - the live attack surface is the link and image forms the parser builds,
|     because those are the only places a caller-supplied value becomes an
|     attribute. That is where the scheme block-list is probed.
|
| The two scanners below therefore answer different questions. `liveThreats()`
| is strict and runs on a renderer fragment, where every node in the document
| came out of the parser, so an `<svg>` or a `<form>` really is a finding.
| `injectionFindings()` runs on a whole page, where the theme legitimately ships
| inline `<svg>` icons and Livewire `<form>` elements, and so asks a narrower
| question: is there an inline event handler, a script-bearing URL, a script or
| style element, or a framing element carrying the payload marker.
|
| A page-level test also has to know which projection it is editing. The Post
| factory derives `body_html` from `body` inside `definition()`, so passing
| `['body' => …]` to `create()` leaves the rendered projection describing a
| different document — which is what `Post::factory()->body(…)` exists to
| prevent. Ideas have no projection and render `content` on the fly.
|
*/

function rendered(string $markdown): string
{
    return app(MarkdownRenderer::class)->render($markdown);
}

function htmlDocument(string $html): DOMDocument
{
    $previous = libxml_use_internal_errors(true);

    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $document;
}

/**
 * Every dangerous *structure* in a document, as human-readable findings.
 *
 * For fragments the renderer produced. Do not point this at a page.
 *
 * @return array<int, string>
 */
function liveThreats(string $html): array
{
    $xpath = new DOMXPath(htmlDocument($html));

    $threats = [];

    foreach ($xpath->query('//*[@*[starts-with(name(), "on")]]') ?: [] as $node) {
        $threats[] = 'an element with an event-handler attribute: <'.$node->nodeName.'>';
    }

    foreach ($xpath->query('//script[.!=""]') ?: [] as $node) {
        $threats[] = 'a script element containing '.substr((string) $node->textContent, 0, 40);
    }

    foreach ($xpath->query('//iframe | //object | //embed | //form | //svg') ?: [] as $node) {
        $threats[] = 'a framing element: <'.$node->nodeName.'>';
    }

    foreach ($xpath->query(scriptBearingUrlsQuery()) ?: [] as $node) {
        $threats[] = 'a script-bearing URL on <'.$node->nodeName.'>';
    }

    return $threats;
}

/**
 * Script-bearing structures inside a region of a rendered page.
 *
 * @return array<int, string>
 */
function injectionFindings(string $html, string $marker): array
{
    $xpath = new DOMXPath(htmlDocument($html));

    $findings = [];

    foreach ($xpath->query('//*[@*[starts-with(name(), "on")]]') ?: [] as $node) {
        $findings[] = 'an inline event handler on <'.$node->nodeName.'>';
    }

    foreach ($xpath->query('//script[.!=""] | //style[.!=""]') ?: [] as $node) {
        $findings[] = 'a <'.$node->nodeName.'> element with inline content';
    }

    foreach ($xpath->query(scriptBearingUrlsQuery()) ?: [] as $node) {
        $findings[] = 'a script-bearing URL on <'.$node->nodeName.'>';
    }

    // Chrome icons and forms are expected on a page; one carrying the payload's
    // own marker string is not, and that is the distinction this makes.
    foreach ($xpath->query('//iframe | //object | //embed | //form | //svg') ?: [] as $node) {
        /** @var DOMElement $node */
        $carriesPayload = str_contains((string) $node->textContent, $marker)
            || collect($node->attributes ?: [])
                ->contains(fn (DOMAttr $attribute) => str_contains($attribute->nodeValue, $marker));

        if ($carriesPayload) {
            $findings[] = 'a framing element carrying the payload: <'.$node->nodeName.'>';
        }
    }

    return $findings;
}

/**
 * XPath matching an attribute holding a URL a browser would execute.
 *
 * Attribute values arrive here entity-decoded by the DOM, which is exactly the
 * single pass a browser makes, so `&#106;avascript:` is already resolved.
 */
function scriptBearingUrlsQuery(): string
{
    $schemes = ['javascript:', 'vbscript:', 'data:text/html', 'data:image/svg', 'data:application'];

    $conditions = [];

    foreach ($schemes as $scheme) {
        $conditions[] = 'contains(@href, '.xpathLiteral($scheme).')';
        $conditions[] = 'contains(@src, '.xpathLiteral($scheme).')';
    }

    return '//*['.implode(' or ', $conditions).']';
}

/**
 * Encode a string for use inside an XPath expression, which has no escaping
 * rule for a double quote and so cannot hold `javascript:` safely otherwise
 * once the value ever grows one.
 */
function xpathLiteral(string $value): string
{
    return str_contains($value, '"')
        ? "'".$value."'"
        : '"'.$value.'"';
}

/**
 * A URL a browser would execute if it resolved this anchor, after the single
 * entity pass the DOM applies to the attribute.
 */
function browserTarget(string $html): ?string
{
    $node = (new DOMXPath(htmlDocument($html)))->query('//a[@href] | //*[@src]')->item(0);

    return $node === null ? null : $node->getAttribute(str_starts_with($node->nodeName, 'a') ? 'href' : 'src');
}

function schemeOf(?string $url): string
{
    return strtolower((string) ($url === null ? '' : parse_url(trim($url), PHP_URL_SCHEME)));
}

/**
 * The scheme a browser resolves for a raw attribute value.
 *
 * Models the URL standard's preprocessing, which is narrower than "strip all
 * control characters": leading and trailing C0 controls and spaces are trimmed,
 * and *only* tab, newline and carriage return are removed from anywhere else.
 * A value whose remaining controls make it unresolvable yields the empty string,
 * which is what an unresolvable href is — not a `javascript:` URL.
 */
function browserSchemeOf(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $trimmed = (string) preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/', '', $decoded);
    $cleaned = (string) preg_replace('/[\x09\x0A\x0D]+/', '', $trimmed);

    if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $cleaned, $matches) !== 1) {
        return '';
    }

    return strtolower(rtrim($matches[0], ':'));
}

/**
 * The subtree of a page that is printed from somebody else's text.
 *
 * A page-level scan that looked at the whole document would report the theme's
 * own inline SVG icons, so each scan is narrowed to the region carrying the
 * author's words.
 *
 * @param  string  $query  XPath selecting the region, relative to the document root
 */
function regionHtml(string $html, string $query): string
{
    $document = htmlDocument($html);

    $nodes = (new DOMXPath($document))->query($query) ?: new DOMNodeList;

    expect($nodes->length)->toBeGreaterThan(0, "no region matched {$query} — the page layout changed and the scan is now vacuous");

    $region = '';

    foreach ($nodes as $node) {
        $region .= (string) $document->saveHTML($node);
    }

    return $region;
}

test('hand-typed markup is escaped rather than stripped, so it prints as the text it is', function (): void {
    $html = rendered("<script>alert('xss')</script>");

    expect($html)
        ->not->toContain('<script')
        ->and($html)->toContain('&lt;script&gt;')
        ->and(liveThreats($html))->toBe([]);
});

test('a style block cannot hide the page because it never becomes a style block', function (): void {
    $html = rendered("<style>body{display:none}</style>\n\nVisible text.");

    expect($html)->not->toContain('<style')
        ->and($html)->toContain('Visible text.')
        ->and(liveThreats($html))->toBe([]);
});

test('an event handler attribute cannot be smuggled onto a permitted tag', function (): void {
    // Here the tag is one the renderer itself built, so the attribute wall is
    // the one under test. `img` is permitted; `onerror` is not one of its
    // attributes, and an unquoted value is not even read as an attribute.
    expect(rendered('<img src="x" onerror="alert(1)">'))
        ->not->toContain('onerror="alert')
        ->and(liveThreats(rendered('<img src="x" onerror="alert(1)">')))->toBe([]);

    expect(rendered('<p onclick="alert(1)">text</p>'))->not->toContain('onclick=');
});

test('the link form the parser builds carries only the attributes it names', function (): void {
    // The parser adds `target`/`rel` for absolute http links and nothing else,
    // so a title segment is not a place an attribute can come from.
    $html = rendered('[x](/gallery)');

    expect($html)->toContain('href="/gallery"')
        ->and($html)->not->toContain('title=');
});

test('a quote in a url cannot close the attribute it is being written into', function (): void {
    $html = rendered('[x](/a"onmouseover="alert(1))');

    expect($html)->not->toContain('onmouseover')
        ->and(liveThreats($html))->toBe([]);
});

test('a javascript url is refused in a link, in any case the author chooses', function (): void {
    foreach (['javascript', 'JaVaScRiPt', 'JAVASCRIPT'] as $scheme) {
        $html = rendered("[x]({$scheme}:alert(1))");

        expect($html)->not->toContain('href', "the [{$scheme}] scheme kept its href")
            ->and(liveThreats($html))->toBe([]);
    }
});

test('a data url is refused, including the svg form that carries a script', function (): void {
    // The base64 form is a real link the parser builds, so the scheme
    // block-list is what stops it: the anchor keeps its text and loses its
    // destination.
    $html = rendered('[x](data:text/html;base64,PHNjcmlwdD4=)');

    expect($html)->not->toContain('href')
        ->and(browserTarget($html))->toBeNull()
        ->and(liveThreats($html))->toBe([]);

    $image = rendered('![hurt](data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=)');

    expect($image)->not->toContain('src=')
        ->and(liveThreats($image))->toBe([]);

    // The inline-svg form is not a link at all — the destination contains
    // whitespace, which the link pattern refuses — so it prints as the text it
    // is. `data:` appearing in that text is not a vulnerability, which is why
    // the assertion is about emitted structure rather than substrings.
    $inline = rendered('[x](data:image/svg+xml,<svg onload=alert(1)>)');

    expect($inline)->not->toContain('href')
        ->and(liveThreats($inline))->toBe([]);
});

test('a vbscript url is refused', function (): void {
    expect(rendered('[x](vbscript:msgbox(1))'))->not->toContain('href');
});

test('a url that is only dangerous because it is entity-encoded never reaches the browser as a scheme', function (): void {
    // This is the case a substring assertion gets wrong. The renderer escapes
    // the whole body before parsing, so `&#106;` arrives at the attribute layer
    // as `&amp;#106;`; the one decode `sanitizeAttributes()` performs therefore
    // yields `&#106;avascript:`, whose characters cannot form a scheme, and the
    // value the browser actually reads is the same string. Browsers do not run a
    // second entity pass on a URL, so the costume never becomes a scheme.
    //
    // The contract is "no emitted attribute resolves to a script-bearing
    // scheme", not "no attribute" — an inert relative link is the correct output
    // here, and asserting otherwise would pin behaviour the renderer does not
    // have. The form that *does* decode to a real scheme is the plain
    // `javascript:` case, tested separately above, and the block-list refuses it.
    $cases = [
        'encoded first letter' => '[x](&#106;avascript:alert(1))',
        'encoded colon' => '[x](javascript&#58;alert(1))',
        'both encoded' => '[x](&#106;avascript&#58;alert(1))',
        'encoded data scheme' => '[x](data&#58;text/html;base64,PHNjcmlwdD4=)',
    ];

    foreach ($cases as $name => $markdown) {
        $html = rendered($markdown);

        expect(schemeOf(browserTarget($html)))
            ->toBe('', "{$name} emitted an attribute the browser would execute")
            ->and(liveThreats($html))->toBe([], "{$name} emitted live markup");
    }
});

test('a scheme broken by whitespace is not a link at all', function (): void {
    // Browsers strip control characters out of a URL before reading its scheme,
    // which is the trick that makes `java` + tab + `script:` look like a
    // non-scheme to a naive check. This renderer does not have to defend against
    // it because its link pattern forbids whitespace inside the destination, so
    // the construct never becomes an attribute. Pinned rather than assumed,
    // because a future link regex that allows whitespace reopens it.
    expect(rendered("[x](<java\tscript:alert(1)>)"))->not->toContain('href')
        ->and(rendered("[x](java\tscript:alert(1))"))->not->toContain('href');
});

test('tab, newline and carriage return are stripped before the scheme is judged', function (): void {
    // These three are the code points the URL standard removes from anywhere in
    // a URL, so they are the ones that genuinely turn a broken-looking value into
    // a live `javascript:` scheme. The link pattern blocks them inside a
    // destination, and this pins that the second wall — the scheme check — would
    // also refuse them if a future pattern admitted them. A value with the break
    // in the *attribute* rather than the markdown is what the scheme check sees.
    foreach (["\t", "\n", "\r"] as $break) {
        $renderer = new ReflectionMethod(MarkdownRenderer::class, 'isUnsafeUrl');

        /** @var MarkdownRenderer $instance */
        $instance = app(MarkdownRenderer::class);

        $brokenScheme = 'java'.$break.'script:alert(1)';

        expect($renderer->invoke($instance, $brokenScheme))
            ->toBeTrue(sprintf('0x%02X did not resolve to an unsafe scheme', ord($break)));
    }
});

test('a scheme broken by a non-whitespace control character is still refused', function (): void {
    // The whitespace case above is closed by the link pattern. This sweeps the
    // rest of the C0 range, which the pattern admits because those code points
    // are not whitespace. The question is deliberately a browser's question and
    // not `parse_url()`'s: the URL standard trims leading/trailing C0 controls
    // and spaces and removes tab, newline and carriage return anywhere, and
    // nothing else. A control a browser leaves in place makes the URL
    // unresolvable rather than turning it into a scheme, so the assertion is
    // that no rendered attribute is a `javascript:` URL *as a browser reads it*.
    foreach (range(0x01, 0x1F) as $point) {
        $break = chr($point);

        foreach ([
            '[x](java'.$break.'script:alert(1))' => 'href',
            '![x](java'.$break.'script:alert(1))' => 'src',
        ] as $markdown => $attribute) {
            $html = rendered($markdown);

            if (preg_match('/'.$attribute.'="([^"]*)"/', $html, $matches) !== 1) {
                continue;
            }

            expect(browserSchemeOf($matches[1]))
                ->not->toBe('javascript', sprintf('0x%02X produced a live %s scheme', $point, $attribute));
        }
    }
});

test('an ordinary url survives, because the wall is a block list and not a refusal to link', function (): void {
    expect(rendered('[the gallery](/dev-lab/gallery)'))
        ->toContain('href="/dev-lab/gallery"');

    expect(rendered('[write](mailto:someone@example.test)'))
        ->toContain('href="mailto:someone@example.test"');
});

test('an ampersand in a query string is encoded exactly once', function (): void {
    // A double-encoded entity is not a security hole, it is a broken link: the
    // browser resolves the href to a URL containing the literal characters
    // `&amp;`, so `?b=1&c=2` arrives as `?b=1&amp;c=2` and the second parameter
    // is gone. This is the regression the value decoding in
    // `sanitizeAttributes()` exists to prevent.
    $html = rendered('[the post](https://example.test/a?b=1&c=2)');

    expect($html)->toContain('href="https://example.test/a?b=1&amp;c=2"')
        ->and($html)->not->toContain('&amp;amp;');
});

test('an external link is given the referrer and opener relationship it claims', function (): void {
    $html = rendered('[the post](https://example.test/a)');

    expect($html)->toContain('rel="noopener noreferrer"')
        ->and($html)->toContain('target="_blank"');

    // A relative link must not be marked external.
    expect(rendered('[the gallery](/dev-lab/gallery)'))->not->toContain('target="_blank"');
});

test('the framing tags are not emitted by the parser and not kept by the sanitiser', function (): void {
    foreach (['iframe', 'object', 'embed', 'form', 'svg'] as $tag) {
        expect(liveThreats(rendered("<{$tag} src=x></{$tag}>text")))->toBe([], "the <{$tag}> survived");
    }
});

test('a conditional comment does not smuggle a script', function (): void {
    // The shape a fuzzer emits. `<!--` is escaped as text by the parser, so the
    // script inside it is never markup.
    expect(liveThreats(rendered('<!--[if IE]><script>alert(1)</script><![endif]-->')))->toBe([]);
});

test('every screen that prints rendered markdown is free of live threats', function (): void {
    $payload = <<<'MD'
        <script>alert("cross-page")</script>

        [click](javascript:alert("cross-page"))

        ![hurt](data:image/svg+xml;base64,PHN2Zz48c2F0ZT48L3N2Zz4=)

        <img src=x onerror=alert("cross-page")>

        <iframe src="javascript:alert('cross-page')"></iframe>

        Plain paragraph that must still render.
        MD;

    $author = User::factory()->create();

    // `body()` rather than `create(['body' => …])`: the factory derives
    // `body_html` in `definition()`, so a raw override would print the default
    // lorem body and the payload would never reach the page at all.
    $post = Post::factory()->published()->body($payload)->create();
    $idea = Idea::factory()->approved()->published()->create([
        'content' => $payload,
        'user_id' => $author->id,
    ]);

    foreach (['/blog/'.$post->slug, '/ideas/'.$idea->slug] as $uri) {
        $body = regionHtml($this->get($uri)->getContent(), '//*[@data-reading-target]');

        expect(injectionFindings($body, 'cross-page'))->toBe([], "{$uri} carried live markup")
            ->and(liveThreats($body))->toBe([], "{$uri} emitted markup the sanitiser should have refused")
            ->and($body)->toContain('Plain paragraph that must still render');
    }
});

test('a comment body is escaped as plain text and never enters the sanitiser', function (): void {
    $post = Post::factory()->published()->create();
    Comment::factory()->for($post)->approved()->create([
        'body' => '<img src=x onerror=alert("payload-marker")>',
    ]);

    $html = $this->get('/blog/'.$post->slug)->getContent();
    $thread = regionHtml($html, '//section[.//h2[@id="comments-heading"]]');

    // The marker is deliberately unlike anything in the page's own copy: the
    // thread section contains the site's real comment `<form>`, and a marker
    // that appeared in its labels would report the form as an injected element.
    expect(injectionFindings($thread, 'payload-marker'))->toBe([])
        ->and($thread)->not->toContain('<img src=x')
        ->and($thread)->toContain('&lt;img src=x');
});

test('the rss envelope does not let a payload close the cdata section early', function (): void {
    // `]]>` ends a CDATA block. A body containing it would otherwise make the
    // author's markup part of the feed's document structure for every reader.
    $post = Post::factory()->published()
        ->body('Lead text]]><script>alert("feed")</script>')
        ->create();

    $xml = $this->get('/feed.xml')->getContent();

    // The document still parses, which is the property `]]>` would break, and
    // every CDATA section is closed by exactly one marker of its own.
    $feed = simplexml_load_string($xml);

    expect($feed)->not->toBeFalse()
        ->and(substr_count($xml, '<![CDATA['))->toBe(substr_count($xml, ']]>'));

    $item = collect($feed->channel->item)
        ->first(fn (SimpleXMLElement $node): bool => str_contains((string) $node->link, (string) $post->slug));

    expect($item)->not->toBeNull('the payload post is missing from the feed');

    $content = (string) $item->children('content', true)->encoded;

    // The stored markdown's `]]>` is gone and its `<` is an entity, so the CDATA
    // section a feed reader opens is the one the template opened.
    expect($content)->toContain('Lead text')
        ->and($content)->toContain('&lt;script&gt;')
        ->and(liveThreats($content))->toBe([])
        ->and(injectionFindings($content, 'feed'))->toBe([]);
});
