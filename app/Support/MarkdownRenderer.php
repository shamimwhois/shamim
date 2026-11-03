<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders authored markdown into sanitised HTML and a plain-text projection.
 *
 * This is the single entry point for turning `posts.body` into the two derived
 * representations stored alongside it. Nothing else may write `body_html`, so
 * the sanitiser cannot be bypassed by a second code path.
 */
class MarkdownRenderer
{
    /**
     * Tags permitted in rendered output.
     *
     * Deliberately excludes every scripting, embedding, and styling vector.
     *
     * @var list<string>
     */
    protected const array ALLOWED_TAGS = [
        'p', 'br', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'em', 'del', 'code', 'pre', 'kbd', 'mark', 'sub', 'sup',
        'ul', 'ol', 'li',
        'blockquote',
        'a',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'img',
    ];

    /**
     * Render markdown to sanitised HTML.
     */
    public function render(string $markdown): string
    {
        return $this->sanitize($this->toHtml($markdown));
    }

    /**
     * Count the words in markdown, for the editor's live statistics.
     *
     * Counts against the plain-text projection rather than the raw source, so
     * syntax characters and code fences do not inflate the figure.
     */
    public function countWords(string $markdown): int
    {
        return count(preg_split('/\s+/u', trim($this->toPlainText($markdown)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Estimate reading time in whole minutes, never less than one.
     */
    public function readingTime(string $markdown, ?int $wordsPerMinute = null): int
    {
        $rate = $wordsPerMinute ?? (int) config('portfolio.blog.words_per_minute', 200);

        return max(1, (int) ceil($this->countWords($markdown) / max(1, $rate)));
    }

    /**
     * Project markdown into searchable, printable plain text.
     */
    public function toPlainText(string $markdown): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $this->toHtml($markdown)) ?? '';

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Derive a short excerpt from markdown, breaking on a sentence boundary.
     */
    public function toExcerpt(string $markdown, int $limit = 200): string
    {
        $text = $this->toPlainText($markdown);

        if (Str::length($text) <= $limit) {
            return $text;
        }

        $truncated = Str::substr($text, 0, $limit);

        $lastStop = max(
            (int) strrpos($truncated, '. '),
            (int) strrpos($truncated, '! '),
            (int) strrpos($truncated, '? '),
        );

        if ($lastStop > (int) ($limit * 0.6)) {
            return Str::substr($truncated, 0, $lastStop + 1);
        }

        $lastSpace = (int) strrpos($truncated, ' ');

        return $lastSpace > 0 ? rtrim(Str::substr($truncated, 0, $lastSpace)).'…' : $truncated.'…';
    }

    /**
     * Extract the document outline (h2 and h3 headings) for a table of contents.
     *
     * @return array<int, array{level: int, id: string, text: string}>
     */
    public function outline(string $html): array
    {
        preg_match_all('/<h([23])(?:\s[^>]*)?>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER);

        $headings = [];

        foreach ($matches as $match) {
            $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($text === '') {
                continue;
            }

            $headings[] = [
                'level' => (int) $match[1],
                'id' => Str::slug($text),
                'text' => $text,
            ];
        }

        return $headings;
    }

    /**
     * Convert markdown to HTML, injecting anchor ids into headings.
     */
    protected function toHtml(string $markdown): string
    {
        $blocks = preg_split('/\n{2,}/', trim($markdown)) ?: [];

        $html = '';

        foreach ($blocks as $block) {
            $html .= $this->renderBlock($block);
        }

        return $html;
    }

    /**
     * Render a single markdown block.
     */
    protected function renderBlock(string $block): string
    {
        $block = trim($block);

        if ($block === '') {
            return '';
        }

        if (str_starts_with($block, '```')) {
            return $this->renderCodeFence($block);
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/s', $block, $matches) === 1) {
            return $this->renderHeading((int) strlen($matches[1]), $matches[2]);
        }

        if (preg_match('/^[-*+]\s+/m', $block) === 1) {
            return $this->renderList($block, ordered: false);
        }

        if (preg_match('/^\d+\.\s+/m', $block) === 1) {
            return $this->renderList($block, ordered: true);
        }

        if (str_starts_with($block, '>')) {
            $inner = preg_replace('/^>\s?/m', '', $block) ?? $block;

            return '<blockquote>'.$this->renderInline($inner).'</blockquote>';
        }

        if ($block === '---') {
            return '<hr>';
        }

        return '<p>'.$this->renderInline($block).'</p>';
    }

    /**
     * Render an ATX heading with a slug-based anchor id.
     */
    protected function renderHeading(int $level, string $text): string
    {
        $level = min(6, max(1, $level));
        $text = trim($text);

        return sprintf(
            '<h%d id="%s">%s</h%d>',
            $level,
            e(Str::slug($text)),
            $this->renderInline($text),
            $level,
        );
    }

    /**
     * Render a fenced code block.
     */
    protected function renderCodeFence(string $block): string
    {
        $lines = explode("\n", $block);
        $language = trim(ltrim((string) array_shift($lines), '`'));

        $code = preg_replace('/\n?```\s*$/', '', implode("\n", $lines)) ?? '';

        $languageAttribute = $language !== '' ? ' data-language="'.e($language).'"' : '';

        return '<pre'.$languageAttribute.'><code>'.e($code).'</code></pre>';
    }

    /**
     * Render an unordered or ordered list.
     */
    protected function renderList(string $block, bool $ordered): string
    {
        $tag = $ordered ? 'ol' : 'ul';
        $pattern = $ordered ? '/^\d+\.\s+/' : '/^[-*+]\s+/';

        $items = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $items[] = '<li>'.$this->renderInline((string) preg_replace($pattern, '', $line)).'</li>';
        }

        return '<'.$tag.'>'.implode('', $items).'</'.$tag.'>';
    }

    /**
     * Render inline markdown: emphasis, code, links, and images.
     *
     * Code spans and links are stashed as opaque tokens before the remainder is
     * escaped, so authored text cannot inject markup through them.
     */
    protected function renderInline(string $text): string
    {
        $placeholders = [];
        $index = 0;

        $stash = function (string $value) use (&$placeholders, &$index): string {
            $key = "\x00TOKEN{$index}\x00";
            $placeholders[$key] = $value;
            $index++;

            return $key;
        };

        $text = preg_replace_callback(
            '/`([^`]+)`/',
            fn (array $m): string => $stash('<code>'.e($m[1]).'</code>'),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)\)/',
            fn (array $m): string => $stash('<img src="'.e($m[2]).'" alt="'.e($m[1]).'" loading="lazy">'),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            function (array $m) use ($stash): string {
                $href = $m[2];
                $attributes = preg_match('#^https?://#i', $href) === 1
                    ? ' target="_blank" rel="noopener noreferrer"'
                    : '';

                return $stash('<a href="'.e($href).'"'.$attributes.'>'.$this->renderInline($m[1]).'</a>');
            },
            $text,
        ) ?? $text;

        $text = e($text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<!_)__([^_]+)__(?!_)/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $text) ?? $text;
        $text = nl2br($text);

        return strtr($text, $placeholders);
    }

