<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final readonly class CreateHandleChangeConfirmation
{
    public static function key(User $user): string
    {
        return "handle-change:{$user->id}";
    }

    /**
     * A username change must be confirmed by the person already holding the session, so the first step leaves a
     * short-lived secret in the cache with the handle it was issued for. Only its SHA-256 hash is stored; the plain
     * confirmation travels to the browser once and is never kept. Redeeming it requires both the confirmation and
     * the same handle, so issuing a new one invalidates any older dialog still open.
     */
    public function handle(User $user, string $handle): string
    {
        $confirmation = Str::random(32);

        Cache::put(self::key($user), [
            'handle' => $handle,
            'confirmation' => hash('sha256', $confirmation),
        ], now()->addMinutes(10));

        return $confirmation;
    }
}
