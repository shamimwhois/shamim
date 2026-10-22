<?php

namespace Database\Seeders;

use App\Actions\DevLab\SaveLabSnippet;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the Developer Lab's example snippets.
 *
 * Four rows and nothing else. A workspace is a scratch buffer, so seeding one
 * would put invented markup in front of the first account that opens the editor and
 * make "recent work" a lie; a snippet, by contrast, is a published object, and the
 * public gallery is an empty page without at least one. The four are the smallest
 * corpus that covers what the module claims: three runnable panes, so the preview
 * button has something to show, plus a PHP row, so the page that says
 * "there is no Run button for PHP" is a claim a reviewer can click rather than a
 * sentence in a landing page.
 *
 * Everything is written through {@see SaveLabSnippet}, the only writer of the
 * visibility/published_at pair, so a seeded snippet goes down the same path as one
 * saved from `/dev-lab/snippets/create` and cannot be published in a shape the
 * action would refuse.
 *
 * Idempotent by slug: the row is looked up before it is written and passed back into
 * the action, so a second `db:seed` updates the code in place instead of minting
 * `card-anatomy-2`. That is what makes it safe to leave in `DatabaseSeeder`.
 *
 * Each example is also filed under a few platform subjects, so the gallery's facet
 * row is not born counting nothing. `defaults()` names those subjects by slug for
 * readability and they are resolved to ids here, which means a deactivated topic
 * drops out of a re-seed rather than being resurrected by it.
 */
class LabSnippetSeeder extends Seeder
{
    public function __construct(
        protected SaveLabSnippet $saveSnippet,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $author = User::query()->orderBy('id')->firstOrFail();

        // Resolved once because the subject slugs repeat across examples, and a
        // topic an operator has deactivated simply drops out of the map: the
        // example still seeds, it just stops advertising a subject that is off.
        $topicsBySlug = Topic::query()->active()->get()->keyBy('slug');

        foreach (self::defaults() as $example) {
            $existing = LabSnippet::query()->firstWhere('slug', $example['slug']);

            $topicIds = collect($example['topics'])
                ->map(fn (string $slug): ?int => $topicsBySlug->get($slug)?->id)
                ->filter()
                ->values()
                ->all();

            // Every example is meant to be readable on the public gallery, which is
            // the only reason this module ships seeded rows at all. The pair is still
            // written by the action, so a seeder cannot publish something the console
            // would refuse.
            ($this->saveSnippet)->handle(
                $author,
                [
                    ...$example,
                    'visibility' => ContentVisibility::Public,
                    // Replaces rather than joins `defaults()`'s entry of the same name,
                    // which holds slugs for readability. A `+` here would keep the slug
                    // list — the left operand wins on a duplicate key — and the sync
                    // would drop every one of them for not being an id.
                    'topics' => $topicIds,
                ],
                $existing,
            );
        }
    }

    /**
     * The examples, in the order the sandbox composes them.
     *
     * Each carries the subject slugs it is filed under, so the gallery is not born
     * with a chip row that opens nothing. `topics` are the platform list from
     * {@see TopicsSeeder}, not the author's private folders.
     *
     * @return array<int, array{language: LabLanguage, title: string, slug: string, description: string, code: string, topics: array<int, string>}>
     */
    public static function defaults(): array
    {
        return [
            [
                'language' => LabLanguage::Html,
                'title' => 'Card anatomy',
                'slug' => 'card-anatomy',
                'description' => 'A title, a line of body copy and a footer, the shape most interface cards reduce to. Preview it on its own and it is unstyled, which is the point: this is the markup half of a trio.',
                'topics' => ['interfaces', 'layout'],
                'code' => <<<'HTML'
                    <article class="card">
                      <h1 class="card-title">Card anatomy</h1>
                      <p class="card-body">
                        Three blocks that appear in nearly every interface card:
                        a title, a body, and a footer with the metadata.
                      </p>
                      <footer class="card-footer">
                        <span>Updated today</span>
                      </footer>
                    </article>
                    HTML,
            ],
            [
                'language' => LabLanguage::Css,
                'title' => 'Soft shadow, one radius',
                'slug' => 'soft-shadow-one-radius',
                'description' => 'The two properties that carry the soft UI look: one radius scale and a shadow tinted towards the background rather than towards black. Paste it into the CSS pane above the HTML example.',
                'topics' => ['css', 'layout'],
                'code' => <<<'CSS'
                    :root {
                      --radius: 16px;
                      --ink: #111827;
                      --hairline: rgb(17 24 39 / 0.08);
                    }

                    body {
                      margin: 0;
                      padding: 2rem;
                      background: #f6f7f9;
                      font: 15px/1.55 system-ui, sans-serif;
                      color: var(--ink);
                    }

                    .card {
                      max-width: 26rem;
                      padding: 1.25rem;
                      border: 1px solid var(--hairline);
                      border-radius: var(--radius);
                      background: #fff;
                      /* Tinted, not black: the shadow reads as thickness. */
                      box-shadow: 0 12px 28px rgb(17 24 39 / 0.06);
                    }

                    .card-title {
                      margin: 0 0 0.5rem;
                      font-size: 1.05rem;
                      letter-spacing: -0.01em;
                    }

                    .card-body {
                      margin: 0 0 1rem;
                      color: #4b5563;
                    }

                    .card-footer {
                      display: flex;
                      padding-top: 0.75rem;
                      border-top: 1px solid var(--hairline);
                      font-size: 0.75rem;
                      color: #9ca3af;
                    }
                    CSS,
            ],
            [
                'language' => LabLanguage::Javascript,
                'title' => 'Debounce a callback',
                'slug' => 'debounce-a-callback',
                'description' => 'The helper that stops a search field firing on every keystroke. It logs on each call and again when the typing settles, so the difference between the two is visible in the console pane.',
                'topics' => ['javascript', 'forms', 'performance'],
                'code' => <<<'JS'
                    function debounce(fn, wait = 300) {
                      let timer;

                      return (...args) => {
                        clearTimeout(timer);
                        timer = setTimeout(() => fn(...args), wait);
                      };
                    }

                    const onSettled = debounce((value) => {
                      console.log('search fired for', value);
                    }, 400);

                    for (const keystroke of ['la', 'lar', 'lara', 'larav']) {
                      console.log('keystroke', keystroke);
                      onSettled(keystroke);
                    }

                    console.warn('only the last keystroke should reach the search');
                    JS,
            ],
            [
                'language' => LabLanguage::Php,
                'title' => 'Celsius to Fahrenheit',
                'slug' => 'celsius-to-fahrenheit',
                'description' => 'Stored, listed, copyable, and deliberately unrunnable here. There is no server-side runner in this lab, so a PHP snippet is a thing you read and take away, not a thing you preview.',
                'topics' => ['php'],
                'code' => <<<'PHP'
                    <?php

                    function celsiusToFahrenheit(float $celsius): float
                    {
                        return $celsius * 9 / 5 + 32;
                    }

                    foreach ([0, 20, 37] as $degrees) {
                        printf("%s°C is %s°F\n", $degrees, round(celsiusToFahrenheit($degrees), 1));
                    }
                    PHP,
            ],
        ];
    }
}
