<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

test('a signed-in reader can submit a comment', function () {
    $post = Post::factory()->published()->create();
    $reader = User::factory()->create();

    Livewire::actingAs($reader)
        ->test('pages::blog.show', ['post' => $post])
        ->set('commentBody', 'A thoughtful contribution to the discussion.')
        ->call('postComment')
        ->assertHasNoErrors();

    expect(Comment::query()->where('post_id', $post->id)->count())->toBe(1);

    $comment = Comment::query()->where('post_id', $post->id)->sole();

    expect($comment->user_id)->toBe($reader->id)
        ->and($comment->status)->toBe(CommentStatus::Pending)
        ->and($comment->body)->toBe('A thoughtful contribution to the discussion.');
});

test('a submitted comment is queued for moderation, not published immediately', function () {
    $post = Post::factory()->published()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::blog.show', ['post' => $post])
        ->set('commentBody', 'Pending review please.')
        ->call('postComment');

    $this->get(route('blog.show', $post))->assertDontSee('Pending review please.');
});

test('a guest is redirected to sign in rather than commenting anonymously', function () {
    $post = Post::factory()->published()->create();

    Livewire::test('pages::blog.show', ['post' => $post])
        ->set('commentBody', 'An anonymous drive-by comment.')
        ->call('postComment')
        ->assertRedirect(route('login'));

    expect(Comment::query()->count())->toBe(0);
});

test('the comment body is required and length checked', function () {
    $post = Post::factory()->published()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::blog.show', ['post' => $post])
        ->set('commentBody', '')
        ->call('postComment')
        ->assertHasErrors(['commentBody' => 'required']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::blog.show', ['post' => $post])
        ->set('commentBody', 'ab')
        ->call('postComment')
        ->assertHasErrors(['commentBody' => 'min']);
});

test('a filled honeypot silently discards the submission', function () {
    $post = Post::factory()->published()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::blog.show', ['post' => $post])
        ->set('commentBody', 'Definitely a human, not a bot.')
        ->set('website', 'https://spam.example')
        ->call('postComment')
        ->assertHasNoErrors();

    expect(Comment::query()->count())->toBe(0);
});

test('a comment body is escaped rather than rendering as markup', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->approved()->create([
        'body' => '<script>alert("xss")</script>',
    ]);

    $response = $this->get(route('blog.show', $post));

    expect($response->getContent())
        ->not->toContain('<script>alert("xss")</script>')
        ->toContain('&lt;script&gt;');
});

test('a reader cannot flood the comment queue', function () {
    $post = Post::factory()->published()->create();
    $reader = User::factory()->create();

    $component = Livewire::actingAs($reader)->test('pages::blog.show', ['post' => $post]);

    foreach (range(1, 5) as $i) {
        $component->set('commentBody', 'Comment number '.$i.' is long enough.')
            ->call('postComment')
            ->assertHasNoErrors();
    }

    $component->set('commentBody', 'One comment too many.')
        ->call('postComment')
        ->assertHasErrors(['commentBody']);

    expect(Comment::query()->where('post_id', $post->id)->count())->toBe(5);
});

test('replies to a comment are shown under their parent', function () {
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->for($post)->approved()->create(['body' => 'The original point']);

    Comment::factory()->for($post)->approved()->create([
        'body' => 'A reply to that point',
        'parent_id' => $parent->id,
    ]);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('The original point')
        ->assertSee('A reply to that point');
});
