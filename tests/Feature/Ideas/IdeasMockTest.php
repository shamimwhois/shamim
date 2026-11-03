<?php

use App\Actions\Ideas\ConvertIdeaToPlan;
use App\Actions\Ideas\DeleteIdea;
use App\Actions\Ideas\DeleteIdeaCategory;
use App\Actions\Ideas\DeleteIdeaTask;
use App\Actions\Ideas\ReorderIdeaTasks;
use App\Actions\Ideas\SaveIdea;
use App\Actions\Ideas\SaveIdeaCategory;
use App\Actions\Ideas\SaveIdeaTask;
use App\Actions\Ideas\ToggleIdeaPin;
use App\Enums\IdeaStatus;
use App\Enums\TaskStatus;
use App\Models\Idea;
use App\Models\IdeaCategory;
use App\Models\IdeaPlan;
use App\Models\IdeaTask;
use App\Models\User;
use App\Support\Seo;
use Flux\Flux;
use Livewire\Livewire;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| Ideas collaborator boundaries (mocked)
|--------------------------------------------------------------------------
|
| Each test replaces one action with a mock to prove the screen *delegates* to it
| rather than doing the work inline. The write staying undone is the point: if a
| refactor moved the persistence into the component, the mock's expectation would
| still be met and the row would appear anyway, which is the regression these
| tests exist to catch.
|
| What is worth delegating is what has a rule attached. Slug derivation, the
| status workflow, the publish conjunction and "conversion happens once" all live
| in the action classes, so a screen that wrote a row itself would be a second,
| weaker implementation of the same rules. The pin and the reorder are here for
| the same reason: those two classes own the only two invariants about ordering
| and about what "pinned" means.
|
| Two mechanics of the mocks themselves are worth recording, because both failed
| quietly at first. An action whose `handle()` declares a model return type gets an
| auto-generated mock of that model back when the expectation has no `andReturn()`,
| so a screen that reads a property off the result throws — hence the explicit
| `andReturn()` on the two cases that return a row. And a call made with a named
| argument (`handle($data, plan: $plan)`) is matched positionally against the
| signature, with the parameters before it padded and the ones after it absent: the
| arity in `with()` has to be the arity of the call site, not of the method.
|
| The Seo cases are a different boundary: the workspace pages must reach the
| shared metadata singleton marked noindex, because a private idea that leaked
| into a search index is a leaked idea.
|
*/

test('the workspace marks itself noindex through the Seo singleton', function () {
    $this->mock(Seo::class, function (MockInterface $mock): void {
        $mock->makePartial()
            ->shouldReceive('set')
            ->once()
            ->with(Mockery::on(fn (array $meta): bool => $meta['title'] === 'Ideas'
                && ($meta['noindex'] ?? null) === true));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::ideas.dashboard');
});

test('the ideas console marks itself noindex through the same singleton', function () {
    $this->mock(Seo::class, function (MockInterface $mock): void {
        $mock->makePartial()
            ->shouldReceive('set')
            ->once()
            ->with(Mockery::on(fn (array $meta): bool => $meta['title'] === 'Ideas — administration'
                && ($meta['noindex'] ?? null) === true));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ideas');
});

test('capturing from the dashboard delegates to the SaveIdea action', function () {
    $this->mock(SaveIdea::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['title'] ?? '') === 'Offline-first reading'),
            )
            ->andReturn(Idea::factory()->make([
                'title' => 'Offline-first reading',
                'slug' => 'offline-first-reading',
            ]));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::ideas.dashboard')
        ->set('captureTitle', 'Offline-first reading')
        ->call('capture')
        ->assertHasNoErrors();

    expect(Idea::query()->count())->toBe(0);
});

test('saving from the edit screen delegates to the same action', function () {
    $this->mock(SaveIdea::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['title'] ?? '') === 'A quieter inbox'
                    && ($data['status'] ?? '') === 'inbox'),
                null,
            )
            ->andReturn(Idea::factory()->make([
                'title' => 'A quieter inbox',
                'slug' => 'a-quieter-inbox',
            ]));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::ideas.edit')
        ->set('title', 'A quieter inbox')
        ->call('save')
        ->assertHasNoErrors();

    expect(Idea::query()->count())->toBe(0);
});

test('a refusal raised by the action reaches the author as exactly one toast', function () {
    $this->mock(SaveIdea::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->andThrow(InvalidArgumentException::class, 'That stage is not reachable from here.');
    });

    $admin = User::factory()->admin()->create();

    Flux::partialMock()->shouldReceive('toast')->once();

    Livewire::actingAs($admin)
        ->test('pages::ideas.edit')
        ->set('title', 'An idea with an illegal stage')
        ->call('save')
        ->assertHasNoErrors();

    expect(Idea::query()->count())->toBe(0);
});

test('deleting from the dashboard delegates to the DeleteIdea action', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->create(['user_id' => $user->id]);

    $this->mock(DeleteIdea::class, function (MockInterface $mock) use ($idea): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (Idea $candidate): bool => $candidate->is($idea)));
    });

    Livewire::actingAs($user)
        ->test('pages::ideas.dashboard')
        ->call('delete', $idea->id);

    expect(Idea::query()->whereKey($idea->id)->exists())->toBeTrue();
});

