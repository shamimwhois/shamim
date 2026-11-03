<?php

namespace App\Enums;

use App\Ai\Agents\ContentWriterAgent;

/**
 * Content categories the editor's AI generator can produce.
 *
 * ## Why this is one enum and not a config array
 *
 * The category appears in exactly two places: the dropdown a person picks from,
 * and the instruction sent to the model. Those two must never disagree — a
 * dropdown labelled "Product description" that prompts the model to write a
 * blog post is a bug nobody notices until a customer does. Keeping the label,
 * the icon and the instruction in one declaration makes that class of bug
 * impossible rather than merely unlikely.
 *
 * ## Structure of a case
 *
 * - `label()` / `description()` — what the dropdown shows.
 * - `icon()` — the Flux icon name beside the label. Must be a real icon; an
 *   invalid name throws a hard render exception.
 * - `instruction()` — the per-category instruction appended to the shared
 *   system prompt. This is the *only* part that varies by category, so the
 *   tone and formatting rules stay in one place.
 * - `defaultTone()` — the tone preselected when this category is chosen.
 * - `wordRange()` — guidance passed to the model, and used to validate that a
 *   requested length is sane rather than absurd.
 *
 * ## Adding a category
 *
 * Add the case, then add its `Enums\ContentCategory` test expectations. The
 * dropdown, the validation rule and the prompt all pick it up automatically —
 * there is no second list to update.
 */
enum ContentCategory: string
{
    case BlogPost = 'blog-post';
    case ProductDescription = 'product-description';
    case MarketingCopy = 'marketing-copy';
    case Email = 'email';
    case SocialPost = 'social-post';
    case Documentation = 'documentation';
    case Outline = 'outline';
    case Rewrite = 'rewrite';

    /**
     * Get the display label shown in the category dropdown.
     */
    public function label(): string
    {
        return match ($this) {
            self::BlogPost => __('Blog post'),
            self::ProductDescription => __('Product description'),
            self::MarketingCopy => __('Marketing copy'),
            self::Email => __('Email'),
            self::SocialPost => __('Social post'),
            self::Documentation => __('Documentation'),
            self::Outline => __('Outline'),
            self::Rewrite => __('Rewrite'),
        };
    }

    /**
     * Get the one-line helper text shown beneath the dropdown.
     */
    public function description(): string
    {
        return match ($this) {
            self::BlogPost => __('A structured article with headings and a conclusion.'),
            self::ProductDescription => __('A feature-led description that ends in a benefit.'),
            self::MarketingCopy => __('Persuasive copy for a landing page or campaign.'),
            self::Email => __('A subject line and a short, scannable body.'),
            self::SocialPost => __('A tight post sized for a social timeline.'),
            self::Documentation => __('Reference prose: task-oriented and precise.'),
            self::Outline => __('A heading skeleton to write into, not finished prose.'),
            self::Rewrite => __('Rework the selected text while keeping its meaning.'),
        };
    }

    /**
     * Get the Flux icon name for this category.
     *
     * Every name here was checked against the icon set Flux actually ships.
     * Flux throws on an unknown icon rather than rendering nothing, so a typo
     * here is a 500, not a blank space.
     */
    public function icon(): string
    {
        return match ($this) {
            self::BlogPost => 'document-text',
            self::ProductDescription => 'shopping-bag',
            self::MarketingCopy => 'megaphone',
            self::Email => 'envelope',
            self::SocialPost => 'chat-bubble-left-right',
            self::Documentation => 'book-open',
            self::Outline => 'list-bullet',
            self::Rewrite => 'pencil-square',
        };
    }

