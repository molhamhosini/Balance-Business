<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Daily automatic backup (requirements §8). Scheduled in routes/console.php. */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--keep=30 : Kept for compatibility; daily backups keep 30}';

    protected $description = 'Dump the MySQL database to storage/app/backups (gzip) and prune old copies';

    public function handle(): int
    {
        try {
            $name = app(\App\Services\System\BackupService::class)->create('daily');
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Backup written: storage/app/backups/'.$name);

        return self::SUCCESS;
    }
}
