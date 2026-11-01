<?php

use App\Enums\AiPublicationStatus;
use App\Enums\AiResourceKind;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiLearningPathItem;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| AI Knowledge Hub console
|--------------------------------------------------------------------------
|
| The console behind `ai-hub:manage`. Pinned here: the route guard, the
| per-action guard, the "saving never publishes" rule, the publication
| workflow (legal moves, illegal moves, the path-publish precondition), and
| the taxonomy rules that keep the topic tree honest after a rename, a
| re-parent, or a delete.
|
*/

test('guests are bounced to the sign-in dialog from the hub console', function () {
    $this->get('/admin/ai-hub')->assertRedirect(route('home', ['signin' => 'required']));
});

test('accounts without ai-hub:manage are refused', function () {
    $this->actingAs(User::factory()->create())->get('/admin/ai-hub')->assertForbidden();
    $this->actingAs(User::factory()->seller()->create())->get('/admin/ai-hub')->assertForbidden();
});

test('an admin sees the stored articles', function () {
    AiArticle::factory()->create(['title' => 'Zebra Retrieval Notes']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/ai-hub')
        ->assertOk()
        ->assertSee('Zebra Retrieval Notes');
});

test('the console disables its mutating buttons while a request is in flight', function () {
    // A double click on a save, a delete confirm or a lesson reorder must not
    // queue two writes: every mutating button carries Livewire's
    // disabled-while-loading guard, the convention the other consoles follow.
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/ai-hub')
        ->assertOk()
        ->assertSee('wire:loading.attr="disabled"', escape: false);
});

test('an unknown shelf is refused', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/ai-hub?tab=ghost')
        ->assertNotFound();
});

test('saving a new article stores it as a draft even when publish is requested', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('articleTitle', 'Draft Discipline')
        ->set('articleBody', "# Draft Discipline\n\nNew hub content stays unpublished until an explicit transition.")
        ->set('articleStatusRequested', AiPublicationStatus::Published->value)
        ->call('saveArticle')
        ->assertHasNoErrors();

    $article = AiArticle::query()->where('title', 'Draft Discipline')->first();

    expect($article)->not->toBeNull()
        ->and($article->status)->toBe(AiPublicationStatus::Draft)
        ->and($article->published_at)->toBeNull()
        ->and($article->slug)->toBe('draft-discipline')
        ->and($article->body_html)->toContain('<h1');
});

test('an article without a title or a body is rejected', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('saveArticle')
        ->assertHasErrors(['articleTitle'])
        ->assertHasErrors(['articleBody']);

    expect(AiArticle::query()->count())->toBe(0);
});