    /**
     * Strip every tag and attribute not on the allow list.
     */
    protected function sanitize(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#</?(script|style|iframe|object|embed|form|input|button)\b[^>]*>#is', '', $html) ?? $html;

        $html = strip_tags($html, '<'.implode('><', self::ALLOWED_TAGS).'>');

        $html = preg_replace_callback(
            '/<([a-z][a-z0-9]*)\b([^>]*)>/i',
            function (array $matches): string {
                $tag = strtolower($matches[1]);
                $attributes = $this->sanitizeAttributes($tag, $matches[2]);

                return '<'.$tag.($attributes ?? '').'>';
            },
            $html,
        ) ?? $html;

        return preg_replace('/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    }

    /**
     * Filter a tag's attributes down to the permitted set.
     */
    protected function sanitizeAttributes(string $tag, string $attributeString): ?string
    {
        $permitted = match ($tag) {
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'loading'],
            'pre' => ['data-language'],
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => ['id'],
            default => [],
        };

        if ($permitted === []) {
            return null;
        }

        preg_match_all('/([a-z-]+)\s*=\s*("[^"]*"|\'[^\']*\')/i', $attributeString, $matches, PREG_SET_ORDER);

        $safe = [];

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            if (! in_array($name, $permitted, true)) {
                continue;
            }

            /*
             * The value arrives from a document that has already been escaped
             * once, so it is decoded before it is inspected and re-encoded on
             * the way out. Two reasons, both load-bearing: the scheme check has
             * to see the scheme the browser will see (`&#106;avascript:` is the
             * same attack wearing an entity), and encoding an already-encoded
             * `&amp;` yields `&amp;amp;`, which makes every link with a query
             * string point at a URL containing a literal ampersand entity.
             */
            $value = html_entity_decode(trim($match[2], '"\''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (in_array($name, ['href', 'src'], true) && $this->isUnsafeUrl($value)) {
                continue;
            }

            $safe[] = $name.'="'.e($value).'"';
        }

        return $safe === [] ? null : ' '.implode(' ', $safe);
    }

    /**
     * Determine whether a URL uses a scheme that can execute script.
     *
     * The value is first reduced to the string a browser will actually read,
     * because the scheme is judged here and executed there. Per the URL
     * standard, a browser trims leading and trailing C0 controls and spaces and
     * removes tab, newline and carriage return from anywhere in the value — and
     * only those. A tab between the letters of `javascript:` therefore becomes a
     * live scheme in the browser while reading as a relative reference to
     * `parse_url()`, which is the discrepancy this reduction closes. Controls
     * that a browser does *not* remove (0x01–0x08, 0x0B, 0x0C, 0x0E–0x1F
     * mid-string) break the URL for the browser rather than forming a scheme, so
     * they need no special handling here.
     */
    protected function isUnsafeUrl(string $url): bool
    {
        $url = $this->asTheBrowserReadsIt($url);

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '') {
            return false;
        }

        return ! in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    /**
     * Reduce a URL to the string a browser resolves.
     *
     * Two removals, both from the URL standard's parser rather than invented
     * here: leading and trailing C0 control or space are trimmed, and tab,
     * newline and carriage return are removed from anywhere in the value.
     */
    protected function asTheBrowserReadsIt(string $url): string
    {
        $trimmed = preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/', '', $url) ?? $url;

        return preg_replace('/[\x09\x0A\x0D]+/', '', $trimmed) ?? $trimmed;
    }
}
