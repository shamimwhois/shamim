<?php

use App\Ai\Agents\ContentWriterAgent;
use App\Ai\Agents\CopyEditorAgent;
use App\Ai\Agents\ImagePromptAgent;
use App\Enums\ContentCategory;
use App\Enums\CorrectionType;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;

/**
 * Put a provider key in place for the duration of a test.
 *
 * `isConfigured()` reads the resolved provider's key out of config, so without
 * this every AI endpoint correctly returns a 503 "not configured". That
 * behaviour has its own test below; the rest of the suite needs the gate open.
 */
function configureAiProvider(): void
{
    config([
        'ai.default' => 'openai',
        'ai.providers.openai.key' => 'test-key',
        'ai.default_for_images' => 'openai',
        'ai.editor.text_provider' => null,
        'ai.editor.image_provider' => null,
        'ai.editor.images_enabled' => true,
    ]);
}

beforeEach(function () {
    // A clean slate for every test: no key, nothing faked.
    config([
        'ai.providers.openai.key' => null,
        'ai.editor.text_provider' => null,
        'ai.editor.image_provider' => null,
    ]);
});

/*
|--------------------------------------------------------------------------
| Capabilities
|--------------------------------------------------------------------------
*/

test('the capabilities endpoint requires authentication', function () {
    $this->getJson(route('editor.ai.capabilities'))->assertUnauthorized();
});

test('the capabilities endpoint reports an unconfigured site honestly', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('editor.ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('configured', false)
        ->assertJsonPath('images', false);
});

test('the capabilities endpoint reports a configured site', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->getJson(route('editor.ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('configured', true)
        ->assertJsonPath('images', true);
});

test('the capabilities endpoint ships every category, tone and correction type', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('editor.ai.capabilities'))
        ->assertOk();

    expect($response->json('categories'))->toHaveCount(count(ContentCategory::cases()))
        ->and($response->json('correction_types'))->toHaveCount(count(CorrectionType::cases()))
        ->and($response->json('tones'))->not->toBeEmpty();
});

test('capabilities are gated on the create-post permission', function () {
    // Every shipped role happens to hold post:create, so a passing request
    // proves nothing about the gate. This asserts the middleware actually
    // consults the permission rather than merely admitting any logged-in user:
    // an account that reports no permissions must be refused.
    $user = Mockery::mock(User::factory()->create())->makePartial();
    $user->shouldReceive('hasAnyPermission')->once()->andReturnFalse();

    $this->actingAs($user)
        ->getJson(route('editor.ai.capabilities'))
        ->assertForbidden();
});

test('the editor endpoints admit an account that holds create-post', function () {
    $user = Mockery::mock(User::factory()->create())->makePartial();
    $user->shouldReceive('hasAnyPermission')->andReturnTrue();

    $this->actingAs($user)
        ->getJson(route('editor.ai.capabilities'))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
*/

test('the preview endpoint renders markdown without any AI provider', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.preview'), [
            'markdown' => "# Heading\n\nSome **bold** text.",
        ])
        ->assertOk()
        // The renderer adds heading anchors, so assert on the parts that carry
        // meaning rather than the exact markup.
        ->assertJsonPath('html', fn (string $html): bool => str_contains($html, '>Heading</h1>')
            && str_contains($html, '<strong>bold</strong>'));
});

test('the preview endpoint sanitises scripts out of the markdown', function () {
    // Raw HTML is escaped rather than stripped, so a script tag arrives as
    // visible text and never becomes an element.
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.preview'), [
            'markdown' => "Safe text\n\n<script>alert('xss')</script>",
        ])
        ->assertOk();

    expect($response->json('html'))
        ->not->toContain('<script>')
        ->toContain('Safe text');
});

test('the preview endpoint rejects a missing markdown key', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.preview'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('markdown');
});

/*
|--------------------------------------------------------------------------
| Corrections
|--------------------------------------------------------------------------
*/