test('pinning delegates to the action that owns what pinned means', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->create(['user_id' => $user->id, 'is_pinned' => false]);

    $this->mock(ToggleIdeaPin::class, function (MockInterface $mock) use ($idea): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (Idea $candidate): bool => $candidate->is($idea)))
            // Returned untouched. The action is what would flip the flag, and the
            // screen reads the answer back off the model, so handing the same row
            // back is the assertion that nothing was flipped here.
            ->andReturn($idea);
    });

    Livewire::actingAs($user)
        ->test('pages::ideas.dashboard')
        ->call('togglePin', $idea->id);

    expect($idea->refresh()->is_pinned)->toBeFalse();
});

test('conversion delegates to the one class allowed to decide what may become a plan', function () {
    $idea = Idea::factory()->approved()->create();

    $this->mock(ConvertIdeaToPlan::class, function (MockInterface $mock) use ($idea): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (Idea $candidate): bool => $candidate->is($idea)))
            ->andReturn(IdeaPlan::factory()->make([
                'title' => $idea->title,
                'slug' => $idea->slug.'-plan',
            ]));
    });

    Livewire::actingAs($idea->user)
        ->test('pages::ideas.edit', ['idea' => $idea->slug])
        ->call('convertToPlan');

    // Neither half of the rule was executed: no plan exists, and the idea is
    // exactly where it was, because the screen does not get to decide either.
    expect(IdeaPlan::query()->count())->toBe(0)
        ->and($idea->refresh()->status)->toBe(IdeaStatus::Approved);
});

test('adding a task delegates to the SaveIdeaTask action', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();

    $this->mock(SaveIdeaTask::class, function (MockInterface $mock) use ($plan): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (array $data): bool => ($data['title'] ?? '') === 'Write the empty state'),
                Mockery::type('null'),
                Mockery::on(fn (IdeaPlan $candidate): bool => $candidate->is($plan)),
            )
            ->andReturn(IdeaTask::factory()->make(['title' => 'Write the empty state']));
    });

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->set('newTask', 'Write the empty state')
        ->call('addTask')
        ->assertHasNoErrors();

    expect(IdeaTask::query()->count())->toBe(0);
});

test('ticking a task is a write the action performs, not the screen', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    $task = IdeaTask::factory()->for($plan, 'plan')->create(['status' => TaskStatus::Todo]);

    $this->mock(SaveIdeaTask::class, function (MockInterface $mock) use ($task): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (array $data): bool => ($data['status'] ?? null) === TaskStatus::Done),
                Mockery::on(fn (IdeaTask $candidate): bool => $candidate->is($task)),
            )
            ->andReturn($task);
    });

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('toggleTask', $task->id);

    expect($task->refresh()->status)->toBe(TaskStatus::Todo)
        ->and($task->refresh()->completed_at)->toBeNull();
});

test('moving a task delegates the swap to ReorderIdeaTasks', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    IdeaTask::factory()->for($plan, 'plan')->create(['sort_order' => 0]);
    $second = IdeaTask::factory()->for($plan, 'plan')->create(['sort_order' => 1]);

    $this->mock(ReorderIdeaTasks::class, function (MockInterface $mock) use ($second): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (IdeaTask $candidate): bool => $candidate->is($second)),
                'up',
            );
    });

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('moveTask', $second->id, 'up');

    expect($second->refresh()->sort_order)->toBe(1);
});

test('removing a task delegates to the DeleteIdeaTask action', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    $task = IdeaTask::factory()->for($plan, 'plan')->create();

    $this->mock(DeleteIdeaTask::class, function (MockInterface $mock) use ($task): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (IdeaTask $candidate): bool => $candidate->is($task)));
    });

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('deleteTask', $task->id);

    expect(IdeaTask::query()->whereKey($task->id)->exists())->toBeTrue();
});

test('saving a category delegates to SaveIdeaCategory', function () {
    $this->mock(SaveIdeaCategory::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(fn (array $data): bool => ($data['name'] ?? '') === 'Reading'),
                Mockery::type('null'),
            )
            ->andReturn(IdeaCategory::factory()->make(['name' => 'Reading', 'slug' => 'reading']));
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::ideas.categories')
        ->set('name', 'Reading')
        ->call('save')
        ->assertHasNoErrors();

    expect(IdeaCategory::query()->count())->toBe(0);
});

test('deleting a category delegates to the action that detaches its ideas first', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->create(['user_id' => $user->id]);
    $idea = Idea::factory()->create(['user_id' => $user->id, 'idea_category_id' => $category->id]);

    $this->mock(DeleteIdeaCategory::class, function (MockInterface $mock) use ($category): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (IdeaCategory $candidate): bool => $candidate->is($category)));
    });

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('delete', $category->id);

    expect(IdeaCategory::query()->whereKey($category->id)->exists())->toBeTrue()
        ->and($idea->refresh()->idea_category_id)->toBe($category->id);
});

test('the admin console removes through the same action the author uses', function () {
    $idea = Idea::factory()->published()->create();

    $this->mock(DeleteIdea::class, function (MockInterface $mock) use ($idea): void {
        $mock->makePartial()
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (Idea $candidate): bool => $candidate->is($idea)));
    });

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ideas')
        ->call('remove', $idea->id);

    expect(Idea::query()->whereKey($idea->id)->exists())->toBeTrue();
});