    /**
     * Get the instruction that makes this category behave differently.
     *
     * This is appended to the shared system prompt. It should describe the
     * *shape* of the output, not repeat the tone rules — those live in
     * {@see ContentWriterAgent} so they stay identical across
     * every category.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::BlogPost => 'Write a structured article. Open with a short introduction that states the problem, develop the argument under Markdown `##` headings, and close with a conclusion that does not merely restate the introduction. Use `###` only when a section genuinely has sub-parts.',
            self::ProductDescription => 'Write a product description. Lead with what the product does for the buyer rather than a list of specifications. Weave the concrete features into the prose so each one is attached to a benefit, and finish with a single clear line about who it is for.',
            self::MarketingCopy => 'Write persuasive marketing copy. Open with a hook that names a real tension the reader recognises, build the case in short paragraphs, and end with one unambiguous call to action. Be concrete; avoid superlatives that carry no information.',
            self::Email => 'Write an email. Begin with a `Subject:` line, then a body of no more than four short paragraphs. State the purpose in the first sentence. Keep the sign-off neutral, using a placeholder such as `— [Your name]` rather than inventing one.',
            self::SocialPost => 'Write a single social post. Keep it under 280 characters unless asked otherwise. No hashtag stuffing — at most two, and only if they are genuinely used as tags. Make one point well.',
            self::Documentation => 'Write reference documentation. Use the imperative mood for procedures ("Run the command", not "You should run the command"). Include a `##` section per task. Where a step has a prerequisite, state it before the step. Be precise about names, and never invent an API surface — if something is unknown, write `TODO:` rather than guessing.',
            self::Outline => 'Produce an outline only: a `#` title, then `##` and `###` headings with a single sentence of intent beneath each. Do not write finished prose. The reader will write into this skeleton.',
            self::Rewrite => 'Rewrite the text the user supplies. Preserve its meaning, its facts and its point of view. Do not add new claims. Improve clarity, rhythm and precision. Return only the rewritten text, with no preamble and no commentary on what you changed.',
        };
    }

    /**
     * Get the tone preselected when this category is chosen.
     */
    public function defaultTone(): ContentTone
    {
        return match ($this) {
            self::BlogPost => ContentTone::Conversational,
            self::ProductDescription => ContentTone::Persuasive,
            self::MarketingCopy => ContentTone::Persuasive,
            self::Email => ContentTone::Professional,
            self::SocialPost => ContentTone::Conversational,
            self::Documentation => ContentTone::Technical,
            self::Outline => ContentTone::Professional,
            self::Rewrite => ContentTone::Conversational,
        };
    }

    /**
     * Get the sensible word-count range for this category.
     *
     * Used as guidance in the prompt and as the bounds for the requested-length
     * validator, so a request for a 50,000-word email is rejected at the edge
     * rather than sent to a model that will refuse it.
     *
     * @return array{0: int, 1: int} Minimum and maximum words.
     */
    public function wordRange(): array
    {
        return match ($this) {
            self::BlogPost => [600, 2500],
            self::ProductDescription => [80, 500],
            self::MarketingCopy => [100, 900],
            self::Email => [50, 400],
            self::SocialPost => [10, 280],
            self::Documentation => [150, 2000],
            self::Outline => [100, 900],
            self::Rewrite => [20, 2000],
        };
    }

    /**
     * Get the default word count requested for this category.
     */
    public function defaultWordCount(): int
    {
        [$min, $max] = $this->wordRange();

        // Aim low in the range: a shorter draft that the author extends is more
        // useful than a long one they have to cut down, and it costs less.
        return (int) round(($min + $max) / 2 / 50) * 50;
    }

    /**
     * Whether this category rewrites text the user supplies.
     *
     * A rewrite with nothing to rewrite is not a useful operation, so the UI
     * requires a selection (or an existing body) for these categories and
     * disables the action when there is nothing to work from.
     */
    public function requiresSourceText(): bool
    {
        return $this === self::Rewrite;
    }

    /**
     * Whether this category produces an outline rather than finished prose.
     */
    public function producesOutline(): bool
    {
        return $this === self::Outline;
    }

    /**
     * Get every case as a value => label map, for a `<select>` input.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Get every case as a plain array for JSON and the front end.
     *
     * @return array<int, array{value: string, label: string, description: string, icon: string, tone: string, word_count: int, min_words: int, max_words: int, requires_source: bool, produces_outline: bool}>
     */
    public static function toArray(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'icon' => $case->icon(),
            'tone' => $case->defaultTone()->value,
            'word_count' => $case->defaultWordCount(),
            'min_words' => $case->wordRange()[0],
            'max_words' => $case->wordRange()[1],
            'requires_source' => $case->requiresSourceText(),
            'produces_outline' => $case->producesOutline(),
        ], self::cases());
    }
}
