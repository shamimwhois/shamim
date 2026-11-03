<?php

use App\Enums\AiLinkKind;
use App\Enums\CommunityKind;
use App\Enums\CommunityRole;
use App\Models\AiArticle;
use App\Models\AiArticleLink;
use App\Models\AiLearningPath;
use App\Models\AiLearningPathItem;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\Post;
use App\Models\PostTag;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| The six factories that did not exist
|--------------------------------------------------------------------------
|
| A factory nobody runs is a factory that is broken, usually in the way that
| matters least at the moment it is written and most later: a column that is
| not null without a default, a state that describes a row the application
| would never write. These tests exist to make `P6`/`P7`'s dependencies real
| rather than plausible, so they assert the row that lands in the database and
| the one or two invariants each model is responsible for — not the factory's
| own arithmetic.
|
*/

test('the article link factory writes a link whose host the model can read', function (): void {
    $link = AiArticleLink::factory()->create();

    expect($link->exists)->toBeTrue()
        ->and($link->kind)->toBe(AiLinkKind::Reference)
        ->and($link->article)->toBeInstanceOf(AiArticle::class)
        ->and($link->host())->toBe($link->host())
        ->and($link->host())->toBe(strtolower($link->host()));

    $repository = AiArticleLink::factory()->repository()->create();

    expect($repository->kind)->toBe(AiLinkKind::Repository)
        ->and($repository->url)->toStartWith('https://github.com/');

    foreach (AiLinkKind::cases() as $kind) {
        expect(AiArticleLink::factory()->kind($kind)->create()->kind)->toBe($kind);
    }
});

test('the path item factory writes a step that inherits its article title', function (): void {
    $article = AiArticle::factory()->create(['title' => 'The one about queues']);
    $path = AiLearningPath::factory()->create();

    $step = AiLearningPathItem::factory()->inside($path, 2)->create(['ai_article_id' => $article->id]);

    expect($step->exists)->toBeTrue()
        ->and($step->path->id)->toBe($path->id)
        ->and($step->sort_order)->toBe(2)
        // `title` is nullable precisely so a step can be labelled by its
        // article; a factory that always invented one would hide that.
        ->and($step->title)->toBeNull()
        ->and($step->displayTitle())->toBe('The one about queues');

    $titled = AiLearningPathItem::factory()->inside($path, 3)->titled('Optional detour')->create();

    expect($titled->displayTitle())->toBe('Optional detour');
});

test('the community factory writes a directory row that matches the feed', function (): void {
    $community = Community::factory()->create();

    expect($community->exists)->toBeTrue()
        ->and($community->kind)->toBe(CommunityKind::Open)
        ->and($community->rules)->toBeNull()
        // `getRouteKeyName()` is `slug`, so a community whose slug collides with
        // another is a 404 waiting to happen rather than a cosmetic problem. The
        // factory's random suffix exists for that reason and is pinned here.
        ->and($community->slug)->toMatch('/^[a-z0-9-]+$/')
        ->and(Community::factory()->create()->slug)->not->toBe($community->slug)
        ->and($community->getRouteKeyName())->toBe('slug')
        ->and($community->topic)->toBeString();

    // A community reads feed posts by topic string, so the factory's `topic()`
    // state is the only way a test can aim a community at a post.
    $post = FeedPost::factory()->create(['topic' => 'laravel']);
    $targeted = Community::factory()->topic('laravel')->create();

    expect($targeted->feedPosts->pluck('id'))->toContain($post->id);

    $inviteOnly = Community::factory()->inviteOnly()->create();

    expect($inviteOnly->kind)->toBe(CommunityKind::InviteOnly)
        ->and($inviteOnly->rules)->not->toBeNull();
});

test('the membership factory writes one row per account per community', function (): void {
    $community = Community::factory()->create();
    $account = User::factory()->create();

    $membership = CommunityMembership::factory()->in($community)->heldBy($account)->create();

    expect($membership->exists)->toBeTrue()
        ->and($membership->role)->toBe(CommunityRole::Member)
        ->and($membership->community_id)->toBe($community->id)
        ->and($membership->user_id)->toBe($account->id)
        ->and($membership->joined_at)->not->toBeNull()
        ->and($community->isMember($account))->toBeTrue()
        ->and($community->isMember(null))->toBeFalse();

    $owner = CommunityMembership::factory()->owner()->create();

    expect($owner->role)->toBe(CommunityRole::Owner);
    expect(CommunityMembership::factory()->moderator()->create()->role)->toBe(CommunityRole::Moderator);

    // The database, not the action class, is what makes the pair unique — and
    // that is only worth pinning because a test seeding two memberships in one
    // community would otherwise double-count a member.
    expect(fn () => CommunityMembership::factory()->in($community)->heldBy($account)->create())
        ->toThrow(QueryException::class);
});

test('the attachment factory writes the two states the two step upload relies on', function (): void {
    $account = User::factory()->create();

    $staged = FeedAttachment::factory()->staged()->create(['user_id' => $account->id]);

    expect($staged->exists)->toBeTrue()
        ->and($staged->feed_post_id)->toBeNull()
        ->and($staged->temp_key)->not->toBeNull()
        ->and($staged->path)->toStartWith('temp/'.$staged->temp_key.'/');

    // The scopes are the query the console and the prune command run, so the
    // state is only correct if the scope finds it.
    expect(FeedAttachment::query()->unattached()->pluck('id'))->toContain($staged->id)
        ->and(FeedAttachment::query()->attached()->pluck('id'))->not->toContain($staged->id);

    $post = FeedPost::factory()->create();
    $claimed = FeedAttachment::factory()->claimed($post, 4)->create();

    expect($claimed->feed_post_id)->toBe($post->id)
        ->and($claimed->temp_key)->toBeNull()
        ->and($claimed->sort_order)->toBe(4)
        ->and($claimed->path)->toStartWith('posts/'.$post->id.'/')
        ->and($claimed->disk)->toBe('feed_media');

    expect(FeedAttachment::query()->attached()->pluck('id'))->toContain($claimed->id);

    expect(FeedAttachment::factory()->png()->claimed($post)->create()->mime)->toBe('image/png');

    $dimensionless = FeedAttachment::factory()->withoutDimensions()->claimed($post)->create();

    expect($dimensionless->width)->toBeNull()
        ->and($dimensionless->height)->toBeNull()
        ->and($dimensionless->mime)->toBe('application/pdf');
});

test('the pivot factory writes the row that keeps a tag count honest', function (): void {
    $post = Post::factory()->published()->create();
    $tag = Tag::factory()->create();

    expect($tag->fresh()->posts_count)->toBe(0);

    $pivot = PostTag::factory()->forPost($post)->withTag($tag)->create();

    expect($pivot->exists)->toBeTrue()
        ->and($pivot->post_id)->toBe($post->id)
        ->and($pivot->tag_id)->toBe($tag->id)
        ->and($pivot->timestamps)->toBeFalse('a composite-key pivot must not be written with timestamps');

    // The model's own `saved` hook is the reason this factory is useful: the
    // count moves without any test calling `syncPostsCount()`.
    expect($tag->fresh()->posts_count)->toBe(1);

    // Removal goes through the relation, not `$pivot->delete()`: a composite-key
    // pivot has no single key to build a `where` from, which is why the app only
    // ever detaches. The row is the factory's, the deletion is the app's.
    $post->tags()->detach($tag->id);

    expect(PostTag::query()->where('post_id', $post->id)->where('tag_id', $tag->id)->exists())->toBeFalse()
        ->and($tag->fresh()->posts_count)->toBe(0);
});
