<?php

use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| About content console
|--------------------------------------------------------------------------
|
| The console behind `about:manage`. Pinned here: the route guard, the
| per-action guard, the anchor-key rules that keep the scrollspy links
| stable, and the subtree delete that keeps the Experience tree honest.
|
*/

test('guests are bounced to the sign-in dialog from the about admin page', function () {
    $this->get('/admin/about')->assertRedirect(route('home', ['signin' => 'required']));
});

test('accounts without about:manage are refused', function () {
    $this->actingAs(User::factory()->create())->get('/admin/about')->assertForbidden();
    $this->actingAs(User::factory()->seller()->create())->get('/admin/about')->assertForbidden();
});

test('an admin sees the stored sections', function () {
    AboutSection::factory()->create([
        'type' => AboutSectionType::Expertise,
        'label' => 'Core Bands',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/about')
        ->assertOk()
        ->assertSee('Core Bands');
});

test('creating a section stores it with a slug derived from the label', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionType', AboutSectionType::Expertise->value)
        ->set('sectionLabel', 'Capabilities')
        ->set('sectionHeading', 'Where the years went')
        ->call('saveSection')
        ->assertHasNoErrors();

    $section = AboutSection::query()->where('key', 'capabilities')->first();

    expect($section)->not->toBeNull()
        ->and($section->type)->toBe(AboutSectionType::Expertise)
        ->and($section->heading)->toBe('Where the years went');
});

test('updating a section keeps its anchor key', function () {
    $section = AboutSection::factory()->create([
        'key' => 'about-expertise',
        'type' => AboutSectionType::Expertise,
        'label' => 'Capabilities',
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->call('editSection', $section->id)
        ->set('sectionLabel', 'What I do')
        ->call('saveSection')
        ->assertHasNoErrors();

    expect($section->refresh()->key)->toBe('about-expertise')
        ->and($section->label)->toBe('What I do');
});

test('an unknown section type is rejected', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionType', 'not-a-type')
        ->set('sectionLabel', 'Anything')
        ->call('saveSection')
        ->assertHasErrors(['sectionType']);

    expect(AboutSection::query()->count())->toBe(0);
});

test('a section without a name is rejected', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionType', AboutSectionType::Work->value)
        ->call('saveSection')
        ->assertHasErrors(['sectionLabel']);
});

test('deleting a section removes its rows', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Expertise]);
    AboutItem::factory()->create(['about_section_id' => $section->id]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->call('deleteSection', $section->id)
        ->assertHasNoErrors();

    expect(AboutSection::query()->count())->toBe(0)
        ->and(AboutItem::query()->count())->toBe(0);
});

test('the visibility switch flips a section', function () {
    $section = AboutSection::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->call('toggleSectionVisibility', $section->id);

    expect($section->refresh()->is_visible)->toBeFalse();
});

test('a row is created inside the selected section', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Expertise]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->set('itemTitle', 'Edge platforms')
        ->set('itemBody', 'Serving traffic at the boundary, not in the origin.')
        ->set('itemTags', 'Envoy, Go')
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = AboutItem::query()->where('title', 'Edge platforms')->first();

    expect($item)->not->toBeNull()
        ->and($item->about_section_id)->toBe($section->id)
        ->and($item->type)->toBe(AboutItemType::Entry);
});

test('a row without a title is rejected', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Expertise]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->call('saveItem')
        ->assertHasErrors(['itemTitle']);

    expect(AboutItem::query()->count())->toBe(0);
});

test('a saved workflow task row surfaces on the public dashboard', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::AiWorkflow]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $section->key)
        ->set('itemType', AboutItemType::WorkflowTask->value)
        ->set('itemTitle', 'Ship the quarterly report')
        ->set('itemSubtitle', '#SH-3000')
        ->set('itemValue', 'deploy')
        ->set('itemPeriod', 'Deploy agent')
        ->set('itemUrlLabel', 'Release')
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = AboutItem::query()->where('title', 'Ship the quarterly report')->first();

    expect($item)->not->toBeNull()
        ->and($item->type)->toBe(AboutItemType::WorkflowTask)
        ->and($item->value)->toBe('deploy');

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Ship the quarterly report')
        ->assertSee('#SH-3000');
});

test('deleting a row removes its whole subtree', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Experience]);
    $employer = AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::Employer,
    ]);
    $role = AboutItem::factory()->childOf($employer)->create(['type' => AboutItemType::Role]);
    AboutItem::factory()->childOf($role)->create(['type' => AboutItemType::Highlight]);
    $untouched = AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::Employer,
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->call('deleteItem', $employer->id);

    expect(AboutItem::query()->whereKey([$employer->id, $role->id, $role->children->first()?->id])->count())->toBe(0)
        ->and(AboutItem::query()->whereKey($untouched->id)->exists())->toBeTrue();
});

test('a row cannot become a child of its own descendant', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Experience]);
    $employer = AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::Employer,
    ]);
    $role = AboutItem::factory()->childOf($employer)->create(['type' => AboutItemType::Role]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->call('editItem', $employer->id)
        ->set('itemParentId', $role->id)
        ->call('saveItem')
        ->assertHasErrors(['itemParentId']);

    expect($employer->refresh()->parent_id)->toBeNull();
});

test('a parent from another section is rejected', function () {
    $experience = AboutSection::factory()->create(['type' => AboutSectionType::Experience]);
    $foreign = AboutItem::factory()->create([
        'about_section_id' => $experience->id,
        'type' => AboutItemType::Employer,
    ]);
    $other = AboutSection::factory()->create(['type' => AboutSectionType::Experience]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about')
        ->set('sectionKey', $other->key)
        ->set('itemTitle', 'Intruder employer')
        ->set('itemType', AboutItemType::Employer->value)
        ->set('itemParentId', $foreign->id)
        ->call('saveItem')
        ->assertHasErrors(['itemParentId']);

    expect(AboutItem::query()->where('title', 'Intruder employer')->exists())->toBeFalse();
});

test('console actions re-check the permission on the server', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Expertise]);

    // Open the console as an admin, then swap the authenticated account before
    // the action runs. The page guard has already passed; only the per-action
    // check can stop this call.
    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.about');

    $this->actingAs(User::factory()->seller()->create());

    $component->call('deleteSection', $section->id)->assertForbidden();

    expect(AboutSection::query()->whereKey($section->id)->exists())->toBeTrue();
});

test('mutation buttons disable themselves while a request is in flight', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/about')
        ->assertOk()
        ->assertSee('wire:loading.attr="disabled"', escape: false);
});