test('a colliding title gets a suffixed slug', function () {
    AiArticle::factory()->create(['title' => 'Context Windows', 'slug' => 'context-windows']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('articleTitle', 'Context Windows')
        ->set('articleBody', '# Context Windows\n\nSecond article, same title.')
        ->call('saveArticle')
        ->assertHasNoErrors();

    expect(AiArticle::query()->where('slug', 'context-windows-2')->exists())->toBeTrue();
});

test('re-titling an article keeps its slug', function () {
    $article = AiArticle::factory()->create(['title' => 'Old Name', 'slug' => 'kept-slug']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('editArticle', $article->id)
        ->set('articleTitle', 'New Name')
        ->call('saveArticle')
        ->assertHasNoErrors();

    expect($article->refresh()->title)->toBe('New Name')
        ->and($article->slug)->toBe('kept-slug');
});

test('saving files the article under topics and reference links', function () {
    $topic = AiTopic::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('articleTitle', 'Filed and Referenced')
        ->set('articleBody', '# Filed\n\nBody copy.')
        ->set('articleTopics', [$topic->id])
        ->set('articleLinks', [
            ['url' => 'https://example.com/doc', 'label' => 'Doc', 'kind' => 'reference', 'note' => ''],
            ['url' => '', 'label' => '', 'kind' => 'reference', 'note' => ''],
        ])
        ->call('saveArticle')
        ->assertHasNoErrors();

    $article = AiArticle::query()->where('title', 'Filed and Referenced')->first();

    expect($article->topics->pluck('id')->all())->toBe([$topic->id])
        ->and($article->links)->toHaveCount(1);
});

test('deleting an article removes it', function () {
    $article = AiArticle::factory()->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('deleteArticle', $article->id);

    expect(AiArticle::query()->whereKey($article->id)->exists())->toBeFalse();
});

test('a publish transition stamps the timestamp once and keeps it on republication', function () {
    $article = AiArticle::factory()->create();

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub');

    $component->call('changeStatus', 'article', $article->id, AiPublicationStatus::Published->value);

    $article->refresh();
    expect($article->status)->toBe(AiPublicationStatus::Published)
        ->and($article->published_at)->not->toBeNull();

    $firstStamp = $article->published_at;

    $component->call('changeStatus', 'article', $article->id, AiPublicationStatus::Draft->value);
    $component->call('changeStatus', 'article', $article->id, AiPublicationStatus::Published->value);

    expect($article->refresh()->published_at->equalTo($firstStamp))->toBeTrue();
});

test('an illegal transition is refused without touching the record', function () {
    $article = AiArticle::factory()->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('changeStatus', 'article', $article->id, AiPublicationStatus::Review->value)
        ->assertHasNoErrors();

    expect($article->refresh()->status)->toBe(AiPublicationStatus::Published);
});

test('only articles can be held in review', function () {
    $path = AiLearningPath::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('changeStatus', 'path', $path->id, AiPublicationStatus::Review->value);

    expect($path->refresh()->status)->toBe(AiPublicationStatus::Draft);
});

test('a transition aimed at an unknown shelf is a 404', function () {
    $topic = AiTopic::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('changeStatus', 'topic', $topic->id, AiPublicationStatus::Published->value)
        ->assertStatus(404);
});

test('a path with an unpublished lesson cannot be published from the form', function () {
    $lesson = AiArticle::factory()->create();
    $path = AiLearningPath::factory()->create()->fresh();
    $path->items()->create(['ai_article_id' => $lesson->id, 'sort_order' => 0]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('editPath', $path->id)
        ->set('pathStatusRequested', AiPublicationStatus::Published->value)
        ->call('savePath')
        ->assertHasErrors(['pathStatusRequested']);

    expect($path->refresh()->status)->toBe(AiPublicationStatus::Draft);
});

test('a path whose lessons are all published can be published from the form', function () {
    $lesson = AiArticle::factory()->published()->create();
    $path = AiLearningPath::factory()->create();
    $path->items()->create(['ai_article_id' => $lesson->id, 'sort_order' => 0]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('editPath', $path->id)
        ->set('pathStatusRequested', AiPublicationStatus::Published->value)
        ->call('savePath')
        ->assertHasNoErrors();

    expect($path->refresh()->status)->toBe(AiPublicationStatus::Published)
        ->and($path->published_at)->not->toBeNull();
});

test('the lesson picker refuses empty, duplicate, and unknown picks', function () {
    $article = AiArticle::factory()->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('addLesson')
        ->assertHasErrors(['lessonChoice'])
        ->set('lessonChoice', $article->id)
        ->call('addLesson')
        ->assertHasNoErrors(['lessonChoice'])
        ->set('lessonChoice', $article->id)
        ->call('addLesson')
        ->assertHasErrors(['lessonChoice'])
        ->set('lessonChoice', 999_999)
        ->call('addLesson')
        ->assertHasErrors(['lessonChoice']);
});

test('the lesson order assembled in the form is the order stored on save', function () {
    $first = AiArticle::factory()->published()->create();
    $second = AiArticle::factory()->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('lessonChoice', $first->id)
        ->call('addLesson')
        ->set('lessonChoice', $second->id)
        ->call('addLesson')
        ->call('moveLesson', $second->id, 'up')
        ->set('pathTitle', 'Async Reading Route')
        ->call('savePath')
        ->assertHasNoErrors();

    $path = AiLearningPath::query()->where('title', 'Async Reading Route')->first();

    expect($path->items()->pluck('ai_article_id')->all())->toBe([$second->id, $first->id]);
});

test('reordering a saved lesson swaps it with its neighbour', function () {
    $first = AiArticle::factory()->published()->create();
    $second = AiArticle::factory()->published()->create();
    $path = AiLearningPath::factory()->withLessons([$first->id, $second->id])->create();

    $secondItem = $path->items()->where('ai_article_id', $second->id)->first();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('reorderLesson', $secondItem->id, 'up');

    expect($path->refresh()->items()->pluck('ai_article_id')->all())->toBe([$second->id, $first->id]);
});

test('deleting a path releases its slots but keeps the articles', function () {
    $lesson = AiArticle::factory()->published()->create();
    $path = AiLearningPath::factory()->withLessons([$lesson->id])->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('deletePath', $path->id);

    expect(AiLearningPath::query()->whereKey($path->id)->exists())->toBeFalse()
        ->and(AiArticle::query()->whereKey($lesson->id)->exists())->toBeTrue()
        ->and(AiLearningPathItem::query()->where('ai_learning_path_id', $path->id)->count())->toBe(0);
});

test('creating a topic stores it with a slug derived from its name', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('topicName', 'Retrieval Augmentation')
        ->call('saveTopic')
        ->assertHasNoErrors();

    $topic = AiTopic::query()->where('name', 'Retrieval Augmentation')->first();

    expect($topic)->not->toBeNull()
        ->and($topic->slug)->toBe('retrieval-augmentation')
        ->and($topic->is_active)->toBeTrue();
});

test('renaming a topic keeps its slug', function () {
    $topic = AiTopic::factory()->create(['name' => 'Old Topic', 'slug' => 'kept-topic-slug']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('editTopic', $topic->id)
        ->set('topicName', 'New Topic')
        ->call('saveTopic')
        ->assertHasNoErrors();

    expect($topic->refresh()->name)->toBe('New Topic')
        ->and($topic->slug)->toBe('kept-topic-slug');
});

test('a topic cannot be filed underneath its own child', function () {
    $parent = AiTopic::factory()->create();
    $child = AiTopic::factory()->childOf($parent)->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('editTopic', $parent->id)
        ->set('topicParentId', $child->id)
        ->call('saveTopic')
        ->assertHasErrors(['topicParentId']);

    expect($parent->refresh()->parent_id)->toBeNull();
});

test('the active switch flips a topic without deleting its filings', function () {
    $topic = AiTopic::factory()->create();
    $article = AiArticle::factory()->withTopics([$topic->id])->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('toggleTopicActive', $topic->id);

    expect($topic->refresh()->is_active)->toBeFalse()
        ->and($article->topics()->count())->toBe(1);
});

test('deleting a topic promotes its children and un-files its articles', function () {
    $root = AiTopic::factory()->create();
    $child = AiTopic::factory()->childOf($root)->create();
    $article = AiArticle::factory()->withTopics([$root->id])->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('deleteTopic', $root->id);

    expect(AiTopic::query()->whereKey($root->id)->exists())->toBeFalse()
        ->and($child->refresh()->parent_id)->toBeNull()
        ->and($article->refresh()->topics()->count())->toBe(0);
});

test('reordering a topic swaps it with its sibling', function () {
    $first = AiTopic::factory()->create(['sort_order' => 0]);
    $second = AiTopic::factory()->create(['sort_order' => 1]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('reorderTopic', $second->id, 'up');

    expect($first->refresh()->sort_order)->toBe(1)
        ->and($second->refresh()->sort_order)->toBe(0);
});

test('saving a new resource stores it as a draft and derives its shelf position', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('resourceTitle', 'Ollama')
        ->set('resourceUrl', 'https://ollama.com')
        ->set('resourceKindValue', AiResourceKind::Tool->value)
        ->set('resourceStatusRequested', AiPublicationStatus::Published->value)
        ->call('saveResource')
        ->assertHasNoErrors();

    $resource = AiResource::query()->where('title', 'Ollama')->first();

    expect($resource)->not->toBeNull()
        ->and($resource->status)->toBe(AiPublicationStatus::Draft)
        ->and($resource->kind)->toBe(AiResourceKind::Tool);
});

test('a resource link is checked before it is stored', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('resourceTitle', 'Relative Trap')
        ->set('resourceUrl', '/docs/ollama')
        ->call('saveResource')
        ->assertHasErrors(['resourceUrl'])
        ->set('resourceTitle', 'Scheme Trap')
        ->set('resourceUrl', 'ftp://ollama.com')
        ->call('saveResource')
        ->assertHasErrors(['resourceUrl']);

    expect(AiResource::query()->count())->toBe(0);
});

test('a resource filed under an unknown topic is refused on the topic field', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('resourceTitle', 'Misfiled')
        ->set('resourceUrl', 'https://example.com')
        ->set('resourceTopicId', 999_999)
        ->call('saveResource')
        ->assertHasErrors(['resourceTopicId']);
});

test('deleting a resource removes it', function () {
    $resource = AiResource::factory()->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('deleteResource', $resource->id);

    expect(AiResource::query()->whereKey($resource->id)->exists())->toBeFalse();
});

test('reordering a resource swaps it with its neighbour on the shelf', function () {
    $first = AiResource::factory()->create(['sort_order' => 0]);
    $second = AiResource::factory()->create(['sort_order' => 1]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('reorderResource', $second->id, 'up');

    expect($first->refresh()->sort_order)->toBe(1)
        ->and($second->refresh()->sort_order)->toBe(0);
});

test('console actions re-check the permission on the server', function () {
    $article = AiArticle::factory()->published()->create();

    // Open the console as an admin, then swap the authenticated account before
    // the action runs. The page guard has already passed; only the per-action
    // check can stop this call.
    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub');

    $this->actingAs(User::factory()->seller()->create());

    $component->call('deleteArticle', $article->id)->assertForbidden();

    expect(AiArticle::query()->whereKey($article->id)->exists())->toBeTrue();
});
