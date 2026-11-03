<?php

use App\Actions\Marketplace\ApproveProduct;
use App\Actions\Marketplace\ApproveReview;
use App\Actions\Marketplace\RejectProduct;
use App\Actions\Marketplace\RejectReview;
use App\Enums\ProductStatus;
use App\Enums\ReviewStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use App\Models\User;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Flux\Flux;
use Livewire\Livewire;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| Collaborator boundaries (mocked)
|--------------------------------------------------------------------------
|
| These tests replace a collaborator with a mock to prove the page *delegates*
| to it. That is a different claim from the feature tests, which prove the
| result is correct: here the assertion is "the component called the action
| once, with this model, and did not perform the write itself".
|
| The write staying undone is the point. If a future refactor inlines
| `$product->update([...])` into the component, the mock's expectation still
| passes but the database assertion fails — which is exactly the regression
| these tests exist to catch.
|
*/

test('the catalogue publishes its head metadata through the Seo singleton', function () {
    publishedMarketplaceProduct();

    $this->mock(Seo::class, function (MockInterface $mock): void {
        $mock->makePartial()
            ->shouldReceive('set')
            ->once()
            ->with(Mockery::on(
                fn (array $meta): bool => $meta['title'] === 'Developer Marketplace'
                    && is_string($meta['description'])
                    && $meta['description'] !== '',
            ));
    });

    $this->get(route('marketplace.index'))->assertOk();
});

test('a listing publishes its own name as the head title', function () {
    $product = publishedMarketplaceProduct(['name' => 'Queue Lens', 'tagline' => 'A queue inspector.']);

    $this->mock(Seo::class, function (MockInterface $mock) use ($product): void {
        $mock->makePartial()
            ->shouldReceive('set')
            ->once()
            ->with(Mockery::on(
                fn (array $meta): bool => $meta['title'] === $product->name
                    && $meta['description'] === $product->tagline,
            ));
    });

    $this->get(route('marketplace.show', ['product' => $product->slug]))->assertOk();
});

test('the overview tab renders the description through the sanitiser', function () {
    $product = publishedMarketplaceProduct([
        'description' => 'Original description that must not be rendered verbatim.',
    ]);

    $this->mock(MarkdownRenderer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('render')
            ->once()
            ->andReturn('<p data-test="rendered-description">Sanitised body.</p>');
    });

    $this->get(route('marketplace.show', ['product' => $product->slug]))
        ->assertOk()
        ->assertSee('Sanitised body.')
        ->assertDontSee('Original description that must not be rendered verbatim.');
});

test('publishing delegates to the ApproveProduct action', function () {
    $product = MarketplaceProduct::factory()->pending()->create();

    $this->mock(ApproveProduct::class, function (MockInterface $mock) use ($product): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (MarketplaceProduct $candidate): bool => $candidate->is($product)))
            ->andReturn($product);
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('approveProduct', $product->id)
        ->assertHasNoErrors();

    expect($product->refresh()->status)->toBe(ProductStatus::PendingReview);
});

test('withdrawing delegates to the RejectProduct action', function () {
    $product = MarketplaceProduct::factory()->published()->create();

    $this->mock(RejectProduct::class, function (MockInterface $mock) use ($product): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (MarketplaceProduct $candidate): bool => $candidate->is($product)))
            ->andReturn($product);
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('withdrawProduct', $product->id)
        ->assertHasNoErrors();

    expect($product->refresh()->status)->toBe(ProductStatus::Published);
});

test('approving a review delegates to the ApproveReview action', function () {
    $review = MarketplaceReview::factory()->create();

    $this->mock(ApproveReview::class, function (MockInterface $mock) use ($review): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (MarketplaceReview $candidate): bool => $candidate->is($review)))
            ->andReturn($review);
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('approveReview', $review->id)
        ->assertHasNoErrors();

    expect($review->refresh()->status)->toBe(ReviewStatus::Pending);
});

test('rejecting a review delegates to the RejectReview action', function () {
    $review = MarketplaceReview::factory()->create();

    $this->mock(RejectReview::class, function (MockInterface $mock) use ($review): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (MarketplaceReview $candidate): bool => $candidate->is($review)))
            ->andReturn($review);
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('rejectReview', $review->id)
        ->assertHasNoErrors();

    expect($review->refresh()->status)->toBe(ReviewStatus::Pending);
});

test('a moderation action raises exactly one toast', function () {
    $product = MarketplaceProduct::factory()->pending()->create();

    Flux::partialMock()->shouldReceive('toast')->once();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('approveProduct', $product->id);

    expect($product->refresh()->status)->toBe(ProductStatus::Published);
});
