<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Composer
    |--------------------------------------------------------------------------
    |
    | The write-side vocabulary and limits of the post composer. These are the
    | same lists the topic rail and the type chips render — a value accepted
    | here has to be a value the feed can filter on, or a published post would
    | land in a topic nobody can reach. Kept in config rather than in the
    | component so the action that normalises a row and the UI that offers the
    | choice cannot drift apart.
    |
    */

    'composer' => [
        'types' => ['discussion', 'question', 'showcase', 'snippet', 'article', 'poll'],
        'topics' => ['backend', 'frontend', 'tooling', 'community'],
        'default_type' => 'discussion',
        'default_topic' => 'community',
        'max_body_length' => 5000,
        'max_title_length' => 200,
        'max_tags' => 8,
        'max_attachments' => 8,

        /*
        | `max_upload_kilobytes` is the largest image the app *wants* to take,
        | not the largest it can. PHP's own `upload_max_filesize` and
        | `post_max_size` bound what ever reaches application code, and a file
        | above them is discarded before any validator runs — so the value a
        | reader is actually held to is the smallest of the three. That
        | effective limit is read through {@see App\Support\UploadLimits},
        | which clamps this one down to what the running process will accept
        | and is what both the validation message and the file picker quote.
        |
        | Raise the PHP settings (and this number with them) to advertise more;
        | raising only this number changes nothing except the size of the lie.
        */
        'max_upload_kilobytes' => 8192,

        'poll_min_options' => 2,
        'poll_max_options' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    |
    | Guard rails around the act of putting a post in front of the community.
    | `rate_limit_attempts` is counted per account inside the decay window and
    | is lifted entirely for administrators; `duplicate_window_seconds` is the
    | shorter lock against a double-clicked publish button submitting the same
    | post twice. `temp_media_hours` is how long an upload may sit staged with
    | no post before `feed:prune-media` reclaims it.
    |
    */

    'publishing' => [
        'rate_limit_attempts' => 5,
        'rate_limit_decay_seconds' => 60,
        'duplicate_window_seconds' => 10,
        'temp_media_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ecosystem radar
    |--------------------------------------------------------------------------
    |
    | Editorial curation for the feed's radar card, not scraped or live
    | telemetry. Update the list by hand when the ecosystem story changes;
    | `stars` is a human-readable display string refreshed at curation time.
    |
    */

    'radar' => [
        [
            'repo' => 'laravel/framework',
            'stars' => '32.1k',
            'lang' => 'PHP',
            'url' => 'https://github.com/laravel/framework',
            'dot' => 'bg-signal-500',
        ],
        [
            'repo' => 'tailwindlabs/tailwindcss',
            'stars' => '86.4k',
            'lang' => 'CSS',
            'url' => 'https://github.com/tailwindlabs/tailwindcss',
            'dot' => 'bg-ink-900 dark:bg-ink-100',
        ],
        [
            'repo' => 'vitejs/vite',
            'stars' => '72.8k',
            'lang' => 'TypeScript',
            'url' => 'https://github.com/vitejs/vite',
            'dot' => 'bg-amber-500',
        ],
    ],

];
