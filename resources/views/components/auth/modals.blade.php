{{--
    The guest authentication dialogs, as one mount point.

    Every public shell includes this component once. It renders nothing for a
    signed-in account — each dialog also guards itself — so the layout never
    needs an auth check. The three dialogs are the whole guest auth journey:
    login, registration, and the forgot-password request. Password reset
    (token-bearing), two-factor challenge, confirm-password, and verify-email
    remain standalone pages under pages/auth because they are reached from
    emails or an in-progress session, not from a guest click.
--}}

@guest
    <x-auth.login-modal />
    <x-auth.register-modal />
    <x-auth.forgot-password-modal />
@endguest
