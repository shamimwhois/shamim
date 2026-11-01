<?php

use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use App\Services\AboutContent;
use Database\Seeders\AboutContentSeeder;

/*
|--------------------------------------------------------------------------
| About page — dynamic content
|--------------------------------------------------------------------------
|
| The public page renders database rows where they exist and configuration
| or built-in prose where they do not. Pinned here for every block: the
| override, the fallback when the rows are hidden, and the fallback when the
| operator has stored only the section wrapper. If the presenter stops
| consulting the database — or stops consulting the fallback — a test fails.
|
*/

test('stored expertise rows replace the configured capability cards', function () {
    $section = AboutSection::factory()->create([
        'type' => AboutSectionType::Expertise,
        'heading' => 'Where the years went',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'title' => 'Distributed systems',
        'body' => 'Consensus, backpressure, and failure modes that stay boring.',
        'icon' => 'circle-stack',
        'tags' => 'Go, Kafka, Postgres',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Where the years went')
        ->assertSee('Distributed systems')
        ->assertSee('Consensus, backpressure, and failure modes that stay boring.')
        ->assertDontSee('Backend architecture');
});

test('hidden expertise rows fall back to the configured cards', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Expertise]);
    AboutItem::factory()->hidden()->create([
        'about_section_id' => $section->id,
        'title' => 'Distributed systems',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertDontSee('Distributed systems')
        ->assertSee('Backend architecture');
});

test('a section with only a heading keeps the configured rows', function () {
    AboutSection::factory()->create([
        'type' => AboutSectionType::Expertise,
        'heading' => 'What I actually do',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('What I actually do')
        ->assertSee('Backend architecture');
});

test('a hidden section falls back to the built-in wording', function () {
    AboutSection::factory()->hidden()->create([
        'type' => AboutSectionType::Expertise,
        'heading' => 'What I actually do',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertDontSee('What I actually do')
        ->assertSee('Capabilities');
});

test('stored principles replace the built-in prose', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Principles]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'title' => 'Measure before you refactor',
        'body' => 'A profiler costs an afternoon; a rewrite costs a quarter.',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Measure before you refactor')
        ->assertDontSee('Make the wrong thing hard to do');
});

test('stored reasons replace the built-in claims and the navigation label', function () {
    $section = AboutSection::factory()->create([
        'type' => AboutSectionType::Reasons,
        'label' => 'Hire me',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'title' => 'Ships and stays',
        'body' => 'The feature lands, then someone owns it. That someone is me.',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Ships and stays')
        // The stored row replaces the built-in claim, and the stored section
        // label replaces the rail's default wording.
        ->assertDontSee('Backend Engineering')
        ->assertDontSee('Why me');
});

test('stored focus pairs replace the built-in facts', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Focus]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'title' => 'Shipping cadence',
        'value' => 'Two week releases',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Shipping cadence')
        ->assertSee('Two week releases')
        ->assertDontSee('Primary stack');
});

test('the hero section rewrites the role, tagline, and availability', function () {
    AboutSection::factory()->create([
        'type' => AboutSectionType::Hero,
        'heading' => 'Staff Engineer',
        'lead' => 'Builds platforms that stay quiet at three in the morning.',
        'body' => 'Open to staff platform roles.',
    ]);

    $profile = app(AboutContent::class)->profile();

    expect($profile->role)->toBe('Staff Engineer')
        ->and($profile->tagline)->toBe('Builds platforms that stay quiet at three in the morning.')
        ->and($profile->availability)->toBe('Open to staff platform roles.');

    // The pill and the closing heading are the two places the About page shows
    // them. The site footer keeps reading config — it is page chrome, not the
    // About section.
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Staff Engineer')
        ->assertSee('Open to staff platform roles.');
});

test('an experience tree replaces the configured timeline', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Experience]);
    $employer = AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::Employer,
        'title' => 'Hexa Labs',
        'period' => '2021 - present',
    ]);
    $role = AboutItem::factory()->childOf($employer)->create([
        'type' => AboutItemType::Role,
        'title' => 'Platform Engineer',
        'period' => '2021 - 2023',
    ]);
    AboutItem::factory()->childOf($role)->create([
        'type' => AboutItemType::Highlight,
        'title' => 'Cut p95 latency in half',
        'tags' => 'Go, Kafka',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Hexa Labs')
        ->assertSee('Platform Engineer')
        ->assertSee('Cut p95 latency in half')
        ->assertDontSee('Lattice Systems');
});

test('stored work rows replace the configured projects', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Work]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'title' => 'Ledger Core',
        'subtitle' => 'Lead engineer',
        'body' => 'Rebuilt the settlement path around an append-only ledger.',
        'value' => 'Reconciliation now takes minutes',
        'tags' => 'Laravel, Postgres',
        'url' => 'https://github.com/example/ledger-core',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Ledger Core')
        ->assertSee('Reconciliation now takes minutes')
        ->assertSee('https://github.com/example/ledger-core', false)
        ->assertDontSee('Atlas Ledger');
});

