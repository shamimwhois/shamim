<?php

/*
|--------------------------------------------------------------------------
| Livewire configuration
|--------------------------------------------------------------------------
|
| Only the values DevFolio actually diverges on are declared here. Everything
| else falls back to the package defaults.
|
| The one meaningful change is `component_layout`: the package default is the
| starter kit's dashboard shell, but the public site (landing page, blog index,
| post detail, about) renders inside its own layout. Page components that need
| the dashboard shell opt in explicitly via a layout attribute.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Component Namespaces
    |--------------------------------------------------------------------------
    |
    | `pages::` resolves to resources/views/pages — the starter kit already
    | ships with this expectation, and every route in this app uses it.
    |
    */

    'component_namespaces' => [
        'layouts' => resource_path('views/layouts'),
        'pages' => resource_path('views/pages'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Page Layout
    |--------------------------------------------------------------------------
    |
    | The layout a single-file component is wrapped in when rendered as a full
    | page via Route::livewire(). Public pages use the marketing shell.
    |
    */

    'component_layout' => 'layouts::public',

    /*
    |--------------------------------------------------------------------------
    | Component Locations
    |--------------------------------------------------------------------------
    */

    'component_locations' => [
        resource_path('views/components'),
        resource_path('views/livewire'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Use the site's own pagination view so Livewire-updated lists match the
    | server-rendered ones exactly.
    |
    */

    'pagination_theme' => 'vendor.pagination.devfolio',

    /*
    |--------------------------------------------------------------------------
    | Legacy Model Binding
    |--------------------------------------------------------------------------
    */

    'legacy_model_binding' => false,

    /*
    |--------------------------------------------------------------------------
    | Inject Assets
    |--------------------------------------------------------------------------
    |
    | Assets are pulled in through @vite in the layouts; injecting again would
    | duplicate the script tags.
    |
    */

    'inject_assets' => false,

    /*
    |--------------------------------------------------------------------------
    | Navigate
    |--------------------------------------------------------------------------
    */

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#10b981',
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary File Uploads
    |--------------------------------------------------------------------------
    */

    'temporary_file_upload' => [
        'disk' => null,
        'rules' => null,
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
