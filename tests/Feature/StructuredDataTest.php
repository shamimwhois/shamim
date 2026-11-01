<?php

use App\Enums\LabLanguage;
use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Structured data in the document head
|--------------------------------------------------------------------------
|
| Two pages now describe themselves to a machine: a published idea as an `Article`,
| and a published snippet as `SoftwareSourceCode`. That is the one place in this
| application where a string from the database is printed into the head without
| Blade's escaping, because a JSON literal inside a `<script>` element cannot be
| HTML-escaped and stay parseable.
|
| So the tests aim at the escaping before they aim at the shape. A title containing
| `</script>` has to arrive as data — one script element, valid JSON, and the
| characters back out again — rather than as a closed tag with the rest of the
| document inside it. The shape assertions then pin the claims the pages are allowed
| to make: a person with a name and no email address, a runtime only for the
| languages a browser actually executes, and nothing at all for a page that has been
| told not to index itself.
|
*/

/**
 * Every node the response printed, whatever number of script elements carried them.
 *
 * The head prints one `<script type="application/ld+json">` per page and that element
 * holds the whole graph as a list, so a page with two things to say about itself
 * arrives as a single block containing two nodes. Flattening here is what lets the
 * assertions below be written against nodes rather than against the packaging.
 *
 * @return list<array<string, mixed>>
 */
function ld_json(string $html): array
{
    preg_match_all(
        '/<script type="application\/ld\+json">(.*?)<\/script>/s',
        $html,
        $matches,
    );

    $nodes = [];

    foreach ($matches[1] as $raw) {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            continue;
        }

        // A list of nodes rather than one node: the first key is `0`, not `@type`.
        if (array_is_list($decoded)) {
            foreach ($decoded as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }

            continue;
        }

        $nodes[] = $decoded;
    }

    return $nodes;
}

/**
 * The first decoded block of a given `@type`.
 *
 * @return array<string, mixed>|null
 */
function ld_type(array $blocks, string $type): ?array
{
    foreach ($blocks as $block) {
        if (($block['@type'] ?? null) === $type) {
            return $block;
        }
    }

    return null;
}

test('a published idea describes itself as an article', function () {
    $idea = Idea::factory()->published()->create([
        'title' => 'Why the frame has no origin',
        'content' => str_repeat('A sentence worth reading. ', 40),
    ]);

    $blocks = ld_json($this->get(route('ideas.show', ['idea' => $idea->slug]))->assertOk()->getContent());

    expect($blocks)->toHaveCount(2);

    $article = ld_type($blocks, 'Article');

    expect($article)->not->toBeNull()
        ->and($article['@context'])->toBe('https://schema.org')
        ->and($article['headline'])->toBe('Why the frame has no origin')
        ->and($article['datePublished'])->toBe($idea->published_at->toIso8601String())
        ->and($article['mainEntityOfPage']['@id'])->toBe(route('ideas.show', ['idea' => $idea->slug]))
        ->and($article['wordCount'])->toBeGreaterThan(0)
        ->and($article['timeRequired'])->toStartWith('PT')
        ->and($article['author']['name'])->toBe($idea->user->name);
});

test('the author in the metadata is a name and never an address', function () {
    $idea = Idea::factory()->published()->create();

    $html = $this->get(route('ideas.show', ['idea' => $idea->slug]))->assertOk()->getContent();
    $article = ld_type(ld_json($html), 'Article');

    expect($article['author'])->not->toHaveKey('email')
        ->and($article['author'])->not->toHaveKey('address')
        ->and($html)->not->toContain($idea->user->email);
});

test('the topic names an idea is filed under become its keywords', function () {
    $forms = Topic::factory()->create(['name' => 'Forms']);
    $a11y = Topic::factory()->create(['name' => 'Accessibility']);

    $idea = Idea::factory()->published()->withTopics([$forms->id, $a11y->id])->create();

    $article = ld_type(ld_json($this->get(route('ideas.show', ['idea' => $idea->slug]))->getContent()), 'Article');

    expect($article['keywords'])->toBe('Forms, Accessibility');
});

test('an idea filed under nothing claims no keywords', function () {
    $idea = Idea::factory()->published()->create();

    $article = ld_type(ld_json($this->get(route('ideas.show', ['idea' => $idea->slug]))->getContent()), 'Article');

    expect($article)->not->toHaveKey('keywords');
});

test('an idea walks a crawler back through the ideas index', function () {
    $idea = Idea::factory()->published()->create(['title' => 'A trail worth following']);

    $trail = ld_type(ld_json($this->get(route('ideas.show', ['idea' => $idea->slug]))->getContent()), 'BreadcrumbList');

    expect($trail['itemListElement'])->toHaveCount(3)
        ->and(array_column($trail['itemListElement'], 'position'))->toBe([1, 2, 3])
        ->and(array_column($trail['itemListElement'], 'name'))->toBe(['Home', 'Ideas', 'A trail worth following']);
});

