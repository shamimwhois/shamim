<?php

namespace App\Data;

/**
 * One selected-work project, as the about page and its detail pages read it.
 *
 * The about page keeps projects in configuration (or in `about_items` rows for an
 * operator-managed page), and neither of those carries a URL slug or a docs
 * pointer. This object is the shape both the card grid and the `/work/{project}`
 * pages share, so a slug is derived once instead of in every template.
 */
readonly class Project
{
    /**
     * @param  array<int, string>  $stack
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $role,
        public string $summary,
        public string $outcome,
        public array $stack,
        public ?string $url,
        public int $number,
        public bool $isSite = false,
    ) {}

    /**
     * The marker the about page prints beside the project number.
     */
    public function marker(): string
    {
        return 'P·'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The detail page for this project.
     */
    public function href(): string
    {
        return route('work.show', ['project' => $this->slug]);
    }
}
