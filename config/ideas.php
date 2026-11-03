<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Listing Sizes
    |--------------------------------------------------------------------------
    |
    | Rows per page for the two lists that paginate: the public index and the
    | workspace's own inventory. They are separate numbers because they are
    | separate reading tasks. A visitor scanning published ideas wants few,
    | large cards; an account clearing its inbox wants to see how deep the pile
    | is before deciding what to move next.
    |
    */

    'per_page' => [
        'public' => (int) env('IDEAS_PER_PAGE', 12),
        'workspace' => (int) env('IDEAS_PER_PAGE_WORKSPACE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Bands
    |--------------------------------------------------------------------------
    |
    | The workspace home shows a band of pinned ideas and a band of recently
    | touched ones. These are shelves, not indices, so they take a fixed count
    | rather than a page: a band that paginates stops being a glance.
    |
    */

    'dashboard' => [
        'pinned' => (int) env('IDEAS_DASHBOARD_PINNED', 6),
        'recent' => (int) env('IDEAS_DASHBOARD_RECENT', 8),
    ],

];
