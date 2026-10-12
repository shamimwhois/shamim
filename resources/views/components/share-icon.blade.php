@props([
    'name',
])

@php
    /**
     * Brand marks for share targets.
     *
     * Flux ships Heroicons, which has no brand logos — and pulling in a brand
     * icon package for six glyphs is not worth the dependency. These are inline
     * paths, sized to match a 3.5 `size-*` utility (14px), using `currentColor`
     * so they inherit the button's hover state.
     *
     * `envelope` and `link` are not brands; they fall through to Flux/Heroicons
     * so there is exactly one icon source per concept.
     */
@endphp

@switch($name)
    @case('x')
        <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
        </svg>
        @break

    @case('linkedin')
        <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
        </svg>
        @break

    @case('hackernews')
        <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M0 0h24v24H0V0zm11.34 12.98L6.72 5.4h2.05l2.4 4.1c.32.55.6 1.12.83 1.7h.05c.24-.6.52-1.17.85-1.71l2.44-4.09h2.02l-4.66 7.55v5.65h-1.36v-5.62z" />
        </svg>
        @break

    @case('reddit')
        <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 0C5.373 0 0 5.373 0 12c0 3.314 1.343 6.314 3.515 8.485l-2.286 2.286A.75.75 0 001.75 24H12c6.627 0 12-5.373 12-12S18.627 0 12 0zm5.01 13.402c.019.14.03.283.03.428 0 2.263-2.617 4.098-5.845 4.098-3.227 0-5.844-1.835-5.844-4.098 0-.147.011-.29.032-.43a1.246 1.246 0 01-.76-1.148c0-.688.557-1.246 1.245-1.246.328 0 .627.127.85.334C8.02 10.86 9.62 10.5 11.4 10.45l.6-2.826a.248.248 0 01.296-.194l1.98.42a.877.877 0 011.594.47.877.877 0 11-1.754 0c0-.057.006-.113.017-.166l-1.767-.375-.536 2.52c1.756.06 3.332.42 4.42.98a1.243 1.243 0 012.096.912c0 .483-.276.9-.68 1.109zM9.172 13.83a.877.877 0 100-1.754.877.877 0 000 1.754zm5.656 0a.877.877 0 100-1.754.877.877 0 000 1.754zm-.222 1.69a.234.234 0 00-.33-.001c-.53.512-1.4.768-2.276.768-.876 0-1.745-.256-2.275-.768a.234.234 0 00-.33.33c.667.643 1.706.938 2.605.938.9 0 1.939-.295 2.606-.938a.234.234 0 000-.329z" />
        </svg>
        @break

    @case('facebook')
        <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047v-2.66c0-3.026 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.971H15.83c-1.491 0-1.956.93-1.956 1.886v2.264h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" />
        </svg>
        @break

    @case('envelope')
        <flux:icon name="envelope" class="size-3.5" />
        @break

    @default
        <flux:icon name="link" class="size-3.5" />
@endswitch
