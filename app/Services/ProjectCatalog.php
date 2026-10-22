<?php

namespace App\Services;

use App\Data\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The catalogue behind the about page's Selected work grid and `/work/{project}`.
 *
 * Projects are content — configured in `portfolio.author.projects` on a fresh
 * install, replaced by `about_items` rows once an operator manages the page. The
 * catalogue reads the same profile the about page renders, so a project card and
 * its detail page cannot disagree about a name, a role or an outcome. Docs are a
 * separate concern: they are looked up by slug in configuration, and the site's
 * own project reads the repository's `docs/*.md` files instead.
 *
 * Everything is memoised per request; the about page asks for the list once and
 * a docs page asks for the same index in the sidebar, the article and the
 * prev/next links.
 */
class ProjectCatalog
{
    /**
     * @var array<int, Project>|null
     */
    protected ?array $projects = null;

    /**
     * @var array<string, array<int, array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string}>>
     */
    protected array $documents = [];

    /**
     * Rendered documents, keyed by project and doc slug.
     *
     * The header metadata and the article both ask for the same document during
     * one request; rendering a long page twice would be the most expensive thing
     * on the screen.
     *
     * @var array<string, array<string, string>>
     */
    protected array $rendered = [];

    public function __construct(
        protected AboutContent $about,
    ) {}

    /**
     * Every project, in the order the about page prints them.
     *
     * The site's own dossier is appended rather than stored with the others: it is
     * the one project whose documentation is the repository itself, and it should
     * not disappear when an operator replaces the configured work rows.
     *
     * @return array<int, Project>
     */
    public function all(): array
    {
        if ($this->projects !== null) {
            return $this->projects;
        }

        $projects = [];

        foreach ($this->about->profile()->projects as $entry) {
            $name = trim($entry['name']);

            if ($name === '') {
                continue;
            }

            $slug = $this->uniqueSlug($entry['slug'] ?? Str::slug($name), $projects);

            $projects[] = new Project(
                name: $name,
                slug: $slug,
                role: $entry['role'],
                summary: $entry['summary'],
                outcome: $entry['outcome'],
                stack: array_values(array_filter($entry['stack'])),
                url: filled($entry['url'] ?? null) ? (string) $entry['url'] : null,
                number: count($projects) + 1,
            );
        }

        $site = config('portfolio.author.site_project');

        if (is_array($site) && filled($site['name'] ?? null)) {
            $slug = $this->uniqueSlug((string) ($site['slug'] ?? Str::slug($site['name'])), $projects);

            $projects[] = new Project(
                name: (string) $site['name'],
                slug: $slug,
                role: (string) ($site['role'] ?? ''),
                summary: (string) ($site['summary'] ?? ''),
                outcome: (string) ($site['outcome'] ?? ''),
                stack: array_values(array_filter((array) ($site['stack'] ?? []))),
                url: filled($site['url'] ?? null) ? (string) $site['url'] : null,
                number: count($projects) + 1,
                isSite: true,
            );
        }

        return $this->projects = $projects;
    }

    /**
     * One project by its URL slug, or null when the URL names nothing.
     */
    public function find(string $slug): ?Project
    {
        foreach ($this->all() as $project) {
            if ($project->slug === $slug) {
                return $project;
            }
        }

        return null;
    }

    /**
     * The documentation index for a project, in reading order.
     *
     * @return array<int, array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string}>
     */
    public function docs(Project $project): array
    {
        return $this->documents[$project->slug] ??= $this->buildDocuments($project);
    }

    /**
     * One documentation page, rendered, or null when the slug is not in the index.
     *
     * @return array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string, html: string}|null
     */
    public function doc(Project $project, string $slug): ?array
    {
        foreach ($this->docs($project) as $doc) {
            if ($doc['slug'] !== $slug) {
                continue;
            }

            return $doc + ['html' => $this->rendered[$project->slug][$slug] ??= $this->render($doc['markdown'])];
        }

        return null;
    }

    /**
     * The docs either side of the one being read, for the footer navigation.
     *
     * @return array{previous: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    public function neighbours(Project $project, string $slug): array
    {
        $docs = $this->docs($project);
        $index = null;

        foreach ($docs as $position => $doc) {
            if ($doc['slug'] === $slug) {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $docs[$index - 1] ?? null,
            'next' => $docs[$index + 1] ?? null,
        ];
    }

    /**
     * The docs URL for a project page. Omit `$doc` for the project overview.
     */
    public function docUrl(Project $project, ?string $doc = null): string
    {
        return route('work.show', array_filter([
            'project' => $project->slug,
            'doc' => $doc,
        ]));
    }

    /**
     * Whether this project's docs are the application's own `docs/` folder.
     */
    public function isRepository(Project $project): bool
    {
        $definition = $this->definition($project);

        return ($definition['docs_source'] ?? null) === 'repository';
    }

