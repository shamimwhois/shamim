<?php

namespace Database\Seeders;

use App\Actions\AiHub\SaveAiArticle;
use App\Actions\AiHub\SaveAiLearningPath;
use App\Actions\AiHub\SaveAiResource;
use App\Actions\AiHub\SaveAiTopic;
use App\Actions\AiHub\SyncAiPublicationStatus;
use App\Actions\AiHub\SyncAiRelatedArticles;
use App\Enums\AiArticleFormat;
use App\Enums\AiDifficulty;
use App\Enums\AiLinkKind;
use App\Enums\AiPublicationStatus;
use App\Enums\AiResourceKind;
use App\Models\AiArticle;
use App\Models\AiTopic;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a browsable AI Knowledge Hub.
 *
 * Every row is written through the same actions the admin console uses, so the
 * seeded corpus exercises the real pipeline: derived article projections come
 * from HydrateAiArticleContent, topic filing goes through SyncAiArticleTopics,
 * and nothing becomes public without an explicit
 * {@see SyncAiPublicationStatus} transition — the same rule the console obeys.
 */
class AiHubSeeder extends Seeder
{
    public function __construct(
        protected SaveAiTopic $saveTopic,
        protected SaveAiArticle $saveArticle,
        protected SaveAiLearningPath $savePath,
        protected SaveAiResource $saveResource,
        protected SyncAiPublicationStatus $syncStatus,
        protected SyncAiRelatedArticles $syncRelated,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $author = User::query()->orderBy('id')->firstOrFail();

        $topics = $this->seedTopics();
        $articles = $this->seedArticles($author, $topics);
        $this->seedPaths($author, $topics, $articles);
        $this->seedResources($author, $topics);
    }

    /**
     * Create the taxonomy, including one nested child and one retired root.
     *
     * @return array<string, AiTopic> keyed by slug
     */
    protected function seedTopics(): array
    {
        $roots = [
            [
                'name' => 'Getting Started',
                'slug' => 'getting-started',
                'icon' => 'map',
                'sort_order' => 0,
                'description' => 'First steps with the hub: how to read it, what to skip, and where to begin.',
            ],
            [
                'name' => 'Prompt Engineering',
                'slug' => 'prompt-engineering',
                'icon' => 'bookmark',
                'sort_order' => 1,
                'description' => 'Shaping model behaviour with instructions, examples, and structure.',
            ],
            [
                'name' => 'AI Tooling',
                'slug' => 'ai-tooling',
                'icon' => 'wrench',
                'sort_order' => 2,
                'description' => 'The software around the models: frameworks, runners, and local stacks.',
            ],
            [
                'name' => 'Evals & Quality',
                'slug' => 'evals-quality',
                'icon' => 'beaker',
                'sort_order' => 3,
                'description' => 'Measuring what a model actually does, rather than what it sounds like it does.',
            ],
            [
                'name' => 'Hardware Notes',
                'slug' => 'hardware-notes',
                'icon' => 'queue-list',
                'sort_order' => 4,
                'is_active' => false,
                'description' => 'Retired: GPU and VRAM notes moved into the tooling articles.',
            ],
        ];

        $topics = [];

        foreach ($roots as $definition) {
            $topic = $this->saveTopic->handle($definition);
            $topics[$topic->slug] = $topic;
        }

        $child = $this->saveTopic->handle([
            'name' => 'Prompt Patterns',
            'slug' => 'prompt-patterns',
            'parent_id' => $topics['prompt-engineering']->id,
            'icon' => 'queue-list',
            'sort_order' => 0,
            'description' => 'Reusable shapes: system prompts, few-shot framing, and structured output.',
        ]);

        $topics[$child->slug] = $child;

        return $topics;
    }

