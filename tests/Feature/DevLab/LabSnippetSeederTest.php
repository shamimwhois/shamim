<?php

use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use Database\Seeders\LabSnippetSeeder;
use Database\Seeders\TopicsSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The four example snippets
|--------------------------------------------------------------------------
|
| The seeder ships the corpus, the taxonomy and the publication pair, and this file
| pins all three. First the coverage: the corpus must include every language the lab
| claims to know, including the one it will not run. Then the filing: each example
| must arrive carrying the subjects the seeder names, because a gallery whose facet
| row counts nothing is the state the module's own privacy rule forbids. And finally
| that re-running the seeder renames nothing, duplicates nothing and re-syncs the
| pivots instead of stacking them, which is what lets it stay in `DatabaseSeeder`
| rather than hiding behind a flag.
|
| Publication is asserted through the pair rather than through `isPublished()`
| alone, because a seeder that wrote `visibility` directly would produce a row the
| action refuses to make and every list would still filter it out correctly only by
| accident.
|
*/

test('the seeded gallery covers every language the lab knows', function () {
    $this->seed(UserSeeder::class);
    $this->seed(LabSnippetSeeder::class);

    foreach (LabLanguage::cases() as $language) {
        expect(LabSnippet::query()->inLanguage($language)->exists())
            ->toBeTrue("No seeded snippet is written in [{$language->value}].");
    }
});

test('every seeded snippet is published through the pair the module reads', function () {
    $this->seed(UserSeeder::class);
    $this->seed(LabSnippetSeeder::class);

    LabSnippet::query()->each(function (LabSnippet $snippet): void {
        expect($snippet->visibility)->toBe(ContentVisibility::Public)
            ->and($snippet->published_at)->not->toBeNull()
            ->and($snippet->isPublished())->toBeTrue()
            ->and(trim($snippet->code))->not->toBe('');
    });
});

test('the seeded PHP example is stored without being runnable', function () {
    $this->seed(UserSeeder::class);
    $this->seed(LabSnippetSeeder::class);

    $php = LabSnippet::query()->inLanguage(LabLanguage::Php)->first();

    expect($php)->not->toBeNull()
        ->and($php->language->isBrowserRunnable())->toBeFalse()
        ->and($php->code)->toStartWith('<?php');
});

test('every seeded snippet is filed under the subjects the seeder names', function () {
    $this->seed(UserSeeder::class);
    $this->seed(TopicsSeeder::class);
    $this->seed(LabSnippetSeeder::class);

    // The seeder asks for its subjects by slug and the action files them by id. A
    // payload that kept the slug list would still pass `is_array`, reach the sync,
    // and have every entry dropped for not being an id — four rows carrying nothing,
    // and a gallery whose facet row counts nothing.
    foreach (LabSnippet::query()->with('topics')->get() as $snippet) {
        expect($snippet->topics)->not->toBeEmpty();
    }

    $pivots = DB::table('lab_snippet_topic')->count();

    // Syncing rather than inserting, so the taxonomy survives a second `db:seed`
    // with the same rows it had after the first.
    $this->seed(LabSnippetSeeder::class);

    expect(DB::table('lab_snippet_topic')->count())->toBe($pivots);
});

test('the seeded pages resolve, so the gallery is not a wall of dead links', function () {
    $this->seed(UserSeeder::class);
    $this->seed(LabSnippetSeeder::class);

    $this->get('/dev-lab/gallery')
        ->assertOk()
        ->assertSee('Card anatomy');

    foreach (LabSnippet::query()->get() as $snippet) {
        $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->assertOk();
    }
});

test('re-running the seeder neither duplicates nor re-slugs a row', function () {
    $this->seed(UserSeeder::class);
    $this->seed(LabSnippetSeeder::class);

    $before = LabSnippet::query()->pluck('slug')->sort()->values()->all();
    $ids = LabSnippet::query()->pluck('id')->sort()->values()->all();

    $this->seed(LabSnippetSeeder::class);

    expect(LabSnippet::query()->count())->toBe(count($before))
        ->and(LabSnippet::query()->pluck('slug')->sort()->values()->all())->toBe($before)
        // The same rows, updated in place, rather than deleted and recreated.
        ->and(LabSnippet::query()->pluck('id')->sort()->values()->all())->toBe($ids);
});
