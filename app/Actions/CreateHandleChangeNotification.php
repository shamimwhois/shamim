<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class CreateHandleChangeNotification
{
    /**
     * Renaming is announced to the person who did it, the same way leveling up is. The new handle rides in the
     * reason so the inbox can say "your username is now @handle" without exposing the key. Every change notifies,
     * so the key carries a fresh uuid rather than a fixed value that would deduplicate the next rename away.
     */
    public function handle(User $user, string $handle): void
    {
        Notification::query()->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'recipient_id' => $user->id,
            'actor_id' => $user->id,
            'type' => NotificationType::HandleChanged->value,
            'commit_id' => null,
            'reason' => $handle,
            'key' => 'handle_changed:'.Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
