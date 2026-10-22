<?php

use App\Models\LabSnippet;
use App\Models\Topic;
use Database\Seeders\TopicsSeeder;
use Flux\FluxManager;

/*
|--------------------------------------------------------------------------
| A topic icon is free text, and it used to be able to take the page down
|--------------------------------------------------------------------------
|
| The `icon` column is a name, not an enum: an operator types it into the topic
| form, a seeder writes it into a file that runs long after whoever chose it has
| moved on, and `<flux:icon>` resolves that name against the glyph set the
| installed Flux ships. A name outside that set is not a missing picture — the
| component throws while the view compiles, and every reader of the taxonomy
| gets the exception. The shipped topics proved it: one of them asked for
| `terminal`, which Flux does not have, and `/topics` answered 500.
|
| So the read is guarded, and the guard is what these tests pin. The name stays
| in the column exactly as it was written — the guard is a read, not a rewrite,
| and a database should not lose an operator's intent because the current glyph
| set has not caught up with it. What changes is that a name nobody can draw
| reads back as nothing, which is the state every view already codes a fallback
| for (`?? 'tag'`).
|
*/

/**
 * Whether the installed Flux can draw this glyph name.
 */
function fluxDraws(?string $name): bool
{
    return $name !== null && $name !== '' && app(FluxManager::class)->componentExists('icon.'.$name);
}

it('gives back an icon the glyph set has, exactly as it was stored', function () {
    $topic = Topic::factory()->create(['icon' => 'server-stack']);

    expect(fluxDraws('server-stack'))->toBeTrue()
        ->and($topic->icon)->toBe('server-stack');
});

it('gives back nothing for a name the glyph set has never heard of', function () {
    $topic = Topic::factory()->create(['icon' => 'terminal']);

    expect(fluxDraws('terminal'))->toBeFalse()
        ->and($topic->icon)->toBeNull();
});

it('keeps the name an operator typed in the column rather than overwriting it', function () {
    $topic = Topic::factory()->create(['icon' => 'terminal']);

    // The read is guarded; the stored value is not edited. A typo stays visible
    // to whoever can fix it, and a glyph added to a future Flux release starts
    // working on its own instead of needing a data migration.
    expect($topic->getRawOriginal('icon'))->toBe('terminal')
        ->and($topic->fresh()->getRawOriginal('icon'))->toBe('terminal');
});

it('treats a blank as no icon at all', function (?string $typed) {
    $topic = Topic::factory()->create(['icon' => null]);
    $topic->update(['icon' => $typed]);

    expect($topic->fresh()->icon)->toBeNull();
})->with([
    'empty string' => '',
    'spaces' => '   ',
    'null' => null,
]);

it('trims the name instead of trusting the field', function () {
    $topic = Topic::factory()->create(['icon' => '  bolt  ']);

    expect($topic->getRawOriginal('icon'))->toBe('bolt')
        ->and($topic->icon)->toBe('bolt');
});

it('still lists every topic when one of them carries an undrawable name', function () {
    $broken = Topic::factory()->create(['icon' => 'terminal']);
    $sound = Topic::factory()->create(['icon' => 'wrench']);

    // The index shows a subject only once something published carries it, so the
    // rows have to be given content before they reach the page that threw.
    foreach ([$broken, $sound] as $topic) {
        LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);
    }

    // Before the guard this request threw: one bad row in a twelve-row table was
    // enough to take the whole index away from every reader.
    $this->get('/topics')
        ->assertOk()
        ->assertSee($broken->name)
        ->assertSee($sound->name);
});

it('still filters the gallery when a facet chip cannot be drawn', function () {
    $topic = Topic::factory()->create(['icon' => 'terminal']);
    LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);

    // The chip is the render site here, and the count gate means the topic only
    // reaches the page at all because a published snippet carries it — which is
    // exactly the path that threw.
    $this->get('/dev-lab/gallery')
        ->assertOk()
        ->assertSee($topic->name);
});

it('ships a topic list whose icons all resolve', function () {
    $this->seed(TopicsSeeder::class);

    $unreadable = Topic::all()
        ->reject(fn (Topic $topic): bool => fluxDraws($topic->getRawOriginal('icon')))
        ->pluck('slug')
        ->all();

    // A seeder is data someone has to read to notice. This is the test that
    // notices, so the next spelling that is not a glyph fails here instead of
    // returning a 500 in production.
    expect($unreadable)->toBe([]);
});

it('ships factory defaults whose icons all resolve', function () {
    $icons = collect(['Topic', 'AiTopic', 'MarketplaceCategory', 'AboutSection'])
        ->mapWithKeys(function (string $model): array {
            $factory = "Database\\Factories\\{$model}Factory";

            return [$model => (new $factory)->raw()['icon'] ?? null];
        })
        ->reject(fn (?string $icon): bool => fluxDraws($icon))
        ->keys()
        ->all();

    // The factories feed every feature test that renders a card, so a default
    // name outside the glyph set would break the suite in a way that looks like
    // a Flux problem rather than a factory one.
    expect($icons)->toBe([]);
});
