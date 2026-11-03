<?php

use App\Enums\AccountRole;
use App\Enums\ContentVisibility;
use App\Enums\IdeaStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Idea visibility and derived copy
|--------------------------------------------------------------------------
|
| `isPublished()` is the invariant the whole module rests on, and `isVisibleTo()`
| is the one predicate every surface asks before it renders a row. Both are pure
| functions of columns the model already carries, so they are exercised here with
| no database at all: a failure points at the rule rather than at a fixture. The
| screens that call them are covered in tests/Feature/Ideas.
|
*/

/**
 * An idea that exists only as attribute values, because visibility is decided on columns.
 */
function ideaUnderTest(array $attributes = []): Idea
{
    return (new Idea)->forceFill(array_merge([
        'id' => 1,
        'user_id' => 7,
        'title' => 'An idea',
        'slug' => 'an-idea',
        'status' => IdeaStatus::Inbox,
        'visibility' => ContentVisibility::Private,
        'published_at' => null,
    ], $attributes));
}

/**
 * An account that exists only as an id and a role, because permissions are read from the role.
 *
 * Named rather than `reader()`: Pest unit files share one global function namespace,
 * and `reader()` already belongs to the feed visibility suite.
 */
function ideaReader(int $id, AccountRole $role = AccountRole::User): User
{
    return (new User)->forceFill([
        'id' => $id,
        'name' => "Reader {$id}",
        'email' => "reader{$id}@example.test",
        'role' => $role,
    ]);
}

/*
|--------------------------------------------------------------------------
| The publish gate
|--------------------------------------------------------------------------
*/

test('publication is the conjunction of the flag and the timestamp, not either alone', function () {
    expect(ideaUnderTest(['visibility' => ContentVisibility::Public, 'published_at' => Carbon::now()])->isPublished())->toBeTrue()
        ->and(ideaUnderTest(['visibility' => ContentVisibility::Public, 'published_at' => null])->isPublished())->toBeFalse()
        ->and(ideaUnderTest(['visibility' => ContentVisibility::Private, 'published_at' => Carbon::now()])->isPublished())->toBeFalse();
});

test('an idea that has never been touched by the publisher is not published', function () {
    expect(ideaUnderTest()->isPublished())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Who may read it
|--------------------------------------------------------------------------
*/

test('a published idea is readable by anyone including a guest', function () {
    $published = ideaUnderTest([
        'visibility' => ContentVisibility::Public,
        'published_at' => Carbon::now(),
        'status' => IdeaStatus::Approved,
    ]);

    expect($published->isVisibleTo(null))->toBeTrue()
        ->and($published->isVisibleTo(ideaReader(99)))->toBeTrue();
});

test('a public flag with no timestamp stays invisible to a guest', function () {
    // The state the factory calls `unpublishedPublic()` is reachable through a row
    // written before the sync action existed. It must not leak.
    expect(ideaUnderTest(['visibility' => ContentVisibility::Public])->isVisibleTo(null))->toBeFalse();
});

test('a private idea is readable by its author and by nobody else signed in', function () {
    $private = ideaUnderTest();

    expect($private->isVisibleTo(ideaReader(7)))->toBeTrue()
        ->and($private->isVisibleTo(ideaReader(8)))->toBeFalse()
        ->and($private->isVisibleTo(null))->toBeFalse();
});

test('an account that may open the admin panel reads across accounts', function () {
    $private = ideaUnderTest();

    expect($private->isVisibleTo(ideaReader(99, AccountRole::Admin)))->toBeTrue();
});

test('an idea with no author belongs to nobody but is still open to the admin console', function () {
    $orphan = ideaUnderTest(['user_id' => null]);

    expect($orphan->isVisibleTo(ideaReader(7)))->toBeFalse()
        ->and($orphan->isVisibleTo(ideaReader(99, AccountRole::Admin)))->toBeTrue();
});

test('the author is the only account that may write to an idea', function () {
    // An admin reads other people's work but does not rewrite it: moderation here is
    // a delete decision, not an edit.
    expect(ideaUnderTest()->isOwnedBy(ideaReader(7)))->toBeTrue()
        ->and(ideaUnderTest()->isOwnedBy(ideaReader(99, AccountRole::Admin)))->toBeFalse()
        ->and(ideaUnderTest()->isOwnedBy(null))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The teaser
|--------------------------------------------------------------------------
*/

test('the excerpt is the summary when there is one', function () {
    expect(ideaUnderTest(['summary' => 'A hand-written teaser.'])->excerpt())->toBe('A hand-written teaser.');
});

test('the excerpt falls back to the opening of the content when the summary is empty', function () {
    // A card with an empty teaser is the usual failure of a hand-maintained summary
    // column, so the fallback is part of the contract rather than a nicety.
    expect(ideaUnderTest(['summary' => null, 'content' => 'First paragraph of the note.'])->excerpt())
        ->toBe('First paragraph of the note.');
});

test('the excerpt strips markup before falling back to the content', function () {
    expect(ideaUnderTest(['summary' => null, 'content' => '<p>Hidden <strong>markup</strong> here.</p>'])->excerpt(200))
        ->toBe('Hidden markup here.');
});

test('the excerpt respects the limit it was given', function () {
    $excerpt = ideaUnderTest(['summary' => null, 'content' => str_repeat('a', 400)])->excerpt(20);

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(24);
});

test('an idea with nothing written in it yields an empty excerpt rather than an error', function () {
    expect(ideaUnderTest(['summary' => null, 'content' => null])->excerpt())->toBe('');
});

/*
|--------------------------------------------------------------------------
| The queries the screens are built on
|--------------------------------------------------------------------------
*/

test('the published scope asks for both halves of the publish gate', function () {
    $query = Idea::query()->published()->toRawSql();

    expect($query)->toContain('visibility')
        ->and($query)->toContain('is not null');
});

test('a search term has its like wildcards escaped', function () {
    // A term of `%` would otherwise match every row the account owns, which turns a
    // filter into a leak of the whole workspace.
    expect(ideaUnderTest()->escapeLikeValue('100%_done'))->toBe('100\\%\\_done')
        ->and(ideaUnderTest()->escapeLikeValue('plain'))->toBe('plain');
});

test('an empty search term leaves the query alone instead of matching everything', function () {
    $sql = Idea::query()->search('  ')->toRawSql();

    expect($sql)->not->toContain('like');
});

test('the open scope keeps archived work out of the default list', function () {
    expect(Idea::query()->open()->toRawSql())->toContain('status');
});

test('priority sorts ranked ideas first and leaves unranked work at the end', function () {
    expect(WorkPriority::Urgent->weight())->toBeLessThan(WorkPriority::High->weight())
        ->and(WorkPriority::Medium->weight())->toBeLessThan(WorkPriority::Low->weight());

    // The SQL that orders a list is a constant, so it has to be kept honest against
    // the weights that describe them in PHP. Without this, editing one of the two
    // would silently reorder the workspace.
    $sql = WorkPriority::orderBySql();

    foreach (WorkPriority::cases() as $priority) {
        expect($sql)->toContain("WHEN '{$priority->value}' THEN {$priority->weight()}");
    }

    expect($sql)->toContain('ELSE '.(count(WorkPriority::cases())).' END');
});