    /**
     * Write the article corpus and file it against the taxonomy.
     *
     * @param  array<string, AiTopic>  $topics
     * @return array<string, AiArticle> keyed by slug
     */
    protected function seedArticles(User $author, array $topics): array
    {
        $articles = [];

        foreach ($this->articleDefinitions($topics) as $definition) {
            $topicIds = array_filter(array_map(
                fn (string $slug): ?int => isset($topics[$slug]) ? $topics[$slug]->id : null,
                $definition['topics'],
            ));

            $article = $this->saveArticle->handle(
                $author,
                [
                    'title' => $definition['title'],
                    'slug' => $definition['slug'],
                    'summary' => $definition['summary'],
                    'body' => $definition['body'],
                    'format' => $definition['format'],
                    'difficulty' => $definition['difficulty'],
                    'is_featured' => $definition['featured'] ?? false,
                    'prerequisites' => $definition['prerequisites'] ?? null,
                ],
                null,
                $topicIds,
                $definition['links'] ?? [],
            );

            if ($definition['published']) {
                $this->syncStatus->handle($article, AiPublicationStatus::Published);
                $article->published_at = Carbon::now()->subDays($definition['age_days']);
                $article->view_count = $definition['views'] ?? 0;
                $article->save();
            } elseif (($definition['status'] ?? null) === AiPublicationStatus::Review) {
                // Draft -> review is a real workflow move, so it goes through
                // the single writer of the status invariant like everything else.
                $this->syncStatus->handle($article, AiPublicationStatus::Review);
                $article->save();
            }

            $articles[$article->slug] = $article->refresh();
        }

        // A small curated reading list between the two prompting articles so
        // the related-articles rail on the public detail page has content.
        if (isset($articles['how-to-read-this-hub'], $articles['writing-prompts-that-survive-review'])) {
            $this->syncRelated->handle($articles['how-to-read-this-hub'], [
                $articles['writing-prompts-that-survive-review']->id,
            ]);
        }

        return $articles;
    }

