<?php

use App\Actions\DevLab\DeleteLabSnippet;
use App\Actions\DevLab\DeleteLabWorkspace;
use App\Actions\DevLab\RecordLabExecution;
use App\Actions\DevLab\SaveLabSnippet;
use App\Actions\DevLab\SaveLabWorkspace;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\LabSnippet;
use App\Models\LabWorkspace;
use App\Models\User;
use App\Support\Seo;
use Flux\Flux;
use Livewire\Livewire;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| Developer Lab collaborator boundaries (mocked)
|--------------------------------------------------------------------------
|
| Same contract as the Ideas mock suite: replace one action, prove the screen only
| delegates, and let the write staying undone be the assertion.
|
| The Lab needs these more than the Ideas module does, because two of its
| invariants are about *not* doing something. A run is recorded in exactly one
| place, and a run that was refused must not rebuild the frame. Both are invisible
| to a test that only watches the database: the row is absent either way, whether
| the guard worked or the code never asked. Mocking the recorder makes the question
| explicit, because the expectation is about who was consulted and in what order.
|
| The allowance figures are here for the same reason. `remainingToday()` is public
| on the action precisely so the screens do not reimplement the arithmetic, and a
| mock that answers the question proves which class the number came from.
|
*/

test('the sandbox marks itself noindex through the Seo singleton', function () {
    $this->mock(Seo::class, function (MockInterface $mock): void {
        $mock->makePartial()
            ->shouldReceive('set')
            ->once()
            ->with(Mockery::on(fn (array $meta): bool => $meta['title'] === 'Sandbox'
                && ($meta['noindex'] ?? null) === true));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox');
});

test('saving a workspace delegates to the SaveLabWorkspace action', function () {
    $this->mock(SaveLabWorkspace::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['name'] ?? '') === 'Marquee experiment'
                    && ($data['html'] ?? '') === '<h1>Hi</h1>'),
                Mockery::type('null'),
            )
            ->andReturn(LabWorkspace::factory()->make(['name' => 'Marquee experiment']));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Marquee experiment')
        ->set('html', '<h1>Hi</h1>')
        ->call('save')
        ->assertHasNoErrors();

    expect(LabWorkspace::query()->count())->toBe(0);
});

test('a run delegates the recording to the action that owns the policy', function () {
    $user = User::factory()->create();

    $this->mock(RecordLabExecution::class, function (MockInterface $mock) use ($user): void {
        // The bar reads the allowance on every render, so the mock answers that
        // question too. `handle()` is the only place a run gets recorded, and that
        // is the call the expectation is about.
        $mock->shouldReceive('remainingToday')->andReturn(99);
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (User $candidate): bool => $candidate->is($user)),
                LabLanguage::Html,
                'Loop study',
            )
            ->andReturn(new LabExecution);
    });

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Loop study')
        ->set('html', '<p>while (true)</p>')
        ->call('run')
        ->assertDispatched('lab-run');

    // The screen asked; the action never ran. Nothing was recorded.
    expect(LabExecution::query()->count())->toBe(0);
});

test('a refused run raises one toast and does not rebuild the frame', function () {
    $this->mock(RecordLabExecution::class, function (MockInterface $mock): void {
        $mock->shouldReceive('remainingToday')->andReturn(0);
        $mock->shouldReceive('handle')
            ->once()
            ->andThrow(InvalidArgumentException::class, 'You have used all 100 runs allowed today.');
    });

    Flux::partialMock()->shouldReceive('toast')->once();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox')
        ->set('html', '<p>again</p>')
        ->call('run')
        // The dispatch is what tells Alpine to compose a new document. A refusal
        // that still rebuilt would show output underneath a message saying there
        // would be none.
        ->assertNotDispatched('lab-run');

    expect(LabExecution::query()->count())->toBe(0);
});