test('stored stats replace the configured stat band', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Stats]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'title' => 'Systems migrated',
        'value' => '12',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Systems migrated')
        ->assertDontSee('Systems shipped');
});

test('a contact row rewrites its channel instead of adding another', function () {
    $configured = config('portfolio.author.links');

    $section = AboutSection::factory()->create(['type' => AboutSectionType::Contact]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'title' => 'Email',
        'url' => 'mailto:hire@example.test',
        'value' => 'hire@example.test',
    ]);

    $profile = app(AboutContent::class)->profile();

    // The channel key comes from the slugified title, so a row named 'Email'
    // replaces the configured channel rather than joining the list as a new one.
    expect($profile->link('email')['url'])->toBe('mailto:hire@example.test')
        ->and(array_keys($profile->links))->toBe(array_keys($configured));

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('hire@example.test');
});

test('seeding the about content leaves the page rendering its default copy', function () {
    // The seeder mirrors the fallback layer on purpose: flipping a fresh
    // install from config-driven to database-driven must not move a pixel.
    $this->seed(AboutContentSeeder::class);

    expect(AboutSection::query()->count())->toBe(10)
        ->and(AboutItem::query()->count())->toBeGreaterThan(0);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Backend architecture')
        ->assertSee('Atlas Ledger')
        ->assertSee('Lattice Systems')
        ->assertSee('Make the wrong thing hard to do')
        ->assertSee('Autonomous Engineering');
});

test('stored workflow rows replace the built-in dashboard demo', function () {
    $section = AboutSection::factory()->create([
        'type' => AboutSectionType::AiWorkflow,
        'heading' => 'Delivery Pipeline',
        'lead' => 'Watch the agents move work from trigger to production.',
        'body' => 'Every card on this board is a real pipeline step.',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'Harden the payment webhook',
        'subtitle' => '#SH-2001',
        'body' => 'Verify signatures and add replay protection.',
        'value' => 'review',
        'period' => 'Security agent',
        'url_label' => 'Bug',
        'tags' => 'Payments, Security',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowAgent,
        'title' => 'Auditor Agent',
        'body' => 'Checks every diff',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowMetric,
        'title' => 'Deploys today',
        'subtitle' => 'header',
        'value' => '17',
    ]);

    // The heading renders split across an accent span, so the stored
    // heading and lead are pinned on the presenter; the page check below
    // covers everything that renders as one contiguous string.
    $workflow = app(AboutContent::class)->aiWorkflow();

    expect($workflow['heading'])->toBe('Delivery Pipeline')
        ->and($workflow['lead'])->toBe('Watch the agents move work from trigger to production.');

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Watch the agents move work from trigger to production.')
        ->assertSee('Every card on this board is a real pipeline step.')
        ->assertSee('Harden the payment webhook')
        ->assertSee('#SH-2001')
        ->assertSee('Auditor Agent')
        ->assertSee('Deploys today')
        ->assertDontSee('Redesign navbar profile')
        ->assertDontSee('Tasks completed');
});

test('the workflow band falls back to the built-in demo when its rows are hidden', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::AiWorkflow]);
    AboutItem::factory()->hidden()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::WorkflowTask,
        'title' => 'Withdrawn card',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Autonomous Engineering')
        ->assertSee('Redesign navbar profile')
        ->assertDontSee('Withdrawn card');
});

test('the thinking explorer renders its built-in defaults', function () {
    // The value claims used to render under this band. They are section 02's
    // specification now, so this test only pins the explorer's own content.
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Problem Solving')
        ->assertSee('System 1 — Fast thinking')
        ->assertSee('Reproduce first');
});

test('stored thinking rows replace the built-in explorer content', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Reasons]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Model Context',
        'subtitle' => 'model-context',
        'body' => 'How context is gathered, cached and spent.',
        'url_label' => 'deep',
    ]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingNode,
        'title' => 'Context budgets',
        'subtitle' => 'model-context',
        'body' => 'A token spent on context is a token not spent on reasoning.',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Model Context')
        ->assertSee('Context budgets')
        // By key, not by name: "Client & Team Focus" is a claim
        // card now, so the name is on the page either way.
        ->assertDontSee('data-hub-key="problem-solving"', false);
});

test('a typo in a stored category key degrades to a safe slug', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Reasons]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Edge Cases',
        'subtitle' => 'Not A Key!!',
        'body' => 'Stored with an unusable key.',
    ]);

    $explorer = app(AboutContent::class)->thinkingExplorer();

    expect($explorer['categories'][0]['key'])->toBe('not-a-key')
        ->and($explorer['categories'][0]['name'])->toBe('Edge Cases')
        ->and($explorer['categories'][0]['affinity'])->toBe('both');
});

test('thinking rows never leak into the claim cards', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Reasons]);
    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingMode,
        'title' => 'Stored mode',
        'subtitle' => 'system_2',
        'body' => 'Stored mode description.',
    ]);

    // A thinking row feeds the explorer's mode legend; it must not become a
    // claim card. The six claims are still the Reasons section's own rows.
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Stored mode')
        ->assertSee('Backend Engineering')
        ->assertSee('Client & Team Focus');
});
