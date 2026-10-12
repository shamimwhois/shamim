<?php

namespace App\Actions\Posts;

use App\Support\MarkdownRenderer;

/**
 * Shared persistence logic for the derived post representations.
 *
 * Both create and update must produce `body_html`, `body_text`, word count, and
 * reading time from `body` through one implementation, so the three
 * representations of a post's content can never drift apart.
 */
class HydratePostContent
{
    public function __construct(protected MarkdownRenderer $renderer) {}

    /**
     * Derive and assign every content-derived attribute on the post.
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

        if (blank($attributes['excerpt'] ?? null)) {
            $attributes['excerpt'] = $this->renderer->toExcerpt($body);
        }

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
