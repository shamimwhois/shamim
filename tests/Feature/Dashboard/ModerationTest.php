<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

test('the comments screen requires authentication', function () {
    $this->get(route('dashboard.comments'))->assertRedirect(route('login'));
});

test('the moderation queue defaults to pending comments', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();

    Comment::factory()->for($post)->create(['body' => 'Awaiting a decision']);
    Comment::factory()->for($post)->approved()->create(['body' => 'Already published']);

    $this->actingAs($author)
        ->get(route('dashboard.comments'))
        ->assertOk()
        ->assertSee('Awaiting a decision')
        ->assertDontSee('Already published');
});

test('the approved tab shows approved comments', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();

    Comment::factory()->for($post)->create(['body' => 'Awaiting a decision']);
    Comment::factory()->for($post)->approved()->create(['body' => 'Already published']);

    $this->actingAs($author)
        ->get(route('dashboard.comments', ['status' => 'approved']))
        ->assertOk()
        ->assertSee('Already published')
        ->assertDontSee('Awaiting a decision');
});

test('comments on another authors posts are never listed', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    $mine = Post::factory()->for($author)->published()->create();
    $theirs = Post::factory()->for($stranger)->published()->create();

    Comment::factory()->for($mine)->create(['body' => 'On my post']);
    Comment::factory()->for($theirs)->create(['body' => 'On their post']);

    $this->actingAs($author)
        ->get(route('dashboard.comments'))
        ->assertOk()
        ->assertSee('On my post')
        ->assertDontSee('On their post');
});

test('the moderation queue can be searched', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();

    Comment::factory()->for($post)->create(['body' => 'A remark about caching']);
    Comment::factory()->for($post)->create(['body' => 'A remark about testing']);

    $this->actingAs($author)
        ->get(route('dashboard.comments', ['q' => 'caching']))
        ->assertOk()
        ->assertSee('A remark about caching')
        ->assertDontSee('A remark about testing');
});

test('approving a comment publishes it on the post', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();
    $comment = Comment::factory()->for($post)->create(['body' => 'A pending remark']);

    $this->get(route('blog.show', $post))->assertDontSee('A pending remark');

    $comment->update(['status' => CommentStatus::Approved]);

    $this->get(route('blog.show', $post))->assertSee('A pending remark');
});

test('rejecting a comment keeps it off the post', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();
    $comment = Comment::factory()->for($post)->create(['body' => 'A spam remark']);

    $comment->update(['status' => CommentStatus::Rejected]);

    $this->get(route('blog.show', $post))->assertDontSee('A spam remark');
});

test('deleting a comment removes it from the post', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();
    $comment = Comment::factory()->for($post)->approved()->create(['body' => 'A removable remark']);

    $comment->delete();

    $this->get(route('blog.show', $post))->assertDontSee('A removable remark');
});
