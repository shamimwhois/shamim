<?php

namespace App\Ai\Agents;

use App\Data\TextCorrection;
use App\Enums\CorrectionType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The inline copy editor.
 *
 * Reviews a passage and returns structured correction suggestions. It returns
 * JSON rather than prose because every suggestion has to be applied to an exact
 * span of the document — a paragraph of friendly advice about the writing is
 * not something a UI can act on.
 *
 * ## The offset contract
 *
 * The schema asks the model for `start` and `end` offsets alongside the text.
 * Those offsets are **never trusted**; {@see TextCorrection::fromArray()}
 * re-derives them from the source text. Asking for them anyway is deliberate:
 * a model that must state where an edit belongs produces more conservative,
 * better-localised suggestions than one that only has to name a phrase, even
 * when we throw its arithmetic away.
 *
 * ## What it is told not to do
 *
 * The instructions spend most of their length on restraint. The failure mode of
 * an AI copy editor is not missing errors, it is inventing them — flagging
 * perfectly good prose so the list looks productive, and rewriting an author's
 * voice into a flat corporate register. The rules below are a direct response to
 * that: only flag what is defensibly wrong, never change meaning, leave voice
 * alone.
 */
class CopyEditorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * The maximum number of suggestions to ask for.
     *
     * Capped because a list of forty corrections is not usable — an author
     * stops reading. It also bounds the response size, which keeps the JSON
     * parse reliable.
     */
    public const MAX_SUGGESTIONS = 20;

    /**
     * @param  string  $text  The passage to review.
     * @param  array<int, string>  $types  Allowed correction types; empty means all.
     */
    public function __construct(
        public string $text = '',
        public array $types = [],
    ) {}

    /**
     * Get the instructions the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $types = $this->types === []
            ? CorrectionType::cases()
            : array_filter(
                array_map(fn (string $type): ?CorrectionType => CorrectionType::tryFrom($type), $this->types),
            );

        $names = implode(', ', array_map(
            fn (CorrectionType $type): string => $type->value,
            $types,
        ));

        // Interpolate the cap rather than writing "20" into the prose: the
        // constant is the single source of truth, and a bare {NAME} in a
        // heredoc is literal text, not a placeholder.
        $max = self::MAX_SUGGESTIONS;

        /*
         * The "what to flag" list is built from the requested types rather than
         * written out in full. Listing all six while the header says "only the
         * categories you were asked for" is a contradiction, and a model handed
         * one resolves it unpredictably — usually by reporting the forbidden
         * categories anyway, which is precisely what the filter exists to stop.
         */
        $flagging = implode("\n", array_map(
            fn (CorrectionType $type): string => '- '.$type->guidance(),
            $types,
        ));

        return <<<PROMPT
        You are a careful copy editor. You review a passage of writing and return
        a list of specific, actionable corrections.

        Report only these categories: {$names}. Ignore every other kind of problem,
        however tempting it is to mention.

        ## What to flag

        {$flagging}

        ## What not to flag

        This matters more than the list above. A correction that is not clearly
        defensible is worse than no correction, because it teaches the author to
        distrust the whole review.

        - Do NOT flag a stylistic choice that is merely a preference.
        - Do NOT rewrite for the sake of rewriting. If a sentence is correct and
          readable, leave it alone.
        - Do NOT change meaning, emphasis, or the author's point of view.
        - Do NOT flag the same problem twice.
        - Do NOT flag anything in a code block, a URL, or inline code.
        - Do NOT suggest a correction for text that is already correct.

        ## How to report

        Return at most {$max} corrections, most mechanical first.

        For each one:
        - `original`: the EXACT substring from the text, copied character for
          character, including its case and internal punctuation. It must appear
          verbatim in the text or the correction cannot be applied.
        - `replacement`: the corrected text. Keep it as close to the original as
          the fix allows — change only what is wrong.
        - `type`: exactly one of the categories listed above.
        - `explanation`: one short sentence stating why, written for the author.
          Do not restate the correction; explain it.
        - `start` and `end`: character offsets of `original` within the text.

        Keep `original` short — a phrase or a single sentence, never a whole
        paragraph. If the passage is already clean, return an empty list. An
        empty list is a correct and expected answer.
        PROMPT;
    }

    /**
     * Get the structured output schema.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'corrections' => $schema->array()
                ->items(
                    $schema->object([
                        'original' => $schema->string()
                            ->description('The exact substring from the text that should be changed. Must appear verbatim in the text.')
                            ->required(),
                        'replacement' => $schema->string()
                            ->description('The corrected text that replaces it.')
                            ->required(),
                        'type' => $schema->string()
                            ->enum(['spelling', 'grammar', 'punctuation', 'style', 'clarity', 'tone'])
                            ->description('The category of the correction.')
                            ->required(),
                        'explanation' => $schema->string()
                            ->description('One short sentence explaining why, written for the author.')
                            ->required(),
                        'start' => $schema->integer()
                            ->description('Character offset where the original text begins.')
                            ->required(),
                        'end' => $schema->integer()
                            ->description('Character offset where the original text ends.')
                            ->required(),
                    ])
                )
                ->description('The list of corrections, most mechanical first. May be empty.')
                ->required(),
        ];
    }

    /**
     * The provider this agent prefers.
     *
     * Null means "whatever `ai.editor.text_provider` resolves to", falling back
     * to the SDK default. Returning null rather than a hardcoded provider is
     * what lets the whole editor be repointed with an env change.
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
     * Get the provider as an enum when it matches a known lab.
     */
    public function lab(): ?Lab
    {
        $provider = $this->provider();

        return $provider === null ? null : Lab::tryFrom($provider);
    }
}
