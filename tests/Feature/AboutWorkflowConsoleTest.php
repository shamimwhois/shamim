<?php

use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use App\Models\User;
use App\Services\AboutContent;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| AI Workflow console
|--------------------------------------------------------------------------
|
| The workflow half of `/admin/about`. The generic About form speaks in
| value/subtitle/period; the board speaks in stage, priority, agent, status,
| band and tone. Pinned here: the mapping onto those generic columns, the
| normalisation that stops a typo stranding a card, the tone the operator
| chose surviving to the page, and the seed action refusing to duplicate a
| board that already has rows.
|
*/

/**
 * An AI Workflow section with nothing in it.
 */
function emptyWorkflowSection(): AboutSection
{
    return AboutSection::factory()->create([
        'key' => 'about-ai-workflow',
        'type' => AboutSectionType::AiWorkflow,
        'label' => 'AI Workflow',
        'heading' => 'AI Workflow',
    ]);
}

test('the console shows the board state it is about to publish', function () {
    $section = emptyWorkflowSection();

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'Harden the webhook',
        'value' => 'review',
        'url_label' => 'Bug',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowAgent,
        'title' => 'Auditor Agent',
        'body' => 'Checks every diff',
        'value' => 'blocked',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowMetric,
        'title' => 'Deploys today',
        'subtitle' => 'header',
        'value' => '17',
        'url_label' => 'green',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/about?section=about-ai-workflow')
        ->assertOk()
        ->assertSee('Workflow state')
        ->assertSee('Harden the webhook')
        ->assertSee('Review &amp; Testing', escape: false)
        ->assertSee('Auditor Agent')
        ->assertSee('Blocked')
        ->assertSee('Deploys today')
        // The generic row form would make an operator guess which column is
        // the stage; the workflow console replaces it entirely.
        ->assertDontSee('Value / stat');
});

test('a task card is stored with its stage, priority and agent', function () {
    $section = emptyWorkflowSection();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->set('wfKind', AboutItemType::WorkflowTask->value)
        ->set('wfTitle', 'Ship the quarterly report')
        ->set('wfIdentifier', '#SH-3000')
        ->set('wfBody', 'Generate and publish the numbers.')
        ->set('wfStage', 'deploy')
        ->set('wfPriority', 'Release')
        ->set('wfAgent', 'Deploy agent')
        ->set('wfTarget', 'acme/reports#88')
        ->set('wfTags', 'Reports, Cloud')
        ->set('wfOrder', 4)
        ->call('saveWorkflowItem')
        ->assertHasNoErrors();

    $item = AboutItem::query()->where('title', 'Ship the quarterly report')->firstOrFail();

    expect($item->type)->toBe(AboutItemType::WorkflowTask)
        ->and($item->value)->toBe('deploy')
        ->and($item->url_label)->toBe('Release')
        ->and($item->subtitle)->toBe('#SH-3000')
        ->and($item->period)->toBe('Deploy agent')
        ->and($item->url)->toBe('acme/reports#88')
        ->and($item->sort_order)->toBe(4)
        ->and($item->tagList())->toBe(['Reports', 'Cloud']);
});

test('a card cannot be saved into a stage outside the pipeline', function () {
    $section = emptyWorkflowSection();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->set('wfTitle', 'Stray card')
        ->set('wfStage', 'not-a-stage')
        ->call('saveWorkflowItem')
        ->assertHasErrors(['wfStage']);

    expect(AboutItem::query()->count())->toBe(0);
});

test('an agent node stores its status and the graph reads it back', function () {
    $section = emptyWorkflowSection();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->set('wfKind', AboutItemType::WorkflowAgent->value)
        ->set('wfTitle', 'Coder Agent')
        ->set('wfBody', 'Writes & refactors code')
        ->set('wfStatus', 'blocked')
        ->call('saveWorkflowItem')
        ->assertHasNoErrors();

    $item = AboutItem::query()->where('title', 'Coder Agent')->firstOrFail();

    expect($item->type)->toBe(AboutItemType::WorkflowAgent)
        ->and($item->value)->toBe('blocked');

    $agents = app(AboutContent::class)->aiWorkflow()['agents'];

    expect($agents[0]['status'])->toBe('blocked')
        ->and($agents[0]['statusTone'])->toBe('amber');

    // The graph's linear equivalent names the state, so the colour is never
    // the only carrier (spec §2 W4).
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Coder Agent — Writes &amp; refactors code (Blocked)', escape: false);
});

test('an unknown agent status degrades to active rather than breaking the graph', function () {
    $section = emptyWorkflowSection();

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowAgent,
        'title' => 'Legacy Agent',
        'value' => 'something-else',
    ]);

    $agents = app(AboutContent::class)->aiWorkflow()['agents'];

    expect($agents[0]['status'])->toBe('active');
});

