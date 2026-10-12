{{--
    Auth layout entry point.

    All seven Fortify screens reference `<x-layouts::auth>`. Rather than
    scatter that reference across the view tree, it resolves here to exactly
    one implementation, so switching the authentication design is a one-line
    change to this file and nothing else.
--}}
<x-layouts::auth.split :title="$title ?? null">
    {{ $slot }}
</x-layouts::auth.split>
