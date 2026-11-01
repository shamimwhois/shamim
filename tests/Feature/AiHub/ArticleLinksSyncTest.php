<?php

use App\Actions\AiHub\SyncAiArticleLinks;
use App\Enums\AiLinkKind;
use App\Models\AiArticle;
use App\Models\AiArticleLink;

/*
|--------------------------------------------------------------------------
| Replacing an article's reference links
|--------------------------------------------------------------------------
|
| The links of a hub article live in their own table so a dead reference can be
| found and fixed without rewriting prose, and the only way that stays true is
| that a save replaces the set wholesale. Three things can go wrong in a
| wholesale replace, and all three are pinned here: a row that should not have
| survived does, a submitted row is silently dropped, and — the one that
| reaches past the article being edited — a submitted id belonging to somebody
| else's article is treated as an update.
|
*/

function sync(AiArticle $article, array $links): void
{
    app(SyncAiArticleLinks::class)->handle($article, $links);
}

it('writes the links it is given, numbered in the order they were submitted', function (): void {
    $article = AiArticle::factory()->create();

    sync($article, [
        ['url' => 'https://laravel.com/docs', 'label' => 'Laravel docs', 'kind' => 'documentation'],
        ['url' => 'https://github.com/laravel/framework', 'label' => 'Framework repo', 'kind' => 'repository'],
    ]);

    expect($article->fresh()->links()->orderBy('sort_order')->pluck('label')->all())
        ->toBe(['Laravel docs', 'Framework repo'])
        ->and($article->fresh()->links()->orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1])
        ->and($article->fresh()->links()->firstWhere('label', 'Laravel docs')->kind)->toBe(AiLinkKind::Documentation);
});

it('drops a url that is not a url instead of storing it', function (): void {
    $article = AiArticle::factory()->create();

    sync($article, [
        ['url' => '', 'label' => 'Blank'],
        ['url' => '   ', 'label' => 'Spaces'],
        ['url' => 'not-a-url', 'label' => 'No scheme'],
        ['url' => 'javascript:alert(1)', 'label' => 'Script url'],
        ['url' => 'https://example.com/real', 'label' => 'The one that counts'],
    ]);

    expect($article->fresh()->links()->count())->toBe(1)
        // The numbering survives the drop: the reader sees one ordered list, not a
        // list whose first row is numbered 4.
        ->and($article->fresh()->links()->first()->sort_order)->toBe(0)
        ->and($article->fresh()->links()->first()->url)->toBe('https://example.com/real');
});

it('names a link after its host when the operator left the label empty', function (): void {
    $article = AiArticle::factory()->create();

    sync($article, [
        ['url' => 'https://docs.example.org/guide', 'label' => ''],
        ['url' => 'https://news.example.com', 'label' => '   '],
    ]);

    expect($article->fresh()->links()->orderBy('sort_order')->pluck('label')->all())
        ->toBe(['docs.example.org', 'news.example.com']);
});

it('deletes the rows a save left out rather than leaving them orphaned', function (): void {
    $article = AiArticle::factory()->create();

    sync($article, [
        ['url' => 'https://one.example.com', 'label' => 'One'],
        ['url' => 'https://two.example.com', 'label' => 'Two'],
    ]);

    $kept = $article->fresh()->links()->get();

    /** @var AiArticleLink $survivor */
    $survivor = $kept->firstWhere('url', 'https://two.example.com');

    sync($article, [
        ['id' => $survivor->id, 'url' => 'https://two.example.com/changed', 'label' => 'Two, renamed'],
    ]);

    expect($article->fresh()->links()->count())->toBe(1)
        ->and(AiArticleLink::query()->whereKey($kept->firstWhere('url', 'https://one.example.com')->id)->exists())->toBeFalse()
        ->and($article->fresh()->links()->first()->url)->toBe('https://two.example.com/changed');
});

it('updates a link it recognises instead of adding a second row for it', function (): void {
    $article = AiArticle::factory()->create();
    sync($article, [['url' => 'https://example.com/a', 'label' => 'A']]);

    $id = $article->fresh()->links()->first()->id;

    sync($article, [['id' => $id, 'url' => 'https://example.com/a', 'label' => 'A, relabelled']]);

    expect($article->fresh()->links()->count())->toBe(1)
        ->and($article->fresh()->links()->first()->id)->toBe($id)
        ->and($article->fresh()->links()->first()->label)->toBe('A, relabelled');
});

it('will not edit another article’s link because the submitted id said so', function (): void {
    $article = AiArticle::factory()->create();
    $other = AiArticle::factory()->create();
    sync($other, [['url' => 'https://example.com/mine', 'label' => 'Not yours']]);

    $theirs = $other->fresh()->links()->first();

    sync($article, [['id' => $theirs->id, 'url' => 'https://example.com/mine', 'label' => 'Now it is']]);

    // The id is ignored rather than honoured: the row that appears belongs to the
    // article being saved, and the one it tried to reach is untouched.
    expect($article->fresh()->links()->count())->toBe(1)
        ->and($article->fresh()->links()->first()->id)->not->toBe($theirs->id)
        ->and($theirs->fresh()->ai_article_id)->toBe($other->id)
        ->and($theirs->fresh()->label)->toBe('Not yours');
});

it('coerces a kind nobody recognised to a plain reference', function (): void {
    $article = AiArticle::factory()->create();

    sync($article, [
        ['url' => 'https://example.com/1', 'kind' => 'paper'],
        ['url' => 'https://example.com/2', 'kind' => 'whatever'],
        ['url' => 'https://example.com/3', 'kind' => null],
        ['url' => 'https://example.com/4', 'kind' => AiLinkKind::Demo],
    ]);

    expect($article->fresh()->links()->orderBy('sort_order')->pluck('kind')->all())
        ->toBe([AiLinkKind::Paper, AiLinkKind::Reference, AiLinkKind::Reference, AiLinkKind::Demo]);
});

it('stores a note only when there is one, and clears it when the field is emptied', function (): void {
    $article = AiArticle::factory()->create();
    sync($article, [['url' => 'https://example.com/1', 'note' => 'Read the benchmark section.']]);

    $id = $article->fresh()->links()->first()->id;
    expect($article->fresh()->links()->first()->note)->toBe('Read the benchmark section.');

    sync($article, [['id' => $id, 'url' => 'https://example.com/1', 'note' => '   ']]);

    expect($article->fresh()->links()->first()->note)->toBeNull();
});

it('deletes every link when the article is saved with none left', function (): void {
    $article = AiArticle::factory()->create();
    sync($article, [['url' => 'https://example.com/1'], ['url' => 'https://example.com/2']]);

    expect($article->fresh()->links()->count())->toBe(2);

    sync($article, []);

    expect($article->fresh()->links()->count())->toBe(0)
        ->and(AiArticleLink::query()->count())->toBe(0);
});
