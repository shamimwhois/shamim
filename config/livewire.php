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
| page via Route::livewire(). Public pages use the guest shell — the public
| header, the page, the public footer.
|
| There are five audiences in `resources/views/layouts`, and this is the one
| that answers pages that have not said otherwise:
|
|   guest.home   — unauthenticated visitors; this default
|   auth         — an unauthenticated visitor proving who they are
|   app          — the general authenticated dashboard
|   seller       — the selling workspace
|   admin        — platform administration
|
| Page components that need a workspace shell opt in explicitly with
| `#[Layout('layouts::seller.dashboard')]` and so on. See the docblock at the
| foot of each layout for what it owns and what it delegates.
|
*/

    'component_layout' => 'layouts::guest.home',

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
    |
    | `rules` is the gate every staged upload passes through before a component
    | ever sees it, so it has to be at least as generous as the widest per-field
    | cap in the application — currently a listing deliverable, which
    | `config/marketplace.php` allows 20 MB of. Leave it at Livewire's 12 MB
    | default and a seller with a big package gets a framework message about a
    | limit no screen mentions, and the component's own `max:` rule becomes
    | unreachable. Narrower caps (screenshots, feed images) still bind at the
    | field, which is where each one is documented.
    */

    'temporary_file_upload' => [
        'disk' => null,
        'rules' => 'required|file|max:20480',
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
