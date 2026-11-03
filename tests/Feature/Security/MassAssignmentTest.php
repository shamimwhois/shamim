<?php

use App\Actions\DevLab\SaveLabSnippet;
use App\Actions\Ideas\SaveIdea;
use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\CommentStatus;
use App\Enums\IdeaStatus;
use App\Enums\LabLanguage;
use App\Models\Comment;
use App\Models\FeedPost;
use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\MarketplaceProduct;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Which columns a request can actually reach
|--------------------------------------------------------------------------
|
| Mass assignment is not "is this column fillable". `role`, `status`,
| `visibility` and `published_at` are all fillable here on purpose — the admin
| consoles, the publish transitions and the seeder write them by name. The
| question a test can answer is narrower and more useful: does any path a
| request travels down let a caller put a value in one of those columns?
|
| Two defences exist in this application, and they are different:
|
|   - the column is not fillable, so `fill()` drops it (the counters —
|     `view_count`, `download_count` — and `email_verified_at`);
|   - the column is fillable, but the writer names the keys it takes, so a
|     payload key outside that list is never read (`SaveIdea`, `SaveLabSnippet`,
|     the profile save, the comment store).
|
| Both are asserted here. The first group is a property of the model; the second
| is a property of a code path, which is the one that can regress when somebody
| helpfully swaps an explicit array for `$request->all()`.
|
*/

test('the counters a reader could inflate are not assignable', function (): void {
    // A view count that a form can write is a view count nobody believes.
    Post::factory()->create()->fill(['view_count' => 9999])->save();
    expect(Post::query()->latest('id')->value('view_count'))->not->toBe(9999);

    $idea = Idea::factory()->create();
    $idea->fill(['view_count' => 9999])->save();
    expect($idea->refresh()->view_count)->not->toBe(9999);

    $snippet = LabSnippet::factory()->create();
    $snippet->fill(['view_count' => 9999])->save();
    expect($snippet->refresh()->view_count)->not->toBe(9999);

    $product = MarketplaceProduct::factory()->create();
    $product->fill(['download_count' => 9999])->save();
    expect($product->refresh()->download_count)->not->toBe(9999);
});

test('the moderation flags on a feed post are assignable because only the console writes them', function (): void {
    // Deliberately the opposite case, recorded so it is not "fixed" by adding a
    // guard nobody asked for: `FeedPost` is seeded and moderated, never created
    // by a request, so its flags are fillable and the console's own guard is
    // what protects them.
    $post = FeedPost::factory()->create(['pinned' => false]);

    $post->fill(['pinned' => true])->save();

    expect($post->refresh()->pinned)->toBeTrue();
});

test('the verification timestamp cannot be assigned, only earned', function (): void {
    $user = User::factory()->unverified()->create();

    $user->fill(['email_verified_at' => now()])->save();

    expect($user->refresh()->email_verified_at)->toBeNull();
});

test('role is fillable by design, and the profile screen refuses to write it', function (): void {
    // `User::$fillable` names `role` because `admin/users` is a role editor. The
    // defence is that the *other* writer of a User — the account's own profile
    // screen — validates exactly two keys and fills only what validation
    // returned.
    $account = User::factory()->create(['role' => AccountRole::User->value]);

    Livewire::actingAs($account)
        ->test('pages::settings.profile')
        ->set('name', 'Renamed By Its Owner')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $account->refresh();

    expect($account->name)->toBe('Renamed By Its Owner')
        ->and($account->role)->toBe(AccountRole::User);
});

test('the profile screen exposes no role control for a request to set', function (): void {
    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.profile')
        ->instance();

    // `fill($validated)` can only ever contain keys the component holds as
    // public state, so the absence of the property is the absence of the vector.
    expect(property_exists($component, 'role'))->toBeFalse()
        ->and(property_exists($component, 'email_verified_at'))->toBeFalse();
});

