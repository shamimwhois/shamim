<?php

namespace App\Data;

/**
 * Static professional profile used by the landing and about surfaces.
 *
 * Kept as configuration rather than a database table: this describes the site
 * owner, not content they author, and it changes roughly once a year.
 */
readonly class AuthorProfile
{
    /**
     * @param  array<int, array{title: string, description: string, icon: string, tags: array<int, string>}>  $expertise
     * @param  array<int, array{name: string, role: string, summary: string, outcome: string, stack: array<int, string>, url: string|null}>  $projects
     * @param  array<int, array{company: string, period: string, roles: array<int, array{title: string, period: string, highlights: array<int, array{text: string, stack: array<int, string>}>}>}>  $experience
     * @param  array<int, array{label: string, value: string}>  $stats
     * @param  array<string, array{label: string, url: string|null, handle: string|null}>  $links
     */
    public function __construct(
        public string $name,
        public string $role,
        public string $tagline,
        public string $location,
        public string $availability,
        public string $bio,
        public string $longBio,
        public int $yearsOfExperience,
        public array $expertise,
        public array $projects,
        public array $experience,
        public array $stats,
        public array $links,
    ) {}

    /**
     * Build the profile from configuration.
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('portfolio.author');

        return new self(
            name: $config['name'],
            role: $config['role'],
            tagline: $config['tagline'],
            location: $config['location'],
            availability: $config['availability'],
            bio: $config['bio'],
            longBio: $config['long_bio'],
            yearsOfExperience: (int) $config['years_of_experience'],
            expertise: $config['expertise'],
            projects: $config['projects'],
            experience: $config['experience'],
            stats: $config['stats'],
            links: $config['links'],
        );
    }

    /**
     * Get a single link by key, or null when it is not published.
     *
     * @return array{label: string, url: string|null, handle: string|null}|null
     */
    public function link(string $key): ?array
    {
        $link = $this->links[$key] ?? null;

        return filled($link['url'] ?? null) ? $link : null;
    }

    /**
     * Get every published link, keyed by channel, with unpublished channels
     * filtered out so no renderer can accidentally emit a dead href.
     *
     * @return array<string, array{label: string, url: string|null, handle: string|null}>
     */
    public function socialLinks(): array
    {
        return array_filter(
            $this->links,
            fn (array $link): bool => filled($link['url'] ?? null),
        );
    }

    /**
     * Get up to $limit expertise entries.
     *
     * @return array<int, array{title: string, description: string, icon: string, tags: array<int, string>}>
     */
    public function topExpertise(int $limit = 6): array
    {
        return array_slice($this->expertise, 0, $limit);
    }

    /**
     * Get up to $limit selected projects.
     *
     * @return array<int, array{name: string, role: string, summary: string, outcome: string, stack: array<int, string>, url: string|null}>
     */
    public function selectedProjects(int $limit = 3): array
    {
        return array_slice($this->projects, 0, $limit);
    }
}
