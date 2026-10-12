<?php

namespace App\Services;

use App\Ai\Agents\ContentWriterAgent;
use App\Ai\Agents\CopyEditorAgent;
use App\Ai\Agents\ImagePromptAgent;
use App\Data\TextCorrection;
use App\Enums\ContentCategory;
use App\Enums\ContentTone;
use App\Enums\CorrectionType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * The editor's AI operations.
 *
 * Every AI feature in the editor goes through this class, for two reasons.
 *
 * **One place to be unavailable.** No provider key is configured on a fresh
 * checkout, and that must not be a 500. {@see self::isConfigured()} gates every
 * operation, the controller turns a false into a clear 503, and the editor
 * hides its AI affordances rather than offering buttons that fail.
 *
 * **One place to be wrong safely.** A model returning malformed JSON, an
 * upstream timeout, a rate limit — these are all routine and none of them
 * should surface a stack trace to an author mid-sentence. Failures are caught,
 * logged with context, and rethrown as {@see EditorAiException} carrying a
 * message written for a person.
 */
class EditorAiService
{
    /**
     * Whether an AI provider is configured well enough to attempt a call.
     *
     * Checks for a key on the resolved provider rather than requiring one
     * specific vendor, so switching AI_PROVIDER to a local Ollama does not
     * require a code change here.
     */
    public function isConfigured(): bool
    {
        $provider = config('ai.editor.text_provider') ?: config('ai.default');

        if (! is_string($provider) || $provider === '') {
            return false;
        }

        $config = config("ai.providers.{$provider}");

        if (! is_array($config)) {
            return false;
        }

        // Ollama and openai-compatible need no key, only a base URL. Every
        // other provider is unusable without one.
        $driver = $config['driver'] ?? null;

        if (in_array($driver, ['ollama', 'openai-compatible'], true)) {
            return filled($config['url'] ?? null);
        }

        return filled($config['key'] ?? null);
    }

    /**
     * Whether image generation is both configured and enabled.
     */
    public function isImageGenerationAvailable(): bool
    {
        if (! (bool) config('ai.editor.images_enabled', true)) {
            return false;
        }

        $provider = config('ai.editor.image_provider') ?: config('ai.default_for_images');

        $config = is_string($provider) ? config("ai.providers.{$provider}") : null;

        if (! is_array($config)) {
            return false;
        }

        $driver = $config['driver'] ?? null;

        if (in_array($driver, ['ollama', 'openai-compatible'], true)) {
            return filled($config['url'] ?? null);
        }

        return filled($config['key'] ?? null);
    }

    /**
     * Review a passage and return correction suggestions.
     *
     * Suggestions the model could not place in the source text are dropped
     * rather than surfaced — see {@see TextCorrection::fromArray()}. What
     * comes back is always applicable.
     *
     * @param  array<int, CorrectionType>  $types
     * @return array<int, TextCorrection>
     */
    public function corrections(string $text, array $types = []): array
    {
        $this->ensureConfigured();

        try {
            $agent = CopyEditorAgent::make(
                $text,
                array_map(fn (CorrectionType $type): string => $type->value, $types),
            );

            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt(
                'Review the text and return the corrections.',
                timeout: (int) config('ai.editor.timeout', 60),
            );

            $raw = $response->structured['corrections'] ?? [];

            if (! is_array($raw)) {
                return [];
            }

            $corrections = [];

            foreach ($raw as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $correction = TextCorrection::fromArray($entry, $text);

                if ($correction !== null) {
                    $corrections[] = $correction;
                }
            }

            // Most mechanical first, so an author can clear the obvious fixes
            // without reading the judgement calls.
            usort(
                $corrections,
                fn (TextCorrection $a, TextCorrection $b): int => [$a->type->weight(), $a->start] <=> [$b->type->weight(), $b->start],
            );

            return $corrections;
        } catch (Throwable $e) {
            throw $this->failed('review the text for corrections', $e);
        }
    }

