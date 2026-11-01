{{--
    Auth layout entry point.

    All seven Fortify screens reference `<x-layouts::auth>`. Rather than
    scatter that reference across the view tree, it resolves here to exactly
    one implementation, so switching the authentication design is a one-line
    change to this file and nothing else.

    The indirection also keeps the namespace honest: `layouts::auth` names the
    *audience* (an unauthenticated visitor proving who they are) while
    `layouts::auth.split` names the *implementation*. A second authentication
    design — a single-column card, say — would be added beside `split` and
    selected here, and no caller would change.
--}}
<x-layouts::auth.split :title="$title ?? null">
    {{ $slot }}
</x-layouts::auth.split>
