<?php

namespace App\Actions\AiHub;

use App\Support\MarkdownRenderer;

/**
 * Shared persistence logic for the derived article representations.
 *
 * `body_html`, `body_text`, word count, and reading time are produced here and
 * nowhere else, so the three projections of an article's content can never drift
 * apart, and the sanitiser cannot be bypassed by a second write path.
 */
class HydrateAiArticleContent
{
    public function __construct(protected MarkdownRenderer $renderer) {}

    /**
     * Derive and assign every content-derived attribute.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function handle(array $attributes): array
    {
        $body = (string) ($attributes['body'] ?? '');

        $plainText = $this->renderer->toPlainText($body);

        $attributes['body_html'] = $this->renderer->render($body);
        $attributes['body_text'] = $plainText;
        $attributes['word_count'] = $this->countWords($plainText);
        $attributes['reading_time'] = max(1, (int) ceil($attributes['word_count'] / 200));

        return $attributes;
    }

    /**
     * Count words in a plain-text projection.
     */
    protected function countWords(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        return max(0, str_word_count($text));
    }
}
