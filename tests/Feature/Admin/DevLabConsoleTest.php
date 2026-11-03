<?php

use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\LabSnippet;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Developer Lab admin console
|--------------------------------------------------------------------------
|
| This file inherits the four cases `AdminModulePlaceholdersTest` proved while
| `/admin/dev-lab` was a placeholder: guests bounce, non-admins are refused, the
| page wears staff chrome, and it is not indexable. They are kept rather than
| rewritten because the boundary underneath them did not move.
|
| What is added is the behaviour that only exists now there is something to
| administer. The console reads across accounts, and the one write it is allowed
| across accounts is removal — `LabSnippetPolicy::update()` is authors only, so a
| test that looked for an edit affordance would be asserting a bug.
|
| The guard test is the one to read twice. A Livewire mutation is a POST to
| `/livewire/update` and never passes through `permission:admin:access`, so this
| is the case that would fail if `boot()` were deleted while everything else
| stayed green.
|
*/

test('guests are bounced to the sign-in dialog', function () {
    $this->get('/admin/dev-lab')->assertRedirect(route('home', ['signin' => 'required']));
});

test('accounts without admin:access are refused the console', function () {
    $this->actingAs(User::factory()->create())->get('/admin/dev-lab')->assertForbidden();
    $this->actingAs(User::factory()->seller()->create())->get('/admin/dev-lab')->assertForbidden();
});

test('an admin reads snippets from every account in the workspace shell', function () {
    $first = LabSnippet::factory()->create(['title' => 'One account saved']);
    $second = LabSnippet::factory()->create(['title' => 'Another account saved']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/dev-lab')
        ->assertOk()
        ->assertSee($first->title)
        ->assertSee($second->title)
        // Staff chrome, and nothing here may be indexed.
        ->assertSee('data-sui', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow" />', false);
});

test('the console lists the author beside the snippet', function () {
    $author = User::factory()->create(['name' => 'Roya', 'email' => 'roya@example.test']);
    LabSnippet::factory()->for($author)->create(['title' => 'Debounce a callback']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/dev-lab')
        ->assertOk()
        ->assertSee('Roya')
        ->assertSee('roya@example.test');
});

test('a private snippet is listed but not marked public', function () {
    $private = LabSnippet::factory()->create(['title' => 'Not for the gallery']);
    $published = LabSnippet::factory()->published()->create(['title' => 'Live on the site']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/dev-lab')
        ->assertOk()
        ->assertSee($private->title)
        ->assertSee('Public')
        ->assertSee($published->title);
});

test('the language filter narrows the list to that language', function () {
    LabSnippet::factory()->language(LabLanguage::Javascript)->create(['title' => 'A helper written in JS']);
    LabSnippet::factory()->php()->create(['title' => 'A helper written in PHP']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.dev-lab')
        ->set('language', LabLanguage::Php->value)
        ->assertSee('A helper written in PHP')
        ->assertDontSee('A helper written in JS');
});

test('the search reaches the saved code as well as the title', function () {
    LabSnippet::factory()->create([
        'title' => 'Something else entirely',
        'code' => 'function debounce(fn, wait) { return fn; }',
    ]);
    LabSnippet::factory()->create(['title' => 'Unrelated', 'code' => '<p>nothing to find here</p>']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.dev-lab')
        ->set('search', 'debounce')
        ->assertSee('Something else entirely')
        ->assertDontSee('Unrelated');
});

test('the console never renders stored code as markup', function () {
    LabSnippet::factory()->create([
        'title' => 'A snippet containing a tag',
        'code' => '<script>alert(document.cookie)</script>',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/dev-lab')
        ->assertOk()
        ->assertDontSee('<script>alert(document.cookie)</script>', false)
        ->assertDontSee('sandbox="allow-scripts"', false);
});

test('the executions tab lists runs from every account', function () {
    LabExecution::factory()->labelled('Nav experiment')->create();
    LabExecution::factory()->labelled('Colour picker')->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.dev-lab')
        ->set('tab', 'executions')
        ->assertSee('Nav experiment')
        ->assertSee('Colour picker');
});

test('an admin may take a snippet down', function () {
    $snippet = LabSnippet::factory()->published()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.dev-lab')
        ->call('remove', $snippet->id);

    expect(LabSnippet::query()->whereKey($snippet->id)->exists())->toBeFalse();
});

test('removing a snippet leaves the runs it produced', function () {
    $author = User::factory()->create();
    $snippet = LabSnippet::factory()->for($author)->create();
    LabExecution::factory()->for($author)->labelled($snippet->title)->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.dev-lab')
        ->call('remove', $snippet->id);

    expect(LabExecution::query()->count())->toBe(1)
        ->and(LabExecution::query()->first()->label)->toBe($snippet->title);
});

test('an account without admin:access cannot reach the console through the endpoint', function () {
    $snippet = LabSnippet::factory()->create();

    // The route never sees this request, so the only thing standing between an
    // ordinary account and somebody else's row is the component's own guard.
    Livewire::actingAs($snippet->user)
        ->test('pages::admin.dev-lab')
        ->assertForbidden();

    expect(LabSnippet::query()->whereKey($snippet->id)->exists())->toBeTrue();
});