test('an empty buffer is refused before the recorder is consulted at all', function () {
    // `handle()` has no expectation: the moment the screen reached the action,
    // Mockery would fail the call. Run on nothing must not spend the allowance.
    $this->mock(RecordLabExecution::class, function (MockInterface $mock): void {
        $mock->shouldReceive('remainingToday')->andReturn(100);
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox')
        ->set('html', '')
        ->call('run')
        ->assertNotDispatched('lab-run');

    expect(LabExecution::query()->count())->toBe(0);
});

test('the editor asks the recorder how many runs are left instead of counting rows', function () {
    $this->mock(RecordLabExecution::class, function (MockInterface $mock): void {
        $mock->shouldReceive('remainingToday')
            ->once()
            ->andReturn(3);
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox')
        ->assertSee('3 runs left today');
});

test('the history screen reads the allowance and records nothing', function () {
    $this->mock(RecordLabExecution::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')->never();
        $mock->shouldReceive('remainingToday')->andReturn(99);
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.history')
        ->assertSee('of 100 runs, 99 left');
});

test('turning a pane into a snippet delegates to the module writer', function () {
    $this->mock(SaveLabSnippet::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['language'] ?? null) === LabLanguage::Javascript
                    && ($data['code'] ?? '') === 'const noop = () => {}'),
            )
            ->andReturn(LabSnippet::factory()->make([
                'title' => 'Debounce helper',
                'slug' => 'debounce-helper',
            ]));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Debounce helper')
        ->set('js', 'const noop = () => {}')
        ->call('makeSnippet', 'js');

    expect(LabSnippet::query()->count())->toBe(0);
});

test('discarding a workspace delegates to the DeleteLabWorkspace action', function () {
    $user = User::factory()->create();
    $workspace = LabWorkspace::factory()->create(['user_id' => $user->id]);

    $this->mock(DeleteLabWorkspace::class, function (MockInterface $mock) use ($workspace): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (LabWorkspace $candidate): bool => $candidate->is($workspace)));
    });

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox', ['workspace' => (string) $workspace->id])
        ->call('discard');

    expect(LabWorkspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
});

test('publishing from the list delegates to the only writer of the visibility pair', function () {
    $user = User::factory()->create();
    $snippet = LabSnippet::factory()->create(['user_id' => $user->id]);

    $this->mock(SaveLabSnippet::class, function (MockInterface $mock) use ($snippet): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['visibility'] ?? null) === ContentVisibility::Public),
                Mockery::on(fn (LabSnippet $candidate): bool => $candidate->is($snippet)),
            )
            ->andReturn($snippet);
    });

    Livewire::actingAs($user)
        ->test('pages::dev-lab.snippets')
        ->call('publish', $snippet->id);

    // Neither half of the conjunction moved, because the list does not get to move it.
    expect($snippet->refresh()->visibility)->toBe(ContentVisibility::Private)
        ->and($snippet->published_at)->toBeNull();
});

test('saving from the snippet form delegates to the same action', function () {
    $this->mock(SaveLabSnippet::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['title'] ?? '') === 'Focus trap notes'
                    && ($data['language'] ?? null) === LabLanguage::Css),
                Mockery::type('null'),
            )
            ->andReturn(LabSnippet::factory()->make([
                'title' => 'Focus trap notes',
                'slug' => 'focus-trap-notes',
            ]));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.snippet')
        ->set('title', 'Focus trap notes')
        ->set('language', LabLanguage::Css->value)
        ->set('code', '.card { container-type: inline-size; }')
        ->call('save')
        ->assertHasNoErrors();

    expect(LabSnippet::query()->count())->toBe(0);
});

test('deleting a snippet from the list delegates to DeleteLabSnippet', function () {
    $user = User::factory()->create();
    $snippet = LabSnippet::factory()->create(['user_id' => $user->id]);

    $this->mock(DeleteLabSnippet::class, function (MockInterface $mock) use ($snippet): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (LabSnippet $candidate): bool => $candidate->is($snippet)));
    });

    Livewire::actingAs($user)
        ->test('pages::dev-lab.snippets')
        ->call('destroy', $snippet->id);

    expect(LabSnippet::query()->whereKey($snippet->id)->exists())->toBeTrue();
});

test('the admin console removes a snippet through the same action the author uses', function () {
    $snippet = LabSnippet::factory()->published()->create();

    $this->mock(DeleteLabSnippet::class, function (MockInterface $mock) use ($snippet): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (LabSnippet $candidate): bool => $candidate->is($snippet)));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.dev-lab')
        ->call('remove', $snippet->id);

    expect(LabSnippet::query()->whereKey($snippet->id)->exists())->toBeTrue();
});
