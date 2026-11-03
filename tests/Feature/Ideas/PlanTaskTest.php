<?php

use App\Actions\Ideas\ReorderIdeaTasks;
use App\Actions\Ideas\SaveIdeaPlan;
use App\Actions\Ideas\SaveIdeaTask;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkPriority;
use App\Models\IdeaPlan;
use App\Models\IdeaTask;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Plans and the checklists attached to them
|--------------------------------------------------------------------------
|
| A plan is a document, and a task is a line on it, so the interesting failures
| here are not "can a row be written" but "can a line be attached to the wrong
| document". A task has no owner of its own: it belongs to the plan that lists it,
| and the plan belongs to an account. Every write on the plan screen therefore
| resolves the task through the plan first, which is what the foreign-id tests
| below pin.
|
*/

test('the plans screen lists only the signed-in account plans', function () {
    $user = User::factory()->create();
    $mine = IdeaPlan::factory()->for($user)->create(['title' => 'My own plan']);
    $theirs = IdeaPlan::factory()->create(['title' => 'Somebody else plan']);

    $component = Livewire::actingAs($user)->test('pages::ideas.plans');

    $component->assertSee($mine->title)->assertDontSee($theirs->title);
});

test('a finished plan leaves the default view', function () {
    $user = User::factory()->create();
    $running = IdeaPlan::factory()->for($user)->inProgress()->create(['title' => 'Still moving']);
    $finished = IdeaPlan::factory()->for($user)->completed()->create(['title' => 'Called it done']);

    $component = Livewire::actingAs($user)->test('pages::ideas.plans');

    $component->assertSee($running->title)->assertDontSee($finished->title);

    $component->set('tab', 'completed')->assertSee($finished->title);
});

test('a plan owned by somebody else cannot be opened', function () {
    $plan = IdeaPlan::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('ideas.plan', ['plan' => $plan->slug]))
        // A plan is never public, so an unreadable one is invisible rather than forbidden.
        ->assertNotFound();
});

test('a plan with notes renders them rather than failing on the read', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->documented()->create([
        'notes' => "## Sequence\n\nWeek three is the one that slips.",
    ]);

    // Every other test here builds an untitled-of-notes plan, so the render path
    // for the Markdown body had never been walked. A screen that cannot resolve
    // its renderer throws on the first plan anybody actually wrote something in.
    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->assertSee('Week three is the one that slips')
        ->assertSee('Sequence');
});

test('the plan screen adds a task to the bottom of its own list', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    $first = IdeaTask::factory()->for($plan, 'plan')->create(['title' => 'Write the summary', 'sort_order' => 0]);

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->set('newTask', 'Sketch the empty state')
        ->call('addTask')
        ->assertHasNoErrors();

    $task = IdeaTask::query()->firstWhere('title', 'Sketch the empty state');

    expect($task)->not->toBeNull()
        ->and($task->plan_id)->toBe($plan->id)
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->sort_order)->toBeGreaterThan($first->sort_order);
});

test('a task with nothing to do is refused and writes nothing', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->set('newTask', '   ')
        ->call('addTask')
        ->assertHasErrors(['newTask' => 'required']);

    expect(IdeaTask::query()->count())->toBe(0);
});

test('ticking a task stamps the moment it was finished, un-ticking removes it', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    $task = IdeaTask::factory()->for($plan, 'plan')->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('toggleTask', $task->id);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('toggleTask', $task->id);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Todo)
        ->and($task->completed_at)->toBeNull();
});

test('editing a task inline changes its title and stage', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    $task = IdeaTask::factory()->for($plan, 'plan')->create(['title' => 'Draft the copy']);

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('editTask', $task->id)
        ->set('editingTaskTitle', 'Draft the copy and the empty state')
        ->set('editingTaskStatus', TaskStatus::Blocked->value)
        ->call('updateTask')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->title)->toBe('Draft the copy and the empty state')
        ->and($task->status)->toBe(TaskStatus::Blocked);
});

