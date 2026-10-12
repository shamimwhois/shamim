<?php

namespace App\Concerns;

trait HasReadingTime
{
    /**
     * Average adult reading speed in words per minute.
     */
    protected const int WORDS_PER_MINUTE = 200;

    /**
     * Count the words in a block of text.
     */
    public static function countWords(?string $text): int
    {
        if ($text === null || trim($text) === '') {
            return 0;
        }

        return str_word_count(strip_tags($text));
    }

    /**
     * Estimate reading time in whole minutes, never less than one.
     */
    public static function estimateReadingTime(?string $text, ?int $wordsPerMinute = null): int
    {
        $wordsPerMinute ??= self::WORDS_PER_MINUTE;

        return self::readingTimeFor(self::countWords($text), $wordsPerMinute);
    }

    /**
     * Convert a word count into whole minutes, never less than one.
     *
     * Separated from estimateReadingTime() so callers that already hold a word
     * count — such as the editor's live statistics, which count against the
     * plain-text projection — do not have to count twice.
     */
    public static function readingTimeFor(int $words, ?int $wordsPerMinute = null): int
    {
        if ($words <= 0) {
            return 1;
        }

        return max(1, (int) ceil($words / ($wordsPerMinute ?? self::WORDS_PER_MINUTE)));
    }
}