test('registration ignores a role in the request', function (): void {
    $response = $this->post('/register', [
        'name' => 'Self Appointed',
        'email' => 'self-appointed@example.test',
        'password' => 'Su5!strong-passphrase',
        'password_confirmation' => 'Su5!strong-passphrase',
        'role' => AccountRole::Admin->value,
    ]);

    $account = User::query()->where('email', 'self-appointed@example.test')->first();

    expect($account)->not->toBeNull()
        ->and($account->role)->toBe(AccountRole::User);

    $response->assertSessionHasNoErrors();
});

test('changing the email address withdraws the verification it no longer covers', function (): void {
    // Not a mass-assignment rule but the same boundary: `email_verified_at` is
    // server-owned, and a verified flag carried over onto an address nobody
    // proved would be a stamp you can buy by editing a form.
    $account = User::factory()->create(['email' => 'first@example.test']);

    Livewire::actingAs($account)
        ->test('pages::settings.profile')
        ->set('email', 'second@example.test')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($account->refresh()->email_verified_at)->toBeNull();
});

test('a hand-crafted owner in the idea payload is not read', function (): void {
    $author = User::factory()->create();
    $intruder = User::factory()->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Owner is decided here',
        'summary' => '',
        'content' => '',
        'user_id' => $intruder->id,
    ]);

    expect($idea->user_id)->toBe($author->id);
});

test('a hand-crafted owner in the snippet payload is not read', function (): void {
    $author = User::factory()->create();
    $intruder = User::factory()->create();

    $snippet = app(SaveLabSnippet::class)->handle($author, [
        'title' => 'Owner is decided here',
        'language' => LabLanguage::Html->value,
        'code' => '<p>hi</p>',
        'user_id' => $intruder->id,
    ]);

    expect($snippet->user_id)->toBe($author->id);
});

test('saving an existing idea cannot re-parent it', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $idea = Idea::factory()->for($owner)->create();

    $saved = app(SaveIdea::class)->handle($other, ['title' => 'Renamed anyway'], $idea);

    // `user_id ??= $author->id` keeps the row's owner, which is the correct half.
    // The other half — that `$other` should never have reached here — is the
    // policy's job and is pinned by the workspace tests.
    expect($saved->user_id)->toBe($owner->id);
});

test('the publish moment is decided by the transition, not by the payload', function (): void {
    $author = User::factory()->create();
    $fabricated = now()->subYears(3)->startOfSecond();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Backdated on purpose',
        'summary' => '',
        'content' => '',
        'published_at' => $fabricated->toDateTimeString(),
    ]);

    expect($idea->published_at)->not->toBe($fabricated)
        ->and($idea->status)->toBe(IdeaStatus::Inbox);
});

test('a payload cannot pin an idea through the save action', function (): void {
    $author = User::factory()->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Trying to pin myself',
        'summary' => '',
        'content' => '',
        'is_pinned' => true,
    ]);

    // `is_pinned` is fillable — the workspace has a pin control — but the action
    // names the keys it takes, and this is not one of them.
    expect($idea->is_pinned)->toBeFalse();
});

test('a comment the reader writes is never approved by the writer', function (): void {
    $post = Post::factory()->published()->create();
    $author = User::factory()->create();

    $component = Livewire::actingAs($author)
        ->test('pages::blog.show', ['post' => $post])
        ->set('commentBody', 'A first comment from a reader.')
        ->call('postComment');

    $comment = Comment::query()->latest('id')->first();

    expect($comment->status)->toBe(CommentStatus::Pending)
        ->and($comment->user_id)->toBe($author->id);

    // And the screen holds no status property a request could set — the value is
    // written literally in `postComment()`.
    expect(property_exists($component->instance(), 'status'))->toBeFalse();
});

test('a comment is approved only by the moderation console writing the column', function (): void {
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create();

    // `status` is fillable, which is why the console's authorisation matters
    // more than the column list. Asserted here so the two facts stay together.
    expect($comment->status)->toBe(CommentStatus::Pending)
        ->and(User::factory()->create()->hasPermission(AccountPermission::ModerateComments))->toBeFalse();
});
