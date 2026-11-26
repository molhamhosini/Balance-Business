<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RestoreDatabase extends Command
{
    protected $signature = 'backup:restore {file : Path to a .sql.gz backup} {--force}';

    protected $description = 'Restore the database from a backup made by backup:database (overwrites current data)';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error('File not found');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('This replaces ALL current data. Continue?')) {
            return self::FAILURE;
        }
        $c = config('database.connections.'.config('database.default'));
        $cmd = sprintf('gunzip -c %s | mysql -h%s -P%s -u%s %s', escapeshellarg($file), escapeshellarg($c['host']), escapeshellarg((string) $c['port']), escapeshellarg($c['username']), escapeshellarg($c['database']));
        $p = Process::fromShellCommandline($cmd, null, ['MYSQL_PWD' => (string) $c['password']], null, 1200);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->error($p->getErrorOutput());

            return self::FAILURE;
        }
        $this->info('Restored from '.$file);

        return self::SUCCESS;
    }
}
