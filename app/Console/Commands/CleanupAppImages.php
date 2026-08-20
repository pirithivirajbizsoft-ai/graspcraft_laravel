<?php

namespace App\Console\Commands;

use App\Support\LocalImageStorage;
use Illuminate\Console\Command;

/**
 * Deletes local storage files under the 'app' folder (photographer App raw
 * captures) older than 1 day. Scheduled daily in routes/console.php, which
 * calls this with no --days so the real 1-day retention always applies.
 *
 * --days is only for manual testing, e.g. `php artisan images:cleanup-app
 * --days=0` deletes everything in app/ right now, regardless of age.
 *
 * Only deletes files — no database rows are touched, so an ImagesUpload row
 * whose file has aged out will keep pointing at a now-missing file.
 */
class CleanupAppImages extends Command
{
    protected $signature = 'images:cleanup-app {--days=1 : Delete files older than this many days}';

    protected $description = "Delete files in local storage's 'app' folder older than N days (default 1)";

    public function handle(LocalImageStorage $storage): int
    {
        $days = (int) $this->option('days');
        $deleted = $storage->deleteOlderThan('app', $days);

        $this->info("Deleted {$deleted} file(s) from 'app' older than {$days} day(s).");

        return self::SUCCESS;
    }
}