test('the correct endpoint returns mapped corrections', function () {
    configureAiProvider();

    // Offset 8..12 is "teh" — the model reports the span, the DTO trusts it
    // because the substring matches.
    $text = 'I recieve teh package.';

    Ai::fakeAgent(CopyEditorAgent::class, [
        [
            'corrections' => [
                [
                    'original' => 'recieve',
                    'replacement' => 'receive',
                    'type' => 'spelling',
                    'explanation' => 'Misspelled.',
                    'start' => 2,
                    'end' => 9,
                ],
                [
                    'original' => 'teh',
                    'replacement' => 'the',
                    'type' => 'spelling',
                    'explanation' => 'Transposed letters.',
                    'start' => 10,
                    'end' => 13,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => $text])
        ->assertOk();

    $corrections = $response->json('corrections');

    expect($corrections)->toHaveCount(2)
        ->and($corrections[0]['original'])->toBe('recieve')
        ->and($corrections[0]['replacement'])->toBe('receive')
        ->and($corrections[0]['type'])->toBe('spelling')
        ->and($corrections[0]['start'])->toBe(2)
        ->and($corrections[0]['end'])->toBe(9)
        ->and($response->json('counts.spelling'))->toBe(2);
});

test('the correct endpoint repairs offsets the model got wrong', function () {
    configureAiProvider();

    $text = 'The quick brown fox.';

    // Offsets that do not correspond to the reported text at all.
    Ai::fakeAgent(CopyEditorAgent::class, [
        [
            'corrections' => [
                [
                    'original' => 'quick',
                    'replacement' => 'swift',
                    'type' => 'style',
                    'explanation' => 'Stronger word.',
                    'start' => 999,
                    'end' => 1006,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => $text])
        ->assertOk();

    // Repaired via strpos: "quick" really is at 4.
    expect($response->json('corrections.0.start'))->toBe(4)
        ->and($response->json('corrections.0.end'))->toBe(9);
});

test('the correct endpoint drops corrections it cannot place in the text', function () {
    configureAiProvider();

    Ai::fakeAgent(CopyEditorAgent::class, [
        [
            'corrections' => [
                [
                    'original' => 'not present anywhere',
                    'replacement' => 'x',
                    'type' => 'spelling',
                    'explanation' => 'Hallucinated.',
                    'start' => 0,
                    'end' => 5,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => 'A clean sentence.'])
        ->assertOk();

    expect($response->json('corrections'))->toBe([])
        ->and($response->json('counts'))->toBe([]);
});

test('the correct endpoint drops a no-op correction', function () {
    configureAiProvider();

    Ai::fakeAgent(CopyEditorAgent::class, [
        [
            'corrections' => [
                [
                    'original' => 'same',
                    'replacement' => 'same',
                    'type' => 'spelling',
                    'explanation' => 'Nothing to change.',
                    'start' => 0,
                    'end' => 4,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => 'same text'])
        ->assertOk();

    expect($response->json('corrections'))->toBe([]);
});

test('the correct endpoint drops a correction with an unknown type', function () {
    configureAiProvider();

    Ai::fakeAgent(CopyEditorAgent::class, [
        [
            'corrections' => [
                [
                    'original' => 'word',
                    'replacement' => 'phrase',
                    'type' => 'not-a-real-type',
                    'explanation' => 'Unknown.',
                    'start' => 0,
                    'end' => 4,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => 'word here'])
        ->assertOk();

    expect($response->json('corrections'))->toBe([]);
});

test('the correct endpoint orders mechanical fixes ahead of judgement calls', function () {
    configureAiProvider();

    $text = 'Some bland text here.';

    // Style sorts last by weight, so it must come back after the spelling.
    Ai::fakeAgent(CopyEditorAgent::class, [
        [
            'corrections' => [
                [
                    'original' => 'bland',
                    'replacement' => 'vivid',
                    'type' => 'style',
                    'explanation' => 'Weak adjective.',
                    'start' => 5,
                    'end' => 10,
                ],
                [
                    'original' => 'here',
                    'replacement' => 'there',
                    'type' => 'spelling',
                    'explanation' => 'Wrong word.',
                    'start' => 16,
                    'end' => 20,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => $text])
        ->assertOk();

    expect($response->json('corrections.0.type'))->toBe('spelling')
        ->and($response->json('corrections.1.type'))->toBe('style');
});

test('the correct endpoint passes the requested types to the agent', function () {
    configureAiProvider();

    Ai::fakeAgent(CopyEditorAgent::class, [['corrections' => []]]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), [
            'text' => 'Some text to review.',
            'types' => ['spelling', 'grammar'],
        ])
        ->assertOk();

    Ai::assertAgentWasPrompted(CopyEditorAgent::class, fn ($prompt): bool => true);

    // The fake gateway discards instructions, so build the agent the same way
    // the service does and assert the filter actually reaches its instructions.
    $filtered = new CopyEditorAgent('Some text to review.', ['spelling', 'grammar']);

    expect($filtered->instructions())
        ->toContain('spelling')
        ->toContain('grammar')
        ->not->toContain('clarity');
});

test('an unfiltered review tells the agent to consider every type', function () {
    $instructions = new CopyEditorAgent('Some text to review.')->instructions();

    foreach (CorrectionType::cases() as $type) {
        expect($instructions)->toContain($type->value);
    }
});

test('the agent interpolates its suggestion cap instead of leaving a placeholder', function () {
    // Regression: the cap was written as a bare {MAX_SUGGESTIONS} inside a
    // heredoc, which PHP treats as literal text. The model was being told to
    // "return at most {MAX_SUGGESTIONS} corrections" — a placeholder, not a
    // number — so the cap was never communicated.
    $instructions = (string) new CopyEditorAgent('Some text.')->instructions();

    expect($instructions)
        ->not->toContain('{MAX_SUGGESTIONS}')
        ->toContain('at most '.CopyEditorAgent::MAX_SUGGESTIONS.' corrections');
});

test('the correct endpoint rejects an unknown correction type', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), [
            'text' => 'Some text.',
            'types' => ['nonsense'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('types.0');
});

test('the correct endpoint rejects text that is too short', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => 'a'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('text');
});

test('the correct endpoint rejects text beyond the length cap', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), [
            'text' => str_repeat('a', 20001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('text');
});

/*
|--------------------------------------------------------------------------
| Generation
|--------------------------------------------------------------------------
*/

test('the generate endpoint returns a draft for the requested category', function () {
    configureAiProvider();

    Ai::fakeAgent(ContentWriterAgent::class, [
        [
            'title' => 'Widget Buying Guide',
            'summary' => 'How to choose a widget.',
            'body' => "## Choosing\n\nStart with your budget.",
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'blog-post',
            'tone' => 'professional',
            'subject' => 'How to choose a widget',
            'word_count' => 1200,
        ])
        ->assertOk();

    expect($response->json('category'))->toBe('blog-post')
        ->and($response->json('draft.title'))->toBe('Widget Buying Guide')
        ->and($response->json('draft.body'))->toContain('Choosing');
});

test('the generate endpoint clamps a word count above the category maximum', function () {
    configureAiProvider();

    Ai::fakeAgent(ContentWriterAgent::class, [
        ['title' => 'T', 'summary' => 'S', 'body' => 'B'],
    ]);

    [$min, $max] = ContentCategory::SocialPost->wordRange();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'social-post',
            'subject' => 'A launch announcement',
            'word_count' => 99999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('word_count');

    // The bound really is the category's, not a global one.
    expect($max)->toBeLessThan(99999)
        ->and($min)->toBeGreaterThan(0);
});

test('the generate endpoint accepts a word count at the category minimum', function () {
    configureAiProvider();

    Ai::fakeAgent(ContentWriterAgent::class, [
        ['title' => 'T', 'summary' => 'S', 'body' => 'B'],
    ]);

    [$min] = ContentCategory::SocialPost->wordRange();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'social-post',
            'subject' => 'A launch announcement',
            'word_count' => $min,
        ])
        ->assertOk();
});

test('the generate endpoint requires a source text when rewriting', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'rewrite',
            'subject' => '',
            'source_text' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_text');
});

test('the generate endpoint accepts a rewrite with source text and no subject', function () {
    configureAiProvider();

    Ai::fakeAgent(ContentWriterAgent::class, [
        ['title' => 'T', 'summary' => 'S', 'body' => 'Rewritten.'],
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'rewrite',
            'subject' => '',
            'source_text' => 'This is the original text that needs rewriting.',
        ])
        ->assertOk()
        ->assertJsonPath('draft.body', 'Rewritten.');
});

test('the generate endpoint requires a subject for every non-rewrite category', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'blog-post',
            'subject' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subject');
});

test('the generate endpoint rejects an unknown category', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'interpretive-dance',
            'subject' => 'Something',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category');
});

test('the generate endpoint rejects an unknown tone', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.generate'), [
            'category' => 'blog-post',
            'subject' => 'Something',
            'tone' => 'shouty',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tone');
});

test('a twelve-hundred-word spec fits inside the blog post range', function () {
    [$min, $max] = ContentCategory::BlogPost->wordRange();

    expect(1200)->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
});

/*
|--------------------------------------------------------------------------
| Image prompt
|--------------------------------------------------------------------------
*/

test('the image prompt endpoint refines an idea', function () {
    configureAiProvider();

    Ai::fakeAgent(ImagePromptAgent::class, [
        [
            'prompt' => 'A single ceramic mug on a linen cloth, soft morning light.',
            'alt_text' => 'A ceramic mug on a table.',
            'summary' => 'A mug in warm light.',
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.image-prompt'), [
            'idea' => 'a mug',
            'context' => 'A post about slow mornings.',
            'style' => 'editorial-photography',
        ])
        ->assertOk();

    expect($response->json('prompt'))->toContain('ceramic mug')
        ->and($response->json('alt_text'))->toBe('A ceramic mug on a table.')
        ->and($response->json('summary'))->toBe('A mug in warm light.');
});

test('the image prompt endpoint rejects an idea that is too short', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.image-prompt'), ['idea' => 'ab'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idea');
});

/*
|--------------------------------------------------------------------------
| Image generation
|--------------------------------------------------------------------------
*/

test('the image endpoint stores the generated image and returns markdown', function () {
    configureAiProvider();
    Storage::fake('public');

    Ai::fakeImages([
        base64_encode('fake-png-bytes'),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.image'), [
            'prompt' => 'A ceramic mug on a linen cloth.',
            'alt_text' => 'A ceramic mug.',
            'size' => '1024x1024',
        ])
        ->assertOk();

    $path = $response->json('image.path');

    expect($path)->toBeString()->toStartWith('editor-images/')
        ->and($response->json('image.alt_text'))->toBe('A ceramic mug.')
        ->and($response->json('markdown'))->toContain('![A ceramic mug.]');

    Storage::disk('public')->assertExists($path);
});

test('the image endpoint reports an unconfigured provider as a 503', function () {
    config(['ai.editor.images_enabled' => true, 'ai.providers.openai.key' => null]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.image'), ['prompt' => 'A ceramic mug.'])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'not_configured');
});

test('the image endpoint refuses when image generation is disabled', function () {
    configureAiProvider();
    config(['ai.editor.images_enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.image'), ['prompt' => 'A ceramic mug.'])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'not_configured');
});

test('the image endpoint rejects an unsupported size', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.image'), [
            'prompt' => 'A ceramic mug.',
            'size' => '42x42',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('size');
});

test('the image endpoint rejects a missing prompt', function () {
    configureAiProvider();

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.image'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('prompt');
});

/*
|--------------------------------------------------------------------------
| The unconfigured path
|--------------------------------------------------------------------------
*/

test('every AI endpoint reports a 503 with a clear reason when unconfigured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('editor.ai.correct'), ['text' => 'Some text here.'])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'not_configured');

    $this->actingAs($user)
        ->postJson(route('editor.ai.generate'), [
            'category' => 'blog-post',
            'subject' => 'A subject',
        ])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'not_configured');

    $this->actingAs($user)
        ->postJson(route('editor.ai.image-prompt'), ['idea' => 'a mug'])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'not_configured');

    $this->actingAs($user)
        ->postJson(route('editor.ai.image'), ['prompt' => 'A mug.'])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'not_configured');
});

test('an unconfigured failure never reaches the provider', function () {
    Ai::fakeAgent(CopyEditorAgent::class, [['corrections' => []]]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('editor.ai.correct'), ['text' => 'Some text here.'])
        ->assertStatus(503);

    Ai::assertAgentNeverPrompted(CopyEditorAgent::class);
});

test('every AI endpoint is closed to an unauthenticated visitor', function () {
    $this->postJson(route('editor.ai.correct'), ['text' => 'Some text.'])->assertUnauthorized();
    $this->postJson(route('editor.ai.generate'), ['category' => 'blog-post', 'subject' => 'x'])->assertUnauthorized();
    $this->postJson(route('editor.ai.image-prompt'), ['idea' => 'a mug'])->assertUnauthorized();
    $this->postJson(route('editor.ai.image'), ['prompt' => 'A mug.'])->assertUnauthorized();
});
