<?php

namespace App\Actions\DevLab;

use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Record that an account pressed Run.
 *
 * This is the only write the Run button performs, and the two things it refuses are
 * the module's whole execution policy:
 *
 *   1. **A language the browser cannot run.** {@see LabLanguage::isBrowserRunnable()}
 *      is re-checked here rather than trusted from the interface, because the editor
 *      only draws the button for the three runnable panes and a hand-crafted request
 *      does not care what the editor drew. Without this check, a PHP run would be
 *      recorded against a language that has no runner, and the history would be
 *      lying about something happening.
 *   2. **A run past the day's allowance.** The code executes in the visitor's own
 *      tab, so the limit is not there to protect a server; it is there to bound the
 *      loop, the freeze, and the report that follows. It is counted by day rather
 *      than by hour because the sentence an account needs is "you have 40 runs left
 *      today", not a sliding window nobody can read.
 *
 * The output is not stored. A run is an event, the console belongs to the browser
 * that made it, and keeping a copy of whatever somebody logged would turn a history
 * into an archive of text nobody reads twice.
 */
class RecordLabExecution
{
    /**
     * Record one run.
     *
     * @throws InvalidArgumentException when the language does not run or the day is used up
     */
    public function handle(User $author, LabLanguage $language, ?string $label = null): LabExecution
    {
        if (! $language->isBrowserRunnable()) {
            throw new InvalidArgumentException($language->runExplanation());
        }

        $limit = $this->dailyLimit();

        if ($limit > 0 && $this->usedToday($author) >= $limit) {
            throw new InvalidArgumentException(
                __('You have used all :limit runs allowed today. The allowance resets at midnight, and nothing you saved is affected.', [
                    'limit' => $limit,
                ])
            );
        }

        return DB::transaction(function () use ($author, $language, $label): LabExecution {
            $execution = new LabExecution;
            $execution->forceFill([
                'user_id' => $author->id,
                'language' => $language,
                'label' => $label === null ? null : mb_substr(trim($label), 0, LabExecution::LABEL_MAX),
            ])->save();

            return $execution;
        });
    }

    /**
     * Count the runs one account has performed today.
     *
     * Public because the editor and the dashboard both display the remaining count,
     * and a number computed somewhere else would be a second implementation of the
     * rule rather than the rule.
     */
    public function usedToday(User $user): int
    {
        return LabExecution::query()
            ->where('user_id', $user->id)
            ->onDay(Carbon::today())
            ->count();
    }

    /**
     * Count how many runs remain to the account today.
     *
     * An unlimited day reports null rather than a very large number. A screen that
     * prints "9223372036854775807 runs left" is a screen that lied about having a
     * limit, and the ceiling exists to be configured away on purpose, not to be
     * shown as a joke.
     */
    public function remainingToday(User $user): ?int
    {
        $limit = $this->dailyLimit();

        if ($limit === 0) {
            return null;
        }

        return max(0, $limit - $this->usedToday($user));
    }

    /**
     * The configured ceiling, where zero means no ceiling at all.
     */
    protected function dailyLimit(): int
    {
        return max(0, (int) config('dev_lab.daily_run_limit'));
    }
}
