<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
|
| Account settings are shared by every role — a seller and an admin manage
| their profile, security and appearance through the same screens as a reader.
| There is no team-scoped settings surface: the team feature and its
| `/settings/teams` routes were removed with the rest of the team context.
|
*/

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/terminal', 'pages::settings.terminal')->name('settings.terminal');
    Route::livewire('settings/safety', 'pages::settings.safety')->name('settings.safety');
    Route::livewire('settings/devices', 'pages::settings.devices')->name('settings.devices');
    Route::livewire('settings/billing', 'pages::settings.billing')->name('settings.billing');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('security.edit');
});

/*
| Backward-compatible redirect for the removed team settings page. A bookmark
| or an old link lands on the security screen rather than a 404 — the closest
| surviving screen, since the team surface no longer exists.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('settings/teams', '/settings/security', 301)->name('teams.index.redirect');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