test('a published snippet describes itself as source code', function () {
    $snippet = LabSnippet::factory()->published()->language(LabLanguage::Javascript)->create([
        'title' => 'Debounce a callback',
        'description' => 'Wait for the typing to stop before the search runs.',
    ]);

    $blocks = ld_json($this->actingAs(User::factory()->create())
        ->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->getContent());

    expect($blocks)->toHaveCount(2);

    $code = ld_type($blocks, 'SoftwareSourceCode');

    expect($code)->not->toBeNull()
        ->and($code['name'])->toBe('Debounce a callback')
        ->and($code['codeLanguage'])->toBe('JavaScript')
        ->and($code['programmingLanguage'])->toBe('JavaScript')
        ->and($code['codeText'])->toBe($snippet->code)
        ->and($code['lines'])->toBe($snippet->lineCount())
        ->and($code['runtime'])->toBe('Browser')
        ->and($code['description'])->toBe('Wait for the typing to stop before the search runs.')
        ->and($code['author'])->not->toHaveKey('email')
        ->and($code['isPartOf']['@id'])->toBe(route('dev-lab.gallery'));
});

test('a php snippet claims no runtime it does not have', function () {
    $snippet = LabSnippet::factory()->published()->php()->create();

    $code = ld_type(ld_json($this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->getContent()), 'SoftwareSourceCode');

    expect($code)->not->toHaveKey('runtime')
        ->and($code)->not->toHaveKey('license')
        ->and($code['codeLanguage'])->toBe('PHP')
        // The source is still the source: storing it honestly is the whole claim.
        ->and($code['codeText'])->toBe($snippet->code);
});

test('a snippet without a description does not invent one', function () {
    $snippet = LabSnippet::factory()->published()->create(['description' => null]);

    $code = ld_type(ld_json($this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->getContent()), 'SoftwareSourceCode');

    expect($code)->not->toHaveKey('description');
});

test('a title containing a closing script tag arrives as data rather than as markup', function () {
    $hostile = '</script><script>alert(1)</script>';

    // The slug is written rather than derived, because the thing being tested is a
    // title that would slugify to a URL nobody could read.
    $idea = Idea::factory()->published()->create([
        'title' => $hostile,
        'slug' => 'a-title-that-closes-a-tag',
    ]);

    $html = $this->get(route('ideas.show', ['idea' => 'a-title-that-closes-a-tag']))->assertOk()->getContent();

    // One element, printed once, and the hostile characters survived as a value
    // instead of closing the tag they were written inside.
    expect(substr_count($html, '<script type="application/ld+json">'))->toBe(1)
        ->and($html)->not->toContain($hostile);

    $article = ld_type(ld_json($html), 'Article');

    expect($article)->not->toBeNull()
        ->and($article['headline'])->toBe($hostile);

    $snippet = LabSnippet::factory()->published()->create([
        'title' => $hostile,
        'slug' => 'a-snippet-that-closes-a-tag',
    ]);

    $html = $this->get(route('dev-lab.snippets.show', ['snippet' => 'a-snippet-that-closes-a-tag']))->assertOk()->getContent();

    expect(substr_count($html, '<script type="application/ld+json">'))->toBe(1)
        ->and(ld_type(ld_json($html), 'SoftwareSourceCode')['name'])->toBe($hostile);
});

test('code containing angle brackets stays parseable in the json block', function () {
    $snippet = LabSnippet::factory()->published()->language(LabLanguage::Html)->create([
        'code' => "<section>\n  <h2>Escaped?</h2>\n</section>",
    ]);

    $html = $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->getContent();

    expect($html)->toContain('\\u003Csection\\u003E')
        ->and(ld_type(ld_json($html), 'SoftwareSourceCode')['codeText'])
        ->toBe("<section>\n  <h2>Escaped?</h2>\n</section>");
});

test('a draft read by its author emits no structured data', function () {
    $author = User::factory()->create();
    $idea = Idea::factory()->create(['user_id' => $author->id]);

    $this->actingAs($author)
        ->get(route('ideas.show', ['idea' => $idea->slug]))
        ->assertOk()
        ->assertDontSee('application/ld+json', false)
        ->assertSee('<meta name="robots" content="noindex', false);
});

test('a private snippet read by its author emits no structured data', function () {
    $author = User::factory()->create();
    $snippet = LabSnippet::factory()->create(['user_id' => $author->id]);

    $this->actingAs($author)
        ->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertDontSee('application/ld+json', false);
});

test('the screens nobody is meant to index emit none at all', function () {
    $reader = User::factory()->create();

    $this->actingAs($reader)->get('/dev-lab/sandbox')->assertOk()->assertDontSee('application/ld+json', false);
    $this->actingAs($reader)->get('/dev-lab/snippets')->assertOk()->assertDontSee('application/ld+json', false);
    $this->actingAs($reader)->get('/ideas')->assertOk()->assertDontSee('application/ld+json', false);
    $this->get('/dev-lab/gallery')->assertOk()->assertDontSee('application/ld+json', false);
});
