<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pure-PHP MySQL dump and restore, for hosts where proc_open / mysqldump are not available
 * (e.g. Hostinger shared and cloud plans). Each statement is followed by a separator line so
 * that triggers (which contain semicolons) restore without DELIMITER handling.
 */
class SqlDump
{
    public const SEP = "\n-- @@end\n";

    /** Data that must not travel with a backup: restoring old sessions would log everyone out. */
    public const SKIP_DATA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches'];

    public static function available(): bool
    {
        return function_exists('proc_open') && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    public function dump(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $gz = gzopen($path, 'wb6') ?: throw new \RuntimeException('Cannot write '.$path);
        $w = fn (string $sql) => gzwrite($gz, $sql.self::SEP);
        gzwrite($gz, '-- Business Balance backup '.now()->toIso8601String()."\n");
        $w('SET FOREIGN_KEY_CHECKS=0');
        $w("SET NAMES utf8mb4");
        $tables = collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))->map(fn ($r) => array_values((array) $r)[0]);
        foreach ($tables as $t) {
            $create = array_values((array) DB::selectOne("SHOW CREATE TABLE `$t`"))[1];
            $w("DROP TABLE IF EXISTS `$t`");
            $w($create);
            if (in_array($t, self::SKIP_DATA, true)) {
                continue;
            }
            $batch = [];
            foreach (DB::table($t)->cursor() as $row) {
                $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), (array) $row)).')';
                if (count($batch) === 200) {
                    $w("INSERT INTO `$t` VALUES ".implode(',', $batch));
                    $batch = [];
                }
            }
            if ($batch) {
                $w("INSERT INTO `$t` VALUES ".implode(',', $batch));
            }
        }
        foreach (DB::select('SHOW TRIGGERS') as $tr) {
            $create = array_values((array) DB::selectOne('SHOW CREATE TRIGGER `'.$tr->Trigger.'`'))[2];
            $w('DROP TRIGGER IF EXISTS `'.$tr->Trigger.'`');
            $w(preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $create));
        }
        $w('SET FOREIGN_KEY_CHECKS=1');
        gzclose($gz);
    }

    public function restore(string $path): int
    {
        $sql = gzdecode((string) file_get_contents($path));
        if ($sql === false) {
            throw new \RuntimeException('Not a gzip file');
        }
        // our own format uses the separator; a plain mysqldump file is split on statement ends
        $parts = str_contains($sql, self::SEP) ? explode(self::SEP, $sql) : preg_split('/;\s*\n/', $sql);
        $n = 0;
        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
        foreach ($parts as $stmt) {
            $stmt = trim(preg_replace('/^--[^\n]*\n?/m', '', $stmt));
            if ($stmt === '' || str_starts_with($stmt, 'DELIMITER') || $this->touchesSkipped($stmt)) {
                continue;
            }
            DB::unprepared($stmt);
            $n++;
        }
        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');

        return $n;
    }

    /** Keep the live sessions / cache tables as they are (so the person restoring stays signed in). */
    private function touchesSkipped(string $stmt): bool
    {
        foreach (self::SKIP_DATA as $t) {
            if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|LOCK TABLES|ALTER TABLE)\s+`'.$t.'`/i', $stmt)) {
                return true;
            }
        }

        return false;
    }
}
