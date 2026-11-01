<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| The team-invitation prune task was removed along with the team feature. These
| two entries replace it, and both exist because the feed's write path defers
| work to a later moment rather than doing it in the request.
|
| `feed:publish-scheduled` runs every minute because a schedule is only worth
| what its author believes it to be: a post promised for 09:00 that appears at
| 09:07 is seven minutes of a promise broken, and the row is already written, so
| the tick costs one indexed query. It is idempotent, so a missed tick catches up
| without publishing anything twice.
|
| `feed:prune-media` runs daily because staged uploads have a grace period
| measured in hours — an upload abandoned this morning is not garbage by lunch.
| Left alone, an unfinished composer would leak files onto the private disk
| forever, and the disk is one nothing else can list.
|
*/

Schedule::command('feed:publish-scheduled')->everyMinute();

Schedule::command('feed:prune-media')->daily();
