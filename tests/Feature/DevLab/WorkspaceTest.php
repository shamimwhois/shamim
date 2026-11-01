<?php

use App\Actions\DevLab\SaveLabWorkspace;
use App\Models\LabWorkspace;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Workspaces: the buffer behind the sandbox
|--------------------------------------------------------------------------
|
| A workspace is what is in the three panes right now, and it is the one object in
| the module that is never public. That makes two properties worth pinning: the
| buffer upserts rather than accumulates, and a foreign id reads as "not yours"
| instead of as somebody else's code.
|
| The second is the interesting one. The id arrives in a query string, so the
| screen can be handed to anybody, and a component that loaded by id alone would
| paste a stranger's markup into their editor. The lookup is scoped to the signed-in
| account in every place it happens, and these tests are how that is known rather
| than assumed.
|
*/

test('saving a workspace the first time creates it and stamps the id', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Card grid')
        ->set('html', '<div class="grid"></div>')
        ->set('css', '.grid { display: grid; }')
        ->call('save')
        ->assertHasNoErrors();

    $workspace = LabWorkspace::query()->first();

    expect(LabWorkspace::query()->count())->toBe(1)
        ->and($workspace->user_id)->toBe($user->id)
        ->and($workspace->name)->toBe('Card grid')
        ->and($workspace->css)->toBe('.grid { display: grid; }');
});

test('saving twice updates the same buffer instead of forking it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Card grid')
        ->set('html', '<p>first</p>')
        ->call('save')
        ->set('html', '<p>second</p>')
        ->call('save');

    expect(LabWorkspace::query()->count())->toBe(1)
        ->and(LabWorkspace::query()->first()->html)->toBe('<p>second</p>');
});

test('a workspace is opened by id for its author and loaded into the panes', function () {
    $workspace = LabWorkspace::factory()->create([
        'html' => '<h1>Only mine</h1>',
        'name' => 'Owned buffer',
    ]);

    $this->actingAs($workspace->user)
        ->get('/dev-lab/sandbox?workspace='.$workspace->id)
        ->assertOk()
        ->assertSee('Owned buffer');
});

test('a foreign workspace id does not load another account’s code', function () {
    $workspace = LabWorkspace::factory()->create([
        'name' => 'Somebody else',
        'html' => '<h1>private markup</h1>',
    ]);

    $page = $this->actingAs(User::factory()->create())
        ->get('/dev-lab/sandbox?workspace='.$workspace->id)
        ->assertOk();

    // Not a 403: the id is not a resource the visitor was denied, it is one they
    // do not have, and the screen simply starts clean.
    $page->assertDontSee('Somebody else')
        ->assertDontSee('private markup');
});

test('discarding deletes the row and empties the buffer', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Throwaway')
        ->set('html', '<p>gone</p>')
        ->call('save');

    expect(LabWorkspace::query()->count())->toBe(1);

    $component->call('discard')->assertHasNoErrors();

    expect(LabWorkspace::query()->count())->toBe(0);
});

test('discarding an unsaved buffer writes nothing and stays harmless', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox')
        ->set('html', '<p>never saved</p>')
        ->call('discard');

    expect(LabWorkspace::query()->count())->toBe(0);
});

test('code past the ceiling is refused rather than truncated in silence', function () {
    config(['dev_lab.max_code_bytes' => 64]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Too wide')
        ->set('html', str_repeat('<p>x</p>', 40))
        ->call('save');

    expect(LabWorkspace::query()->count())->toBe(0);
});

test('the save action stores a blank pane as null rather than as an empty string', function () {
    $user = User::factory()->create();

    $workspace = app(SaveLabWorkspace::class)->handle($user, [
        'name' => 'HTML only',
        'html' => '<p>only html</p>',
    ]);

    expect($workspace->html)->toBe('<p>only html</p>')
        ->and($workspace->css)->toBeNull()
        ->and($workspace->js)->toBeNull();
});

test('the dashboard lists only this account’s workspaces', function () {
    $mine = LabWorkspace::factory()->create(['name' => 'Visible to me']);
    $theirs = LabWorkspace::factory()->create(['name' => 'Not visible']);

    $this->actingAs($mine->user)
        ->get('/dev-lab/dashboard')
        ->assertOk()
        ->assertSee('Visible to me')
        ->assertDontSee('Not visible');
});