    /**
     * Build the documentation index for one project.
     *
     * @return array<int, array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string}>
     */
    protected function buildDocuments(Project $project): array
    {
        $definition = $this->definition($project);

        if (($definition['docs_source'] ?? null) === 'repository') {
            return $this->repositoryDocuments();
        }

        $docs = [];

        foreach ((array) ($definition['docs'] ?? []) as $position => $doc) {
            if (! is_array($doc)) {
                continue;
            }

            $title = trim((string) ($doc['title'] ?? ''));
            $markdown = (string) ($doc['body'] ?? '');

            if ($title === '' || $markdown === '') {
                continue;
            }

            $docs[] = [
                'slug' => (string) ($doc['slug'] ?? Str::slug($title)),
                'title' => $title,
                'summary' => (string) ($doc['summary'] ?? Str::limit($this->plainText($markdown), 180)),
                'reading_time' => $this->readingTime($markdown),
                'markdown' => $markdown,
                'source' => 'config',
            ];
        }

        return $docs;
    }

    /**
     * Read the repository's docs folder into an index.
     *
     * Only Markdown files at the top level are documentation. The folder also
     * holds downloadable PRD archives, which are not pages; `File::files()` keeps
     * the walk shallow so a stray file in a subfolder cannot become a route.
     *
     * @return array<int, array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string}>
     */
    protected function repositoryDocuments(): array
    {
        $root = base_path('docs');

        if (! File::isDirectory($root)) {
            return [];
        }

        $docs = [];

        foreach (File::files($root) as $file) {
            if (Str::lower($file->getExtension()) !== 'md') {
                continue;
            }

            $markdown = (string) File::get($file->getPathname());
            $slug = Str::slug($file->getFilenameWithoutExtension());
            $heading = $this->firstHeading($markdown);

            $docs[] = [
                'slug' => $slug,
                'title' => $heading ?: Str::headline($file->getFilenameWithoutExtension()),
                'summary' => Str::limit($this->plainText($markdown), 180),
                'reading_time' => $this->readingTime($markdown),
                'markdown' => $markdown,
                'source' => 'repository',
            ];
        }

        usort($docs, function (array $a, array $b): int {
            $order = array_flip([
                'architecture', 'app-flow', 'routes', 'schema', 'api', 'erd',
                'permissions', 'components', 'ui-ux-brief', 'soft-ui', 'trd',
                'prd', 'themes', 'lab', 'idea',
            ]);

            return ($order[$a['slug']] ?? PHP_INT_MAX) <=> ($order[$b['slug']] ?? PHP_INT_MAX)
                ?: strcmp($a['title'], $b['title']);
        });

        return $docs;
    }

    /**
     * The config entry a project's docs come from.
     *
     * Matched by slug rather than by the profile row, because the about page may
     * be serving a database row while the docs still live beside the configured
     * project of the same name.
     *
     * @return array<string, mixed>
     */
    protected function definition(Project $project): array
    {
        if ($project->isSite) {
            $site = config('portfolio.author.site_project');

            return is_array($site) ? $site : [];
        }

        foreach ((array) config('portfolio.author.projects', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $slug = Str::slug((string) ($entry['slug'] ?? $entry['name'] ?? ''));

            if ($slug === $project->slug) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * Render one documentation page.
     *
     * CommonMark is used here rather than the post renderer: the repository docs
     * are full of tables, and the post renderer's block parser treats a table as a
     * paragraph. `Str::markdown` wraps the same league/commonmark the framework
     * already ships, with raw HTML stripped and unsafe links refused.
     */
    protected function render(string $markdown): string
    {
        return (string) Str::markdown($this->withoutLeadingHeading($markdown), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Remove the document's own H1, because the page prints the title already.
     */
    protected function withoutLeadingHeading(string $markdown): string
    {
        return (string) preg_replace('/\A\s*#\s+.*\R/', '', $markdown, 1);
    }

    /**
     * The first H1 in a Markdown document, when it has one.
     */
    protected function firstHeading(string $markdown): ?string
    {
        return preg_match('/^#\s+(.+)$/m', $markdown, $matches) === 1
            ? trim($matches[1])
            : null;
    }

    /**
     * A one-line projection of a document, for the sidebar and the docs cards.
     *
     * Deliberately not a CommonMark render: a docs index asks for this once per
     * page in the folder, and parsing twenty long documents to write twenty
     * summaries would cost more than the article the reader asked for.
     */
    protected function plainText(string $markdown): string
    {
        $text = (string) preg_replace('/```.*?```/s', ' ', $markdown);
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text);
        $text = (string) preg_replace('/^\s*[-*+]\s+/m', '', $text);
        $text = (string) preg_replace('/[`*_>|~]/', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Minutes a document takes to read, from the same plain-text projection.
     */
    protected function readingTime(string $markdown): int
    {
        $rate = max(1, (int) config('portfolio.blog.words_per_minute', 200));
        $words = str_word_count($this->plainText($markdown));

        return max(1, (int) ceil($words / $rate));
    }

    /**
     * Keep derived slugs unique when two projects happen to share a name.
     *
     * @param  array<int, Project>  $projects
     */
    protected function uniqueSlug(string $slug, array $projects): string
    {
        $slug = $slug !== '' ? $slug : 'project';
        $taken = array_map(fn (Project $project): string => $project->slug, $projects);
        $candidate = $slug;
        $suffix = 2;

        while (in_array($candidate, $taken, true)) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
