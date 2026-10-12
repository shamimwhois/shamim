<?php

namespace App\Ai\Agents;

use App\Enums\ContentCategory;
use App\Enums\ContentTone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The category-driven content writer.
 *
 * Generates a draft from a subject line, shaped by the chosen
 * {@see ContentCategory} and voiced by the chosen {@see ContentTone}.
 *
 * ## Why the system prompt is assembled rather than hardcoded
 *
 * The category and tone fragments come from their enums, so this class holds
 * only the rules that apply to *every* generation regardless of category —
 * Markdown output, no invented facts, no meta-commentary. Everything that
 * varies lives next to the label the author picked in the dropdown, which is
 * what stops the two from drifting.
 *
 * ## Why the output is structured
 *
 * Generation could return bare Markdown, and for a first draft it usually
 * should. Returning JSON lets it also hand back a title and a one-line summary
 * — which the editor puts in the post's title and excerpt fields instead of
 * making the author copy them out of the body. The extra structure costs one
 * schema and saves a manual step on every single generation.
 */
class ContentWriterAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public string $subject = '',
        public ContentCategory $category = ContentCategory::BlogPost,
        public ContentTone $tone = ContentTone::Conversational,
        public int $wordCount = 600,
        public string $sourceText = '',
        public string $audience = '',
        public string $keywords = '',
    ) {}

    /**
     * Get the instructions the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $category = $this->category->instruction();
        $tone = $this->tone->instruction();

        [$minWords, $maxWords] = $this->category->wordRange();
        $target = max($minWords, min($maxWords, $this->wordCount));

        $audience = $this->audience !== ''
            ? "\nThe intended reader: {$this->audience}."
            : '';

        $keywords = $this->keywords !== ''
            ? "\nWeave these in naturally where they fit, and do not force them: {$this->keywords}."
            : '';

        $source = $this->sourceText !== ''
            ? "\n\n## Text to work from\n\n{$this->sourceText}"
            : '';

        return <<<PROMPT
        You are a professional writer producing a first draft for a human to
        revise. The human is the author; you are not.

        ## Output format

        Write in Markdown. Use `##` for top-level sections and `###` for
        sub-sections. Use fenced code blocks with a language tag for code
        (```php). Use `-` for bullets and `1.` for numbered steps. Do not use
        a `#` heading in the body — the title is returned separately.

        ## Shape

        {$category}

        Aim for roughly {$target} words. Staying between {$minWords} and
        {$maxWords} is more important than hitting the target exactly.

        ## Voice

        {$tone}{$audience}{$keywords}

        ## Rules

        - Do not open with a definition of the topic ("X is a...") or with
          filler like "In today's world". Start with something a reader would
          actually want to read.
        - Do not invent facts, statistics, quotes, dates, prices, version
          numbers or URLs. If a specific fact would strengthen the piece but you
          do not know it, write `[TODO: ...]` describing what belongs there.
          A visible gap is far better than a confident fabrication.
        - Do not write meta-commentary about the piece itself. No "In this
          article, we will explore...", no summary of what you just wrote.
        - Never mention that you are an AI or that this was generated.
        - Do not pad. If the subject supports 300 words, write 300 good ones.
        PROMPT.$source;
    }

    /**
     * Get the structured output schema.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('A specific, concrete title. No clickbait, no colons-and-list filler.')
                ->required(),
            'summary' => $schema->string()
                ->description('One sentence, under 200 characters, describing what the piece covers. Used as the excerpt.')
                ->required(),
            'body' => $schema->string()
                ->description('The Markdown body, without a top-level heading.')
                ->required(),
        ];
    }

    /**
     * The provider this agent prefers, or null for the SDK default.
     */
    public function provider(): ?string
    {
        return config('ai.editor.text_provider') ?: null;
    }

    /**
     * The model this agent prefers, or null for the provider's default.
     */
    public function model(): ?string
    {
        return config('ai.editor.text_model') ?: null;
    }

    /**
     * Build the prompt sent to the model.
     *
     * The enum fragments are already in the instructions; this carries the
     * task-specific part, which is what the author actually typed.
     */
    public function promptText(): string
    {
        if ($this->category->requiresSourceText() && $this->sourceText !== '') {
            return 'Rewrite the text above.';
        }

        $subject = trim($this->subject);

        if ($subject === '') {
            // A category with no subject is still a coherent request: "write
            // me a product description" with no product named is a prompt for
            // the model to pick a plausible angle, which is a legitimate way
            // to break a blank page.
            return "Write a {$this->category->label()} on a subject you choose that suits this category.";
        }

        return "Subject: {$subject}";
    }
}