    /**
     * Generate a draft for the given category.
     *
     * @return array{title: string, summary: string, body: string}
     */
    public function generate(
        ContentCategory $category,
        ContentTone $tone,
        string $subject = '',
        int $wordCount = 0,
        string $sourceText = '',
        string $audience = '',
        string $keywords = '',
    ): array {
        $this->ensureConfigured();

        [$minWords, $maxWords] = $category->wordRange();

        $wordCount = $wordCount > 0
            ? max($minWords, min($maxWords, $wordCount))
            : $category->defaultWordCount();

        try {
            $agent = ContentWriterAgent::make(
                $subject,
                $category,
                $tone,
                $wordCount,
                $sourceText,
                $audience,
                $keywords,
            );

            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt(
                $agent->promptText(),
                timeout: (int) config('ai.editor.timeout', 60),
            );

            return [
                'title' => trim((string) ($response->structured['title'] ?? '')),
                'summary' => trim((string) ($response->structured['summary'] ?? '')),
                'body' => trim((string) ($response->structured['body'] ?? '')),
            ];
        } catch (Throwable $e) {
            throw $this->failed("generate a {$category->label()}", $e);
        }
    }

    /**
     * Build an image prompt from a rough idea.
     *
     * Split out from generation so the editor can show the refined prompt to
     * the author before spending an image call on it — a chance to correct a
     * misunderstanding cheaply.
     *
     * @return array{prompt: string, alt_text: string, summary: string}
     */
    public function imagePrompt(string $idea, string $context = '', string $style = 'auto'): array
    {
        $this->ensureConfigured();

        try {
            $agent = ImagePromptAgent::make($idea, $context, $style);

            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt(
                'Build the image prompt for the idea above.',
                timeout: (int) config('ai.editor.timeout', 60),
            );

            return [
                'prompt' => trim((string) ($response->structured['prompt'] ?? $idea)),
                'alt_text' => trim((string) ($response->structured['alt_text'] ?? '')),
                'summary' => trim((string) ($response->structured['summary'] ?? '')),
            ];
        } catch (Throwable $e) {
            throw $this->failed('build the image prompt', $e);
        }
    }

    /**
     * Generate an image and store it publicly.
     *
     * The stored path is returned rather than a data URI: generated images are
     * routinely several megabytes, and inlining one into the Markdown body
     * would bloat the post, the export, and every page that renders it.
     *
     * @return array{path: string, url: string, alt_text: string}
     */
    public function generateImage(string $prompt, string $altText = '', ?string $size = null): array
    {
        if (! $this->isImageGenerationAvailable()) {
            throw new EditorAiException(
                'Image generation is not configured on this site.',
                EditorAiException::NOT_CONFIGURED,
            );
        }

        try {
            $provider = config('ai.editor.image_provider') ?: null;
            $model = config('ai.editor.image_model') ?: null;

            /** @var ImageResponse $response */
            // `fakeableImageProvider`, not `imageProvider`: the latter returns
            // the raw provider and bypasses `Ai::fakeImages()`, which would
            // make this branch untestable and would fire a real HTTP request
            // in the suite. The fakeable variant behaves identically when
            // nothing is faked.
            $response = Ai::fakeableImageProvider($provider)->image(
                $prompt,
                size: $size,
                model: $model,
                timeout: (int) config('ai.editor.timeout', 60),
            );

            $path = $response->storePublicly('editor-images', 'public');

            if (! is_string($path)) {
                throw new EditorAiException(
                    'The generated image could not be saved.',
                    EditorAiException::STORAGE_FAILED,
                );
            }

            return [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'alt_text' => $altText,
            ];
        } catch (EditorAiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->failed('generate the image', $e);
        }
    }

    /**
     * Translate a category into the constraint list the validator uses.
     *
     * @return array{0: int, 1: int}
     */
    public function wordBounds(ContentCategory $category): array
    {
        return $category->wordRange();
    }

    /**
     * Throw when no provider is configured.
     */
    protected function ensureConfigured(): void
    {
        if ($this->isConfigured()) {
            return;
        }

        throw new EditorAiException(
            'AI features are not configured. Add an API key for your chosen provider to enable them.',
            EditorAiException::NOT_CONFIGURED,
        );
    }

    /**
     * Wrap a caught failure in a message safe to show an author.
     */
    protected function failed(string $action, Throwable $e): EditorAiException
    {
        Log::warning("Editor AI failed to {$action}.", [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $message = match (true) {
            str_contains($e->getMessage(), 'timed out'), str_contains($e->getMessage(), 'timeout') => 'The AI provider took too long to respond. Try a shorter passage.',
            str_contains($e->getMessage(), 'rate limit'), str_contains($e->getMessage(), '429') => 'The AI provider is rate limiting this site. Wait a moment and try again.',
            str_contains($e->getMessage(), 'Unauthorized'), str_contains($e->getMessage(), '401') => 'The AI provider rejected the configured API key.',
            default => "The AI provider could not {$action}. Please try again.",
        };

        return new EditorAiException($message, EditorAiException::PROVIDER_FAILED, $e);
    }
}
