<?php

use App\Actions\AiHub\SyncAiPublicationStatus;
use App\Enums\AiPublicationStatus;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Models\User;
use Database\Seeders\AiHubSeeder;

/*
|--------------------------------------------------------------------------
| Seeder as mock-data validation
|--------------------------------------------------------------------------
|
| AiHubSeeder is the demo corpus the hub ships with, so its promises are
| part of the product: the taxonomy has the shape the landing page expects,
| every derived article projection agrees with its source body, and the
| publication invariants hold for the seeded paths. These tests seed once
| and assert against the data exactly as a visitor would see it.
|
*/

beforeEach(function () {
    // The seeder adopts the first existing user as the author, so a user
    // must exist before the seed runs.
    User::factory()->create();

    $this->seed(AiHubSeeder::class);
});

test('the seeder builds the taxonomy with one nested child and one retired root', function () {
    expect(AiTopic::count())->toBe(6);

    $child = AiTopic::where('slug', 'prompt-patterns')->first();
    $parent = AiTopic::where('slug', 'prompt-engineering')->first();

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->is_active)->toBeTrue()
        ->and($parent->is_active)->toBeTrue();

    $retired = AiTopic::where('slug', 'hardware-notes')->first();
    expect($retired->is_active)->toBeFalse()
        ->and(AiTopic::query()->active()->count())->toBe(5);
});

test('the seeder files articles across the three publication states', function () {
    expect(AiArticle::count())->toBe(7)
        ->and(AiArticle::query()->published()->count())->toBe(5);

    expect(AiArticle::where('slug', 'measuring-retrieval-quality-without-a-benchmark')->firstOrFail()->status)
        ->toBe(AiPublicationStatus::Review)
        ->and(AiArticle::where('slug', 'case-study-over-prompting')->firstOrFail()->status)
        ->toBe(AiPublicationStatus::Draft);
});

test('every seeded article keeps its derived projections consistent', function () {
    AiArticle::query()->each(function (AiArticle $article): void {
        expect($article->body_html)->toStartWith('<h2')
            ->and($article->word_count)->toBe(str_word_count($article->body_text))
            ->and($article->reading_time)->toBe(max(1, (int) ceil($article->word_count / 200)));
    });
});

test('published seeded articles carry a back-dated publication stamp', function () {
    $spotlight = AiArticle::where('slug', 'how-to-read-this-hub')->firstOrFail();

    expect($spotlight->published_at)->not->toBeNull()
        ->and($spotlight->published_at->lessThan(now()->subDays(30)))->toBeTrue()
        ->and(AiArticle::where('slug', 'case-study-over-prompting')->firstOrFail()->published_at)
        ->toBeNull();
});

test('the seeder sequences two paths and only the ready one publishes', function () {
    expect(AiLearningPath::count())->toBe(2);

    $published = AiLearningPath::where('slug', 'your-first-week-in-the-hub')->firstOrFail();
    $draft = AiLearningPath::where('slug', 'evaluating-models-you-actually-ship')->firstOrFail();

    expect($published->status)->toBe(AiPublicationStatus::Published)
        ->and($published->items()->count())->toBe(3)
        ->and($published->items->every(
            fn ($item): bool => $item->article->status === AiPublicationStatus::Published
        ))->toBeTrue()
        ->and($draft->status)->toBe(AiPublicationStatus::Draft)
        ->and($draft->items()->count())->toBe(2);
});

test('the draft path cannot be published while a lesson is unpublished', function () {
    $draft = AiLearningPath::where('slug', 'evaluating-models-you-actually-ship')->firstOrFail();

    expect(fn () => app(SyncAiPublicationStatus::class)->handle($draft, AiPublicationStatus::Published))
        ->toThrow(InvalidArgumentException::class, 'Every lesson in a learning path must be published before the path can be.');
});

test('the seeder fills the resource shelf with published, draft, and retired rows', function () {
    expect(AiResource::count())->toBe(6)
        ->and(AiResource::query()->published()->count())->toBe(4);

    expect(AiResource::where('title', 'Anthropic Prompt Library')->firstOrFail()->status)
        ->toBe(AiPublicationStatus::Draft)
        ->and(AiResource::where('title', 'Deprecated: Free-tier GPU Notebook Roundup')->firstOrFail()->status)
        ->toBe(AiPublicationStatus::Archived);
});

test('the seeder wires the curated related-article rail', function () {
    $spotlight = AiArticle::where('slug', 'how-to-read-this-hub')->firstOrFail();

    expect($spotlight->relatedArticles->pluck('slug')->all())
        ->toBe(['writing-prompts-that-survive-review']);
});

test('a guest reads the seeded public surfaces at their seeded visibility', function () {
    $this->get('/ai-hub')->assertOk()->assertSee('How to Read This Hub');

    // Draft and review writing stays off the public catalogue.
    $this->get('/ai-hub/articles/case-study-over-prompting')->assertNotFound();
    $this->get('/ai-hub/articles/measuring-retrieval-quality-without-a-benchmark')->assertNotFound();
    $this->get('/ai-hub/articles/how-to-read-this-hub')->assertOk();

    // The retired topic keeps its rows but loses its page.
    $this->get('/ai-hub/topics/hardware-notes')->assertNotFound();
    $this->get('/ai-hub/topics/getting-started')->assertOk();

    // The draft path is invisible; the published path shows its lessons.
    $this->get('/ai-hub/paths/evaluating-models-you-actually-ship')->assertNotFound();
    $this->get('/ai-hub/paths/your-first-week-in-the-hub')
        ->assertOk()
        ->assertSee('How to Read This Hub');

    // The retired resource is off the shelf.
    $this->get('/ai-hub/resources')->assertOk()->assertDontSee('Free-tier GPU Notebook Roundup');
});
