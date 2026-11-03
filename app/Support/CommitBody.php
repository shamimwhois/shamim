<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A commit body, rendered the way the log prints it.
 *
 * A commit is plain text, and that is the point: the design keeps the body at
 * 15px, pre-wrapped, with only four things recognised inside it — fenced code,
 * inline code, links, and the two addresses a commit can point at (`@handle`
 * and `#tag`). Everything else stays the text the author typed.
 *
 * The order matters. The body is split on fenced blocks *first*, on the raw
 * string, because a fence is the only construct that may contain HTML-looking
 * text, and each part is escaped on its own. Inline rules then run on text that
 * is already escaped, so a body can never inject markup through a link label or
 * a code span.
 */
final class CommitBody
{
    /**
     * Render a body as HTML.
     *
     * @param  string  $body  The raw commit body.
     * @return string Escaped HTML: only the classes this surface defines.
     */
    public static function toHtml(string $body): string
    {
        $html = '';

        foreach (preg_split('/(^```[^\n]*\n.*?^```$)/ms', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $part) {
            if ($part === '') {
                continue;
            }

            // Odd indexes are the delimited fences themselves.
            if ($index % 2 === 1) {
                $html .= self::fence($part);

                continue;
            }

            $html .= self::inline($part);
        }

        return $html;
    }

    /**
     * A fenced block: an optional file name, then the code on the wash.
     */
    private static function fence(string $fence): string
    {
        $lines = explode("\n", $fence);
        $label = trim(substr((string) array_shift($lines), 3));
        array_pop($lines);

        $code = e(implode("\n", $lines));
        $header = $label === ''
            ? ''
            : '<div class="dn-code-filename"><span>'.e($label).'</span></div>';

        return $header.'<pre class="dn-code"><code>'.$code.'</code></pre>';
    }

    /**
     * Inline rules, applied to text that is escaped on the way in.
     */
    private static function inline(string $text): string
    {
        $html = e($text);

        // Links first, so a URL's own `@` or `#` is never mistaken for an address.
        $html = (string) preg_replace(
            '~(?<![\w"\'=])(https?://[^\s<]+[^\s<.,:;"\')])~u',
            '<a href="$1" rel="nofollow noopener noreferrer" target="_blank">$1</a>',
            $html,
        );

        $html = (string) preg_replace(
            '~(?<![\w@/])@([A-Za-z0-9_]{2,30})~u',
            '<a class="dn-mention" href="'.e(route('feed.index')).'?q=%40$1">@$1</a>',
            $html,
        );

        $html = (string) preg_replace(
            '~(?<![\w#/])#([A-Za-z0-9_-]{2,30})~u',
            '<a class="dn-mention" href="'.e(route('feed.index')).'?tag=$1">#$1</a>',
            $html,
        );

        // Inline code last: its contents are literal, so nothing above should
        // reach inside it, and running it now leaves earlier links intact.
        $html = (string) preg_replace('~`([^`\n]+)`~u', '<code>$1</code>', $html);

        // No `<br>`: the body is set `white-space: pre-wrap`, so the author's
        // own line breaks are already the layout.
        return $html;
    }

    /**
     * The seven-character short hash a commit is known by.
     *
     * Derived from the slug rather than the row id so it is stable across
     * environments and readable in a link, and truncated the way git truncates:
     * enough characters to be unique in practice, no more.
     */
    public static function shortHash(string $slug): string
    {
        return substr(Str::lower(hash('sha1', $slug)), 0, 7);
    }
}
