<?php

use App\Actions\AiHub\DeleteAiArticle;
use App\Actions\AiHub\HydrateAiArticleContent;
use App\Actions\AiHub\ReorderAiResource;
use App\Actions\AiHub\SaveAiArticle;
use App\Actions\AiHub\SaveAiLearningPath;
use App\Actions\AiHub\SaveAiResource;
use App\Actions\AiHub\SaveAiTopic;
use App\Actions\AiHub\SyncAiPublicationStatus;
use App\Enums\AiPublicationStatus;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Models\User;
use App\Support\Seo;
use Flux\Flux;
use Livewire\Livewire;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| Hub collaborator boundaries (mocked)
|--------------------------------------------------------------------------
|
| These tests replace a collaborator with a mock to prove the console and the
| public pages *delegate* to it. The write staying undone is the point: if a
| future refactor inlines the persistence into the component, the mock's
| expectation still passes but the database assertion fails — which is the
| regression these tests exist to catch.
|
*/

test('the console marks itself noindex through the Seo singleton', function () {
    $this->mock(Seo::class, function (MockInterface $mock): void {
        $mock->makePartial()
            ->shouldReceive('set')
            ->once()
            ->with(Mockery::on(fn (array $meta): bool => $meta['title'] === 'AI Knowledge Hub'
                && ($meta['noindex'] ?? null) === true));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub');
});

test('saving an article delegates to the SaveAiArticle action', function () {
    $author = User::factory()->admin()->create();

    $this->mock(SaveAiArticle::class, function (MockInterface $mock) use ($author): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (User $user): bool => $user->is($author)),
                Mockery::type('array'),
                Mockery::type('null'),
                Mockery::type('array'),
                Mockery::type('array'),
                Mockery::type('array'),
            )
            ->andReturn(AiArticle::factory()->make(['title' => 'Delegated Draft']));
    });

    Livewire::actingAs($author)
        ->test('pages::admin.ai-hub')
        ->set('articleTitle', 'Delegated Draft')
        ->set('articleBody', '# Delegated\n\nThe console must not write inline.')
        ->call('saveArticle')
        ->assertHasNoErrors();

    expect(AiArticle::query()->count())->toBe(0);
});

test('saving a path delegates to the SaveAiLearningPath action', function () {
    $this->mock(SaveAiLearningPath::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(Mockery::type(User::class), Mockery::type('array'), Mockery::type('null'), Mockery::type('array'))
            ->andReturn(AiLearningPath::factory()->make(['title' => 'Delegated Route']));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('pathTitle', 'Delegated Route')
        ->call('savePath')
        ->assertHasNoErrors();

    expect(AiLearningPath::query()->count())->toBe(0);
});

test('saving a topic delegates to the SaveAiTopic action', function () {
    $this->mock(SaveAiTopic::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (array $data): bool => ($data['name'] ?? '') === 'Delegated Taxonomy'),
                Mockery::type('null'),
            )
            ->andReturn(AiTopic::factory()->make(['name' => 'Delegated Taxonomy']));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('topicName', 'Delegated Taxonomy')
        ->call('saveTopic')
        ->assertHasNoErrors();

    expect(AiTopic::query()->count())->toBe(0);
});

test('saving a resource delegates to the SaveAiResource action', function () {
    $this->mock(SaveAiResource::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['url'] ?? '') === 'https://example.com'),
                Mockery::type('null'),
            )
            ->andReturn(AiResource::factory()->make(['title' => 'Delegated Link']));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->set('resourceTitle', 'Delegated Link')
        ->set('resourceUrl', 'https://example.com')
        ->call('saveResource')
        ->assertHasNoErrors();

    expect(AiResource::query()->count())->toBe(0);
});

test('a status transition delegates to the single publication writer', function () {
    $article = AiArticle::factory()->create();

    $this->mock(SyncAiPublicationStatus::class, function (MockInterface $mock) use ($article): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (AiArticle $candidate): bool => $candidate->is($article)),
                AiPublicationStatus::Published,
            )
            ->andReturn($article);
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('changeStatus', 'article', $article->id, AiPublicationStatus::Published->value);

    expect($article->refresh()->status)->toBe(AiPublicationStatus::Draft);
});

test('deleting an article delegates to the DeleteAiArticle action', function () {
    $article = AiArticle::factory()->published()->create();

    $this->mock(DeleteAiArticle::class, function (MockInterface $mock) use ($article): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (AiArticle $candidate): bool => $candidate->is($article)));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('deleteArticle', $article->id);

    expect(AiArticle::query()->whereKey($article->id)->exists())->toBeTrue();
});

test('reordering a resource delegates to the ReorderAiResource action', function () {
    $resource = AiResource::factory()->create(['sort_order' => 1]);
    $neighbour = AiResource::factory()->create(['sort_order' => 0]);

    $this->mock(ReorderAiResource::class, function (MockInterface $mock) use ($resource, $neighbour): void {
        $mock->makePartial()
            ->shouldReceive('neighbour')
            ->andReturn($neighbour);
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (AiResource $candidate): bool => $candidate->is($resource)),
                Mockery::on(fn (AiResource $candidate): bool => $candidate->is($neighbour)),
            );
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('reorderResource', $resource->id, 'up');

    expect($resource->refresh()->sort_order)->toBe(1);
});

test('a publish transition raises exactly one toast', function () {
    $article = AiArticle::factory()->create();

    Flux::partialMock()->shouldReceive('toast')->once();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ai-hub')
        ->call('changeStatus', 'article', $article->id, AiPublicationStatus::Published->value);

    expect($article->refresh()->status)->toBe(AiPublicationStatus::Published);
});

test('the article page renders the stored projection instead of re-hydrating markdown', function () {
    $article = AiArticle::factory()->published()->create(['title' => 'Stored Rendering']);

    // A full mock with no expectation: if the read path reached the hydrator,
    // Mockery would fail the call. Rendering must trust `body_html` as written.
    $this->mock(HydrateAiArticleContent::class);

    $this->get(route('ai-hub.article', $article))
        ->assertOk()
        ->assertSee('Stored Rendering');
});