test('a metric keeps the tone the operator chose', function () {
    $section = emptyWorkflowSection();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->set('wfKind', AboutItemType::WorkflowMetric->value)
        ->set('wfTitle', 'Pipeline health')
        ->set('wfValue', 'Degraded')
        ->set('wfBand', 'telemetry')
        ->set('wfTone', 'amber')
        ->call('saveWorkflowItem')
        ->assertHasNoErrors();

    $item = AboutItem::query()->where('title', 'Pipeline health')->firstOrFail();

    expect($item->value)->toBe('Degraded')
        ->and($item->subtitle)->toBe('telemetry')
        ->and($item->url_label)->toBe('amber');

    $metrics = app(AboutContent::class)->aiWorkflow()['metrics'];

    expect($metrics['telemetry'][0]['tone'])->toBe('amber');
});

test('a metric without a value is rejected', function () {
    $section = emptyWorkflowSection();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->set('wfKind', AboutItemType::WorkflowMetric->value)
        ->set('wfTitle', 'Empty metric')
        ->set('wfValue', '')
        ->call('saveWorkflowItem')
        ->assertHasErrors(['wfValue']);

    expect(AboutItem::query()->count())->toBe(0);
});

test('editing a row loads it back into the kind-aware form and updates in place', function () {
    $section = emptyWorkflowSection();

    $item = AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'First wording',
        'value' => 'trigger',
        'url_label' => 'Feature',
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->call('editWorkflowItem', $item->id)
        ->assertSet('wfTitle', 'First wording')
        ->assertSet('wfStage', 'trigger')
        ->set('wfStage', 'encoding')
        ->set('wfTitle', 'Second wording')
        ->call('saveWorkflowItem')
        ->assertHasNoErrors();

    expect(AboutItem::query()->count())->toBe(1)
        ->and($item->refresh()->value)->toBe('encoding')
        ->and($item->title)->toBe('Second wording');
});

test('seeding an empty board writes the built-in demo', function () {
    $section = emptyWorkflowSection();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->call('seedWorkflowDefaults')
        ->assertHasNoErrors();

    expect($section->items()->where('type', AboutItemType::WorkflowTask->value)->count())->toBe(7)
        ->and($section->items()->where('type', AboutItemType::WorkflowAgent->value)->count())->toBe(5)
        ->and($section->items()->where('type', AboutItemType::WorkflowMetric->value)->count())->toBe(6);

    // Seeded rows carry the vocabulary the board expects, not null columns.
    $agent = $section->items()->where('type', AboutItemType::WorkflowAgent->value)->firstOrFail();
    $metric = $section->items()->where('type', AboutItemType::WorkflowMetric->value)->firstOrFail();

    expect($agent->value)->toBeIn(['active', 'idle', 'blocked'])
        ->and($metric->url_label)->toBeIn(['cyan', 'green', 'neutral', 'amber']);
});

test('seeding refuses a board that already has rows', function () {
    $section = emptyWorkflowSection();

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'Already here',
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->call('seedWorkflowDefaults');

    expect($section->items()->count())->toBe(1);
});

test('reordering moves a card within its own kind only', function () {
    $section = emptyWorkflowSection();

    foreach (['Alpha', 'Beta', 'Gamma'] as $index => $title) {
        AboutItem::factory()->create([
            'about_section_id' => $section->id,
            'type' => AboutItemType::WorkflowTask,
            'title' => $title,
            'sort_order' => $index,
        ]);
    }

    $agent = AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowAgent,
        'title' => 'Untouched agent',
        'sort_order' => 0,
    ]);

    $gamma = AboutItem::query()->where('title', 'Gamma')->firstOrFail();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->call('moveWorkflowItem', $gamma->id, -1);

    $titles = AboutItem::query()
        ->where('about_section_id', $section->id)
        ->where('type', AboutItemType::WorkflowTask->value)
        ->ordered()
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Alpha', 'Gamma', 'Beta'])
        ->and($agent->refresh()->sort_order)->toBe(0);
});

test('deleting a workflow row removes it from the public board', function () {
    $section = emptyWorkflowSection();

    $item = AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'Withdrawn card',
        'value' => 'trigger',
    ]);

    $this->get(route('about'))->assertOk()->assertSee('Withdrawn card');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->call('deleteItem', $item->id);

    // The board falls back to its demo once the stored rows are gone, so the
    // deleted card must be absent while the demo cards return.
    $this->get(route('about'))
        ->assertOk()
        ->assertDontSee('Withdrawn card')
        ->assertSee('Redesign navbar profile');
});

test('the public board reports its own counts without JavaScript', function () {
    $section = emptyWorkflowSection();

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'One card',
        'value' => 'trigger',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'Two cards',
        'value' => 'deploy',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('2 tasks · 1 at deploy');
});

test('workflow mutations re-check the permission', function () {
    emptyWorkflowSection();

    // Open the console as an admin, then swap the authenticated account before
    // the action runs. The page guard has already passed; only the per-action
    // check can stop this call.
    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about');

    $this->actingAs(User::factory()->seller()->create());

    $component->call('saveWorkflowItem')->assertForbidden();

    expect(AboutItem::query()->count())->toBe(0);
});
