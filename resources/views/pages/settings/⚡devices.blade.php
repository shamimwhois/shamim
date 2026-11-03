<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Devices — where the account is signed in.
 *
 * Read from the session table itself rather than a second registry: a row that
 * exists is a session that can act, so the list cannot claim a device that is
 * already gone. The current session is marked rather than offered for removal,
 * because signing yourself out from a settings page is a prank, not a feature.
 */
new #[Title('Devices')] #[Layout('layouts::feed')] class extends Component
{
    /**
     * The signed-in sessions, most recent first.
     *
     * @return array<int, array{id: string, browser: string, platform: string, ip: string, last: string, current: bool}>
     */
    #[Computed]
    public function devices(): array
    {
        $current = session()->getId();

        return DB::table('sessions')
            ->where('user_id', auth()->id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $session) use ($current): array {
                $agent = (string) ($session->user_agent ?? '');

                return [
                    'id' => (string) $session->id,
                    'browser' => $this->browser($agent),
                    'platform' => $this->platform($agent),
                    'ip' => (string) ($session->ip_address ?? '—'),
                    'last' => now()->setTimestamp((int) $session->last_activity)->diffForHumans(),
                    'current' => $session->id === $current,
                ];
            })
            ->all();
    }

    /** Sign out every other session. */
    public function signOutOthers(): void
    {
        DB::table('sessions')
            ->where('user_id', auth()->id())
            ->where('id', '!=', session()->getId())
            ->delete();

        unset($this->devices);
    }

    /** A readable browser name, without pulling in a parser. */
    private function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            str_contains($agent, 'Firefox/') => 'Firefox',
            default => __('Unknown browser'),
        };
    }

    /** A readable platform name. */
    private function platform(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('Unknown platform'),
        };
    }
}; ?>

<x-log.settings tab="devices">
    <section class="dn-settings-section" aria-labelledby="settings-devices">
        <h2 id="settings-devices" class="dn-settings-title">{{ __('Devices') }}</h2>
        <p class="dn-settings-note">
            {{ __('Every browser signed in to this account. A session that is gone from this list can no longer act.') }}
        </p>

        <div class="dn-list">
            @forelse ($this->devices as $device)
                <div class="dn-list-row" data-test="device">
                    <x-feed.icon name="monitor" :size="18" />
                    <span class="dn-list-body">
                        <span class="dn-list-title">
                            {{ $device['browser'] }} · {{ $device['platform'] }}
                            @if ($device['current'])
                                <span class="dn-status-chip">{{ __('this device') }}</span>
                            @endif
                        </span>
                        <span class="dn-list-meta">{{ $device['ip'] }} · {{ $device['last'] }}</span>
                    </span>
                </div>
            @empty
                <p class="dn-list-empty">fatal: no sessions</p>
            @endforelse
        </div>

        <div class="dn-form-row" style="justify-content: flex-start;">
            <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="signOutOthers" data-test="sign-out-others">
                {{ __('Sign out other sessions') }}
            </button>
        </div>
    </section>
</x-log.settings>
