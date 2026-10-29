@props([
    'name',
    'size' => 20,
])

@php
    /**
     * The log's line icons.
     *
     * The surface needs a small, fixed set — nav, composer tools, the star, the
     * action row — and it needs them at 1.75 stroke on a 24 grid so they read at
     * 20px beside 15px text. Writing the paths here keeps the markup declarative
     * (`<x-feed.icon name="star" />`) and keeps the geometry in one place, which
     * matters because the star's outline and fill states are the same path.
     */
    $paths = [
        'log' => '<path d="M12 3v18"/><circle cx="12" cy="12" r="3"/><path d="M3 7h9M3 17h9"/>',
        'communities' => '<path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/>',
        'blog' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5"/><path d="M8 13h8M8 17h5"/>',
        'marketplace' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'profile' => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'settings' => '<path d="M20 7h-9M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
        'remotes' => '<path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2Z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/>',
        'bell' => '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>',
        'bell-off' => '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="M17 17H4a1 1 0 0 1-.7-1.7C4.6 14 6 12.5 6 8a6 6 0 0 1 .3-1.7"/><path d="m2 2 20 20"/><path d="M8.7 3a6 6 0 0 1 9.3 5c0 2.7.8 4.7 1.7 6.1"/>',
        'archive' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 10.8a2 2 0 0 1-1.1 1.8l-1.8.9A2 2 0 0 0 5 15.2V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.8a2 2 0 0 0-1.1-1.8l-1.8-.9A2 2 0 0 1 15 10.8V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1Z"/>',
        'star' => '<path d="M11.5 3.2a.6.6 0 0 1 1 0l2.4 4.9 5.4.8a.6.6 0 0 1 .3 1l-3.9 3.8.9 5.4a.6.6 0 0 1-.9.6L12 17.2l-4.8 2.5a.6.6 0 0 1-.9-.6l.9-5.4-3.9-3.8a.6.6 0 0 1 .3-1l5.4-.8Z"/>',
        'pen' => '<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.4 2.6a2 2 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'collapse' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m16 15-3-3 3-3"/>',
        'expand' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m14 9 3 3-3 3"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="1.6"/><path d="m21 15-4.5-4.5L7 21"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/>',
        'reply' => '<path d="M9 17l-5-5 5-5"/><path d="M4 12h10a6 6 0 0 1 6 6v2"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
        'up' => '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
        'spark' => '<path d="M12 3l1.6 5.4L19 10l-5.4 1.6L12 17l-1.6-5.4L5 10l5.4-1.6Z"/>',
        'activity' => '<path d="M22 12h-2.5a2 2 0 0 0-1.9 1.5l-2.3 8.3a.25.25 0 0 1-.5 0L9.3 2.2a.25.25 0 0 0-.5 0L6.5 10.5A2 2 0 0 1 4.6 12H2"/>',
        'flag' => '<path d="M4 22V4"/><path d="M4 4h11l-1.6 4.2L15 12.4H4Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'poll' => '<path d="M6 20v-8" stroke-linecap="round"/><path d="M12 20V6" stroke-linecap="round"/><path d="M18 20v-4" stroke-linecap="round"/>',
        'check' => '<path d="m4 12 5.5 5.5L20 6"/>',
        'github' => '<path d="M15 22v-4a3.5 3.5 0 0 0-1-2.7c3 0 5-2 5-5.5a4.3 4.3 0 0 0-1.2-3 4 4 0 0 0-.1-3s-1.2-.4-3.9 1.5a13 13 0 0 0-6.6 0C4.5 3.4 3.3 3.8 3.3 3.8a4 4 0 0 0-.1 3A4.3 4.3 0 0 0 2 9.8c0 3.5 2 5.5 5 5.5a3.5 3.5 0 0 0-1 2.6V22"/><path d="M6 19c-1.5.5-2.5-.5-3-1.5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
        'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'lock' => '<rect width="16" height="11" x="4" y="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'ai-hub' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><path d="m5.6 5.6 2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/><circle cx="12" cy="12" r="2.5"/>',
        'ideas' => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2h5c0-.8.4-1.5 1-2A6 6 0 0 0 12 3Z"/>',
        'dev-lab' => '<path d="m8 6-6 6 6 6"/><path d="m16 6 6 6-6 6"/><path d="m13 4-2 16"/>',
        'article' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
        'topic' => '<path d="M4 9h16M4 15h16"/><path d="M7 3 3 6v12l4 3 4-3V6ZM17 3l-4 3v12l4 3 4-3V6Z"/>',
        'path' => '<circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M8.5 19H15a3.5 3.5 0 0 0 0-7H9a3.5 3.5 0 0 1 0-7h6.5"/>',
        'resource' => '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
        'terminal' => '<path d="m4 17 6-6-6-6"/><path d="M12 19h8"/>',
        'snippet' => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'grid' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'ellipsis' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'ban' => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'down' => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'users' => '<path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/>',
    ];
@endphp

<svg
    {{ $attributes->class(['dn-icon']) }}
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
>{!! $paths[$name] ?? '' !!}</svg>
