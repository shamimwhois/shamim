<?php

use App\Models\Comment;
use App\Models\FeedPost;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;

/*
 * The account-deletion screen is two components: the section that offers the
 * button and the modal that asks for the password behind it. Both are covered
 * here because the button alone is inert — the behaviour that matters is that
 * nothing is deleted until `current_password` is proved, and that the rows the
 * deleted account wrote fare according to the policy the migrations set.
 */

test('the delete section mounts the confirmation dialog the button opens', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.delete-user-form')
        ->assertSee('Delete account', false)
        ->assertSee('delete-user-button', false)
        // The dialog is a child component; if it stops being embedded, the
        // button leads nowhere.
        ->assertSee('Delete your account?', false);
});

test('an empty password deletes nothing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.delete-user-modal')
        ->call('deleteUser')
        ->assertHasErrors(['password' => 'required']);

    expect(User::query()->find($user->id))->not->toBeNull();

    assertAuthenticated();
});

test('a password that is not the account\'s own deletes nothing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'not-the-current-password')
        ->call('deleteUser')
        ->assertHasErrors(['password' => 'current_password']);

    expect(User::query()->find($user->id))->not->toBeNull();

    assertAuthenticated();
});

test('the current password deletes the account, ends the session and returns the visitor home', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertRedirect('/');

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();

    assertGuest();
});

test('deleting an account takes its comments with it and leaves its posts on the feed unowned', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();

    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id, 'user_id' => $author->id]);
    $feedPost = FeedPost::factory()->authoredBy($author)->create();

    Livewire::actingAs($author)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    // Comments cascade: they are the author's words attached to someone else's
    // page, and a dangling attribution is worse than no comment.
    expect(Comment::query()->whereKey($comment->id)->exists())->toBeFalse()
        // Feed posts null out their owner instead, because the thread is the
        // community's and the composer keeps publishing into it.
        ->and(FeedPost::query()->find($feedPost->id)?->user_id)->toBeNull()
        // Nothing about the deletion reaches a different account.
        ->and(User::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue();
});

test('the modal only ever acts on the account that is signed in', function () {
    $victim = User::factory()->create();
    $attacker = User::factory()->create();

    // There is no user id to tamper with: the component reads Auth::user(), so
    // the worst an authenticated account can do is delete itself.
    Livewire::actingAs($attacker)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertRedirect('/');

    expect(User::query()->whereKey($attacker->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($victim->id)->exists())->toBeTrue();
});
