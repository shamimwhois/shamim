<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hub Listing Sizes
    |--------------------------------------------------------------------------
    |
    | How many rows a hub listing renders per page. These live in configuration
    | rather than in the components because the three grids share a breakpoint
    | budget: the articles grid, the paths grid, and the topic index all paginate
    | on the same screen width, and changing one of them without the others is
    | how a page ends up looking accidentally inconsistent.
    |
    */

    'per_page' => [
        'articles' => (int) env('AI_HUB_PER_PAGE_ARTICLES', 9),
        'paths' => (int) env('AI_HUB_PER_PAGE_PATHS', 6),
        'topics' => (int) env('AI_HUB_PER_PAGE_TOPICS', 12),
        'resources' => (int) env('AI_HUB_PER_PAGE_RESOURCES', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Landing Page Counts
    |--------------------------------------------------------------------------
    |
    | The landing page is a set of shelves, not an index, so each shelf asks for
    | a fixed number of rows. The featured article is deliberately one row: the
    | spec describes a single spotlight, and a second spotlight would compete
    | with the topic grid that has to fit on the same viewport.
    |
    */

    'landing' => [
        'spotlight' => (int) env('AI_HUB_LANDING_SPOTLIGHT', 1),
        'recent_articles' => (int) env('AI_HUB_LANDING_RECENT', 6),
        'featured_paths' => (int) env('AI_HUB_LANDING_PATHS', 3),
        'featured_resources' => (int) env('AI_HUB_LANDING_RESOURCES', 4),
        'topic_cards' => (int) env('AI_HUB_LANDING_TOPICS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Article Cross-Linking Limits
    |--------------------------------------------------------------------------
    |
    | Caps on the optional blocks attached to an article. Related articles and
    | inline links are operator-curated, but the console lets more rows be filed
    | than the detail page can show without pushing the next section off-screen,
    | so the presenter trims to these numbers rather than rendering everything.
    |
    */

    'article' => [
        'related_limit' => (int) env('AI_HUB_ARTICLE_RELATED', 4),
        'links_limit' => (int) env('AI_HUB_ARTICLE_LINKS', 8),
        'excerpt_length' => (int) env('AI_HUB_EXCERPT_LENGTH', 200),
    ],

];