test('moving a task one place swaps it with its neighbour', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();

    $first = IdeaTask::factory()->for($plan, 'plan')->create(['sort_order' => 0]);
    $second = IdeaTask::factory()->for($plan, 'plan')->create(['sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('moveTask', $second->id, 'up');

    expect($second->refresh()->sort_order)->toBe(0)
        ->and($first->refresh()->sort_order)->toBe(1);

    // The row at the top of the list has nothing above it, so the list cannot be
    // pushed past its own end.
    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('moveTask', $second->id, 'up');

    expect($second->refresh()->sort_order)->toBe(0);
});

test('a task from another plan cannot be reached through this plan screen', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    $foreign = IdeaTask::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug])
        ->call('toggleTask', $foreign->id)
        ->assertNotFound();

    expect($foreign->refresh()->status)->toBe(TaskStatus::Todo);
});

test('a task cannot be moved onto somebody else plan', function () {
    $plan = IdeaPlan::factory()->create();
    $foreignPlan = IdeaPlan::factory()->create();
    $task = IdeaTask::factory()->for($plan, 'plan')->create();

    expect(fn () => app(SaveIdeaTask::class)->handle(
        ['title' => $task->title],
        task: $task,
        plan: $foreignPlan,
    ))->toThrow(InvalidArgumentException::class);
});

test('only a done task is counted as finished when the plan is filtered', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();
    IdeaTask::factory()->for($plan, 'plan')->done()->create(['title' => 'Finished line']);
    IdeaTask::factory()->for($plan, 'plan')->create(['title' => 'Waiting line']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.plan', ['plan' => $plan->slug]);

    $component->assertSee('Waiting line');

    $component->set('tab', 'done')->assertDontSee('Waiting line')->assertSee('Finished line');
});

test('the plan form writes a document owned by its author', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.plan-edit')
        ->set('title', 'Rebuild the reading list')
        ->set('summary', 'The list has outgrown the sidebar.')
        ->set('problem', 'Readers keep losing their place.')
        ->set('scope', 'The saved list and its empty state.')
        ->call('save')
        ->assertHasNoErrors();

    $plan = IdeaPlan::query()->firstWhere('title', 'Rebuild the reading list');

    expect($plan)->not->toBeNull()
        ->and($plan->user_id)->toBe($user->id)
        ->and($plan->slug)->toBe('rebuild-the-reading-list')
        ->and($plan->status)->toBe(PlanStatus::Draft)
        ->and($plan->problem_statement)->toBe('Readers keep losing their place.')
        ->and($plan->scope)->toBe('The saved list and its empty state.');
});

test('a plan cannot skip to a stage its workflow does not lead to', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create(['status' => PlanStatus::Draft]);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.plan-edit', ['plan' => $plan->slug])
        ->set('status', PlanStatus::Completed->value)
        ->call('save');

    // The refusal belongs to the action rather than the field, because a transition
    // is a rule about the workflow and not about the shape of a form value.
    $component->assertHasNoErrors(['status']);

    expect($plan->refresh()->status)->toBe(PlanStatus::Draft);
});

test('an unranked plan keeps its priority null and a ranked one stores it', function () {
    $user = User::factory()->create();
    $plan = IdeaPlan::factory()->for($user)->create();

    app(SaveIdeaPlan::class)->handle($user, [
        'title' => $plan->title,
        'priority' => WorkPriority::Urgent->value,
    ], $plan);

    expect($plan->refresh()->priority)->toBe(WorkPriority::Urgent);

    app(SaveIdeaPlan::class)->handle($user, [
        'title' => $plan->title,
        'priority' => '',
    ], $plan);

    expect($plan->refresh()->priority)->toBeNull();
});

test('reordering through the action never touches another plan ordering', function () {
    $first = IdeaPlan::factory()->create();
    $second = IdeaPlan::factory()->create();

    $task = IdeaTask::factory()->for($first, 'plan')->create(['sort_order' => 0]);
    $neighbour = IdeaTask::factory()->for($first, 'plan')->create(['sort_order' => 1]);
    $elsewhere = IdeaTask::factory()->for($second, 'plan')->create(['sort_order' => 0]);

    app(ReorderIdeaTasks::class)->handle($neighbour, 'up');

    expect($neighbour->refresh()->sort_order)->toBe(0)
        ->and($task->refresh()->sort_order)->toBe(1)
        ->and($elsewhere->refresh()->sort_order)->toBe(0);
});
