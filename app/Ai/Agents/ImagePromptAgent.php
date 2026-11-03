<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns a rough image idea into a usable image-generation prompt.
 *
 * ## Why this is a separate agent at all
 *
 * Text-to-image models are extremely literal. An author who types "a diagram
 * showing how the queue works" gets a photograph of a queue, or a picture of
 * text that is not quite letters. The gap between what an author means and what
 * an image model renders is bridged by prompt craft: naming a medium, an
 * aspect ratio, a level of abstraction, and — critically — being explicit that
 * there should be **no text in the image**, because image models render text
 * as convincing-looking gibberish.
 *
 * ## Cheap by design
 *
 * This runs before every image generation. It is a short prompt with a small
 * schema, so it is the one place where asking the cheapest available model is
 * clearly right: the output is a prompt, not prose, and the quality ceiling is
 * set by the image model downstream.
 *
 * ## Alt text comes from here too
 *
 * The schema returns `alt_text` alongside the prompt. Alt text written at the
 * moment the image is described is far better than alt text invented
 * afterwards by looking at the result — at that point the model is describing
 * pixels, not intent. Since the author had to state intent to generate the
 * image, the intent is captured here while it is still known.
 */
class ImagePromptAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public string $idea = '',
        public string $context = '',
        public string $style = 'auto',
    ) {}

    /**
     * Get the instructions the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $context = $this->context !== ''
            ? "\n\nThe image will appear in this piece of writing, which should inform the subject and mood but must NOT be reproduced as text:\n\n{$this->context}"
            : '';

        return <<<'PROMPT'
        You turn a short, rough image idea into a precise prompt for a
        text-to-image model.

        ## What makes a good prompt

        Name the medium (photograph, flat vector illustration, 3D render,
        isometric diagram, watercolour), the subject, the composition, the
        lighting and the mood. Be concrete about what is in the frame and what
        is not. Order matters: the most important elements first.

        ## Hard rules

        - The image must contain NO TEXT. State this explicitly in every prompt.
          Image models render text as near-letters that look broken, and it
          ruins an otherwise good image. Say "no text, no lettering, no
          captions, no watermarks".
        - Do not name a living artist, a real person, a celebrity, or a
          trademarked character or brand. Describe the *style* instead — "a
          bold, flat, geometric illustration style" rather than an artist's name.
        - Do not ask for anything violent, sexual, hateful, or otherwise
          unsafe.
        - Do not request a specific aspect ratio in the prompt text; that is
          chosen separately.
        PROMPT.$context;
    }

    /**
     * Get the structured output schema.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('The full image-generation prompt, in English, self-contained and describing medium, subject, composition and style. Must state that the image contains no text.')
                ->required(),
            'alt_text' => $schema->string()
                ->description('Accessible alt text for the resulting image, under 125 characters, describing what the image shows rather than restating the prompt.')
                ->required(),
            'summary' => $schema->string()
                ->description('A short human-readable label for the image, under 60 characters.')
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
}