    /**
     * The article corpus: two paths-worth of lessons, a review-state draft, and
     * a draft that is deliberately kept unpublished.
     *
     * @param  array<string, AiTopic>  $topics
     * @return array<int, array<string, mixed>>
     */
    protected function articleDefinitions(array $topics): array
    {
        return [
            [
                'title' => 'How to Read This Hub',
                'slug' => 'how-to-read-this-hub',
                'summary' => 'The hub is organised by topic, not by feed order. Here is the shortest route through it.',
                'format' => AiArticleFormat::Guide,
                'difficulty' => AiDifficulty::Beginner,
                'topics' => ['getting-started'],
                'published' => true,
                'featured' => true,
                'age_days' => 40,
                'views' => 312,
                'body' => <<<'MARKDOWN'
                ## Start with a topic, not the feed

                Every article is filed under at least one topic. Topics answer *what this is
                about*; the format badge answers *what kind of thing this is*. A glossary
                entry can live in the same topic as a full tutorial, and neither one is
                misfiled.

                ## Follow a learning path when you want an order

                Learning paths are operator-sequenced lesson lists. If you are new to a
                subject, a path is the intended way in.

                ## Trust the dates

                Published articles keep their original publication date even after edits,
                so a path that cites an article is always pointing at the version readers
                saw.
                MARKDOWN,
            ],
            [
                'title' => 'Writing Prompts That Survive Review',
                'slug' => 'writing-prompts-that-survive-review',
                'summary' => 'A checklist for turning a vague instruction into a prompt a reviewer can actually verify.',
                'format' => AiArticleFormat::Tutorial,
                'difficulty' => AiDifficulty::Intermediate,
                'topics' => ['prompt-engineering', 'prompt-patterns'],
                'published' => true,
                'featured' => true,
                'age_days' => 28,
                'views' => 540,
                'links' => [
                    [
                        'label' => 'OpenAI prompt engineering guide',
                        'url' => 'https://platform.openai.com/docs/guides/prompt-engineering',
                        'kind' => AiLinkKind::Documentation,
                        'note' => 'The canonical starting point.',
                    ],
                ],
                'body' => <<<'MARKDOWN'
                ## Make the success condition checkable

                A prompt survives review when a second person can tell whether the output
                met it. "Write a friendly email" cannot be checked; "under 120 words, one
                question, no exclamation marks" can.

                ```php
                $rules = ['max_words' => 120, 'questions' => 1, 'exclamations' => 0];
                ```

                ## State the format before the content

                Models follow structure better than adjectives. Ask for headings, a table,
                or a JSON shape first, then fill it in.

                ## Keep one example per rule

                Few-shot examples teach better than instructions, but only when each
                example demonstrates a single rule. Two rules in one example teach neither.
                MARKDOWN,
            ],
            [
                'title' => 'Running a Local Model Stack',
                'slug' => 'running-a-local-model-stack',
                'summary' => 'What a laptop-sized inference stack actually needs, and what it can safely skip.',
                'format' => AiArticleFormat::Architecture,
                'difficulty' => AiDifficulty::Advanced,
                'topics' => ['ai-tooling'],
                'published' => true,
                'age_days' => 21,
                'views' => 198,
                'prerequisites' => 'Comfort with the command line and basic GPU terminology.',
                'links' => [
                    [
                        'label' => 'llama.cpp repository',
                        'url' => 'https://github.com/ggml-org/llama.cpp',
                        'kind' => AiLinkKind::Repository,
                    ],
                ],
                'body' => <<<'MARKDOWN'
                ## The three layers

                A local stack is a runtime, a serving layer, and a client. You can replace
                any one of them without touching the others, and most troubleshooting is
                figuring out which layer is actually failing.

                ## What you can skip

                You do not need a queue, a router, or an observability stack on day one.
                You do need a pinned model version, because "it worked yesterday" is
                untestable across weight updates.

                ## Quantisation is a trade, not a default

                Smaller quants run on less memory and answer differently. Pick the largest
                quant your evaluation set tolerates, not the smallest your hardware allows.
                MARKDOWN,
            ],
            [
                'title' => 'Designing a Small Evaluation Set',
                'slug' => 'designing-a-small-evaluation-set',
                'summary' => 'Twenty good cases beat two hundred borrowed ones. How to build evals you will actually maintain.',
                'format' => AiArticleFormat::TechnicalNote,
                'difficulty' => AiDifficulty::Intermediate,
                'topics' => ['evals-quality'],
                'published' => true,
                'age_days' => 12,
                'views' => 87,
                'body' => <<<'MARKDOWN'
                ## Write cases from your own failures

                Every evaluation case should trace back to something that went wrong, or
                nearly did, in real use. Borrowed benchmarks measure somebody else's risk.

                ## One assertion per case

                A case that checks three things tells you nothing when it fails. Split it.

                ## Version the grader with the data

                When the pass condition changes, old scores stop meaning the same thing.
                Record which grader produced which number.
                MARKDOWN,
            ],
            [
                'title' => 'Glossary: Context Window',
                'slug' => 'glossary-context-window',
                'summary' => 'What the term "context window" means, and the two misconceptions it carries.',
                'format' => AiArticleFormat::Glossary,
                'difficulty' => AiDifficulty::Beginner,
                'topics' => ['getting-started'],
                'published' => true,
                'age_days' => 8,
                'views' => 140,
                'body' => <<<'MARKDOWN'
                ## Definition

                The context window is the span of tokens a model attends to in a single
                request: the prompt, any retrieved material, and the generated answer.

                ## Misconception one: it is memory

                It is not. Nothing outside the window is available to the model at all,
                and nothing inside it persists once the request ends.

                ## Misconception two: full is fast

                Attention cost grows with window fill. A half-full window of junk is
                slower and worse than a small window of exactly the right material.
                MARKDOWN,
            ],
            [
                'title' => 'Measuring Retrieval Quality Without a Benchmark',
                'slug' => 'measuring-retrieval-quality-without-a-benchmark',
                'summary' => 'Draft: a lightweight hit-rate ritual for RAG systems that answers to no published leaderboard.',
                'format' => AiArticleFormat::Experiment,
                'difficulty' => AiDifficulty::Advanced,
                'topics' => ['evals-quality'],
                'published' => false,
                'status' => AiPublicationStatus::Review,
                'body' => <<<'MARKDOWN'
                ## The ritual

                Once a week, take five questions users actually asked, retrieve for each,
                and write down whether the right document appeared in the top three. Five
                rows, two minutes.

                ## Why it works

                It is cheap enough to survive a busy sprint, and it measures retrieval for
                the questions you have, not the questions a benchmark author had.
                MARKDOWN,
            ],
            [
                'title' => 'A Case Study in Over-Prompting',
                'slug' => 'case-study-over-prompting',
                'summary' => 'Draft: what happened when a support bot accumulated eleven system prompts, and how we cut it back.',
                'format' => AiArticleFormat::CaseStudy,
                'difficulty' => AiDifficulty::Intermediate,
                'topics' => ['prompt-engineering'],
                'published' => false,
                'body' => <<<'MARKDOWN'
                ## The situation

                Each escalation added a paragraph to the system prompt. After a year there
                were eleven of them, several contradicting, none tested.

                ## What we tried

                We removed every rule that had no linked incident, then re-added only the
                ones whose absence produced a reproducible failure.

                ## What happened

                The prompt got 60% shorter and the failure rate did not move. The rules
                had been guarding against a problem that stopped existing months earlier.
                MARKDOWN,
            ],
        ];
    }

