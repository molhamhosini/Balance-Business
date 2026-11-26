<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** Daily automatic backup (requirements §8). Scheduled in routes/console.php. */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--keep=30 : Number of daily backups to keep}';

    protected $description = 'Dump the MySQL database to storage/app/backups (gzip) and prune old copies';

    public function handle(): int
    {
        $c = config('database.connections.'.config('database.default'));
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $file = $dir.'/'.$c['database'].'-'.now()->format('Y-m-d_His').'.sql.gz';

        if (! \App\Support\SqlDump::available()) { // shared hosting: no proc_open, dump with PHP
            try {
                app(\App\Support\SqlDump::class)->dump($file);
            } catch (\Throwable $e) {
                $this->error('Backup failed: '.$e->getMessage());
                @unlink($file);

                return self::FAILURE;
            }

            return $this->prune($dir, $file);
        }

        $cmd = sprintf('mysqldump --single-transaction --routines --triggers --no-tablespaces -h%s -P%s -u%s %s | gzip > %s',
            escapeshellarg($c['host']), escapeshellarg((string) $c['port']), escapeshellarg($c['username']), escapeshellarg($c['database']), escapeshellarg($file));
        $p = Process::fromShellCommandline($cmd, null, ['MYSQL_PWD' => (string) $c['password']], null, 600);
        $p->run();
        if (! $p->isSuccessful() || filesize($file) < 100) {
            $this->error('Backup failed: '.$p->getErrorOutput());
            @unlink($file);

            return self::FAILURE;
        }

        return $this->prune($dir, $file);
    }

    private function prune(string $dir, string $file): int
    {
        $files = collect(File::glob($dir.'/*.sql.gz'))->sort()->values();
        $files->slice(0, max(0, $files->count() - (int) $this->option('keep')))->each(fn ($f) => File::delete($f));
        $this->info('Backup written: '.$file);

        return self::SUCCESS;
    }
}
