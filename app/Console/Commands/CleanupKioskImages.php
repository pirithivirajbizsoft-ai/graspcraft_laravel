<?php

namespace App\Console\Commands;

use App\Support\LocalImageStorage;
use Illuminate\Console\Command;

/**
 * Deletes local storage files under the 'kiosk' folder (kiosk raw captures,
 * plus kiosk/framed composed/retouched order images) older than 3 days.
 * Scheduled daily in routes/console.php, which calls this with no --days so
 * the real 3-day retention always applies.
 *
 * --days is only for manual testing, e.g. `php artisan images:cleanup-kiosk
 * --days=0` deletes everything in kiosk/ right now, regardless of age.
 *
 * Only deletes files — no database rows are touched, so an ImagesUpload or
 * FramImgMap row whose file has aged out will keep pointing at a now-missing
 * file.
 */
class CleanupKioskImages extends Command
{
    protected $signature = 'images:cleanup-kiosk {--days=3 : Delete files older than this many days}';

    protected $description = "Delete files in local storage's 'kiosk' folder (including kiosk/framed) older than N days (default 3)";

    public function handle(LocalImageStorage $storage): int
    {
        $days = (int) $this->option('days');
        $deleted = $storage->deleteOlderThan('kiosk', $days);

        $this->info("Deleted {$deleted} file(s) from 'kiosk' older than {$days} day(s).");

        return self::SUCCESS;
    }
}
