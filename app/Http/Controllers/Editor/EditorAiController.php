<?php

namespace App\Http\Controllers\Editor;

use App\Data\TextCorrection;
use App\Enums\ContentCategory;
use App\Enums\ContentTone;
use App\Enums\CorrectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Editor\CorrectionRequest;
use App\Http\Requests\Editor\GenerationRequest;
use App\Http\Requests\Editor\ImageGenerationRequest;
use App\Http\Requests\Editor\ImagePromptRequest;
use App\Http\Requests\Editor\PreviewRequest;
use App\Services\EditorAiException;
use App\Services\EditorAiService;
use App\Support\MarkdownRenderer;
use Illuminate\Http\JsonResponse;

/**
 * JSON endpoints backing the rich text editor's AI features.
 *
 * ## Why JSON rather than Livewire
 *
 * These actions are fired from inside a text editor that owns its own selection
 * and cursor state. Round-tripping through a Livewire render would replace the
 * editor's DOM and lose the author's cursor position on every request — the
 * single most annoying possible behaviour in a writing tool. Plain JSON keeps
 * the editor in charge of its own DOM.
 *
 * ## Error contract
 *
 * Every failure returns the same shape, so the front end has one code path:
 *
 *     { "message": "…", "reason": "not_configured" }
 *
 * The status code is derived from the reason, and the message is always safe to
 * show to a person — see {@see EditorAiException}.
 */
class EditorAiController extends Controller
{
    public function __construct(private readonly EditorAiService $ai) {}

    /**
     * Report which AI features this site can offer.
     *
     * The editor calls this on mount so it can hide the AI affordances entirely
     * on an unconfigured site, rather than rendering buttons that 503.
     */
    public function capabilities(): JsonResponse
    {
        return response()->json([
            'configured' => $this->ai->isConfigured(),
            'images' => $this->ai->isImageGenerationAvailable(),
            'categories' => ContentCategory::toArray(),
            'tones' => ContentTone::toArray(),
            'correction_types' => CorrectionType::toArray(),
        ]);
    }

    /**
     * Review a passage and return correction suggestions.
     */
    public function correct(CorrectionRequest $request): JsonResponse
    {
        try {
            $corrections = $this->ai->corrections($request->text(), $request->types());
        } catch (EditorAiException $e) {
            return $this->error($e);
        }

        return response()->json([
            'corrections' => array_map(
                fn (TextCorrection $correction): array => $correction->toArray(),
                $corrections,
            ),
            'counts' => $this->countByType($corrections),
        ]);
    }

    /**
     * Generate a draft for the chosen category.
     */
    public function generate(GenerationRequest $request): JsonResponse
    {
        /** @var ContentCategory $category */
        $category = $request->category();

        try {
            $draft = $this->ai->generate(
                $category,
                $request->tone(),
                (string) $request->input('subject', ''),
                $request->wordCount(),
                (string) $request->input('source_text', ''),
                (string) $request->input('audience', ''),
                (string) $request->input('keywords', ''),
            );
        } catch (EditorAiException $e) {
            return $this->error($e);
        }

        return response()->json([
            'draft' => $draft,
            'category' => $category->value,
        ]);
    }

    /**
     * Render Markdown to sanitised HTML for the editor's live preview.
     *
     * Routes through {@see MarkdownRenderer} rather than running a
     * second Markdown implementation in JavaScript. A client-side renderer
     * would be a parallel implementation of the sanitiser and the tag allowlist,
     * and the two would drift — at which point the preview stops predicting what
     * the published post looks like, which is the entire point of having one.
     */
    public function preview(PreviewRequest $request, MarkdownRenderer $renderer): JsonResponse
    {
        return response()->json([
            'html' => $renderer->render($request->markdown()),
        ]);
    }

    /**
     * Refine a rough image idea into a generation prompt.
     */
    public function imagePrompt(ImagePromptRequest $request): JsonResponse
    {
        try {
            $prepared = $this->ai->imagePrompt(
                $request->idea(),
                $request->context(),
                $request->style(),
            );
        } catch (EditorAiException $e) {
            return $this->error($e);
        }

        return response()->json($prepared);
    }

    /**
     * Generate an image and store it.
     */
    public function generateImage(ImageGenerationRequest $request): JsonResponse
    {
        try {
            $image = $this->ai->generateImage(
                $request->prompt(),
                $request->altText(),
                $request->size(),
            );
        } catch (EditorAiException $e) {
            return $this->error($e);
        }

        return response()->json([
            'image' => $image,
            'markdown' => sprintf('![%s](%s)', $image['alt_text'], $image['url']),
        ]);
    }

    /**
     * Count the corrections by type, for the summary bar.
     *
     * @param  array<int, TextCorrection>  $corrections
     * @return array<string, int>
     */
    private function countByType(array $corrections): array
    {
        $counts = [];

        foreach (CorrectionType::cases() as $type) {
            $counts[$type->value] = 0;
        }

        foreach ($corrections as $correction) {
            $counts[$correction->type->value]++;
        }

        // Drop the zeroes so the summary bar only renders categories that
        // actually have something in them.
        return array_filter($counts, fn (int $count): bool => $count > 0);
    }

    /**
     * Turn a service failure into the shared JSON error shape.
     */
    private function error(EditorAiException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'reason' => $e->reason(),
        ], $e->status());
    }
}
