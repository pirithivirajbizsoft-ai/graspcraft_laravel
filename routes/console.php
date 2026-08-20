<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Local image storage cleanup (see app/Console/Commands/CleanupAppImages and
 * CleanupKioskImages). App and Kiosk have different retention windows
 * (1 day / 3 days), so they run as two separate scheduled commands rather
 * than one parameterised command — each is named so `schedule:list` and the
 * onOneServer lock (if ever added for a multi-server deploy) key off it
 * cleanly.
 *
 * Web Master uploads (storage's 'web-master' folder) are permanent and are
 * deliberately not scheduled here.
 */
Schedule::command('images:cleanup-app')->daily()->name('cleanup-app-images');
Schedule::command('images:cleanup-kiosk')->daily()->name('cleanup-kiosk-images');