    /**
     * Sequence two learning paths, publishing the one whose lessons are ready.
     *
     * @param  array<string, AiTopic>  $topics
     * @param  array<string, AiArticle>  $articles
     */
    protected function seedPaths(User $author, array $topics, array $articles): void
    {
        $beginnerLessons = array_values(array_filter([
            $articles['how-to-read-this-hub']->id ?? null,
            $articles['glossary-context-window']->id ?? null,
            $articles['writing-prompts-that-survive-review']->id ?? null,
        ]));

        $beginner = $this->savePath->handle(
            $author,
            [
                'title' => 'Your First Week in the Hub',
                'slug' => 'your-first-week-in-the-hub',
                'description' => 'The ordered route from "what is this" to writing prompts a reviewer can verify.',
                'difficulty' => AiDifficulty::Beginner,
                'ai_topic_id' => $topics['getting-started']->id,
                'is_featured' => true,
                'prerequisites' => 'Nothing. It assumes you have never opened the hub.',
            ],
            null,
            array_map(fn (int $id): array => ['ai_article_id' => $id], $beginnerLessons),
        );

        $this->syncStatus->handle($beginner, AiPublicationStatus::Published);
        $beginner->published_at = Carbon::now()->subDays(30);
        $beginner->save();

        $this->savePath->handle(
            $author,
            [
                'title' => 'Evaluating Models You Actually Ship',
                'slug' => 'evaluating-models-you-actually-ship',
                'description' => 'Draft path: building and maintaining evals that answer to your users, not a leaderboard.',
                'difficulty' => AiDifficulty::Advanced,
                'ai_topic_id' => $topics['evals-quality']->id,
                'prerequisites' => 'The beginner path, or equivalent prompting experience.',
            ],
            null,
            [
                ['ai_article_id' => $articles['designing-a-small-evaluation-set']->id, 'title' => null, 'note' => 'Read the retrieval note after this one.'],
                ['ai_article_id' => $articles['case-study-over-prompting']->id],
            ],
        );
    }

    /**
     * Fill the resources shelf: a mix of kinds, two drafts, one retired entry.
     *
     * @param  array<string, AiTopic>  $topics
     */
    protected function seedResources(User $author, array $topics): void
    {
        $definitions = [
            [
                'title' => 'Ollama',
                'description' => 'The quickest way to get a model running on your own machine.',
                'url' => 'https://ollama.com',
                'kind' => AiResourceKind::Tool,
                'provider' => 'Ollama Inc.',
                'ai_topic_id' => $topics['ai-tooling']->id,
                'published' => true,
                'featured' => true,
            ],
            [
                'title' => 'LlamaIndex',
                'description' => 'Data framework for connecting your own content to an LLM.',
                'url' => 'https://github.com/run-llama/llama_index',
                'kind' => AiResourceKind::Library,
                'provider' => 'LlamaIndex',
                'ai_topic_id' => $topics['ai-tooling']->id,
                'published' => true,
            ],
            [
                'title' => 'OpenAI Prompt Engineering Guide',
                'description' => 'Reference strategies from the OpenAI docs team.',
                'url' => 'https://platform.openai.com/docs/guides/prompt-engineering',
                'kind' => AiResourceKind::Documentation,
                'provider' => 'OpenAI',
                'ai_topic_id' => $topics['prompt-engineering']->id,
                'published' => true,
            ],
            [
                'title' => 'Stanford CRFM: Foundation Models Report',
                'description' => 'The introduction to the 2021 foundation-models paper that started most of this.',
                'url' => 'https://crfm.stanford.edu/2021/08/16/introducing-fms.html',
                'kind' => AiResourceKind::Paper,
                'provider' => 'Stanford CRFM',
                'published' => true,
            ],
            [
                'title' => 'Anthropic Prompt Library',
                'description' => 'Draft: curated example prompts by task, worth filing under patterns.',
                'url' => 'https://docs.anthropic.com/en/prompt-library/library',
                'kind' => AiResourceKind::Documentation,
                'provider' => 'Anthropic',
                'ai_topic_id' => $topics['prompt-patterns']->id,
                'published' => false,
            ],
            [
                'title' => 'Deprecated: Free-tier GPU Notebook Roundup',
                'description' => 'Retired: every provider in this list changed their free tier within months.',
                'url' => 'https://example.com/gpu-notebook-roundup',
                'kind' => AiResourceKind::Course,
                'provider' => 'Anonymous',
                'status' => AiPublicationStatus::Archived,
                'published' => false,
            ],
        ];

        foreach ($definitions as $definition) {
            $resource = $this->saveResource->handle($author, [
                'title' => $definition['title'],
                'description' => $definition['description'],
                'url' => $definition['url'],
                'kind' => $definition['kind'],
                'provider' => $definition['provider'],
                'ai_topic_id' => $definition['ai_topic_id'] ?? null,
                'is_featured' => $definition['featured'] ?? false,
            ]);

            if ($definition['published']) {
                $this->syncStatus->handle($resource, AiPublicationStatus::Published);
                $resource->published_at = Carbon::now()->subDays(15);
                $resource->save();
            } elseif (($definition['status'] ?? null) === AiPublicationStatus::Archived) {
                $this->syncStatus->handle($resource, AiPublicationStatus::Archived);
                $resource->save();
            }
        }
    }
}
