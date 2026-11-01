<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Listing media
    |--------------------------------------------------------------------------
    |
    | The write-side limits for the screenshots a seller attaches to a listing.
    | They live here rather than in the editor because the action that stores a
    | file and the screen that offers the choice must agree about what a
    | screenshot is; a limit asserted in only one of them is a limit that can be
    | walked past.
    |
    | `max_per_listing` bounds the gallery a storefront page has to lay out, and
    | `max_upload_kilobytes` bounds what one upload may cost. Nothing resizes an
    | image in this application — the aspect ratio is handled in CSS — so the
    | file a seller sends is the file a buyer sees.
    |
    */

    'media' => [
        'disk' => 'marketplace_media',
        'max_per_listing' => 8,
        'max_upload_kilobytes' => 4096,
        'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deliverables
    |--------------------------------------------------------------------------
    |
    | The file a buyer receives when they download a release. Wider than the
    | screenshot limits because a package is a zip, not a picture, and the mime
    | list is an allow-list of archives rather than of images: a deliverable is
    | streamed with `Content-Disposition: attachment`, so the thing to prevent
    | here is a listing quietly becoming a file host for arbitrary content.
    |
    */

    'deliverable' => [
        'disk' => 'marketplace_media',
        'max_upload_kilobytes' => 20480,
        'accepted_mimes' => [
            'application/zip',
            'application/x-zip-compressed',
            'application/gzip',
            'application/x-gzip',
            'application/x-tar',
            'application/x-7z-compressed',
        ],
    ],

];
