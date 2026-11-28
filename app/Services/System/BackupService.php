<?php

namespace App\Services\System;

use App\Models\AuditLog;
use App\Support\SqlDump;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Whole-database backups in storage/app/backups (pure PHP, works without proc_open).
 * File name: {db}-{Y-m-d_His}[-{tag}].sql.gz, tag = manual | before-import | before-restore.
 * Daily backups (no tag) keep 30 copies; each tag keeps its own last 20.
 */
class BackupService
{
    public const TAGS = ['daily' => 'Daily', 'manual' => 'Manual', 'before-import' => 'Before import', 'before-restore' => 'Before restore'];

    public const KEEP_DAILY = 30;
    public const KEEP_TAGGED = 20;

    public function dir(): string
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public function create(string $tag = 'manual', ?string $note = null): string
    {
        @set_time_limit(0);
        $db = (string) config('database.connections.'.config('database.default').'.database');
        $name = Str::slug($db).'-'.now()->format('Y-m-d_His').($tag === 'daily' ? '' : '-'.$tag).'.sql.gz';
        $path = $this->dir().'/'.$name;
        try {
            app(SqlDump::class)->dump($path);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        if ($note) {
            File::put($path.'.note', mb_substr($note, 0, 200));
        }
        $this->prune();

        return $name;
    }

    /** @return array<int, array{name:string, tag:string, date:\Illuminate\Support\Carbon, size:int, note:?string}> newest first */
    public function list(): array
    {
        return collect(File::glob($this->dir().'/*.sql.gz'))->map(function ($f) {
            $name = basename($f);
            preg_match('/(\d{4}-\d{2}-\d{2}_\d{6})(?:-([a-z-]+))?\.sql\.gz$/', $name, $m);

            return [
                'name' => $name, 'tag' => $m[2] ?? 'daily', 'size' => filesize($f),
                'date' => isset($m[1]) ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d_His', $m[1]) : \Illuminate\Support\Carbon::createFromTimestamp(filemtime($f)),
                'note' => is_file($f.'.note') ? File::get($f.'.note') : null,
            ];
        })->sortByDesc(fn ($b) => $b['date']->timestamp)->values()->all();
    }

    /** Full path of a listed backup; refuses anything else (no path tricks). */
    public function path(string $name): string
    {
        $path = $this->dir().'/'.basename($name);
        if (! preg_match('/^[a-z0-9_-]+-\d{4}-\d{2}-\d{2}_\d{6}(-[a-z-]+)?\.sql\.gz$/i', basename($name)) || ! is_file($path)) {
            throw new \InvalidArgumentException(__('Backup not found.'));
        }

        return $path;
    }

    /** Takes a "before restore" copy first, then replaces all data with the chosen backup. */
    public function restore(string $name): string
    {
        $path = $this->path($name);
        @set_time_limit(0);
        $date = preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})\d{2}/', $name, $m) ? "$m[1] $m[2]:$m[3]" : $name;
        $safety = $this->create('before-restore', __('Before restoring the backup of :n', ['n' => $date]));
        app(SqlDump::class)->restore($path);
        AuditLog::record('restored', null, null, ['backup' => $name, 'safety_copy' => $safety]);

        return $safety;
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        File::delete([$path, $path.'.note']);
    }

    public function prune(): void
    {
        collect($this->list())->groupBy('tag')->each(function ($items, $tag) {
            $items->slice($tag === 'daily' ? self::KEEP_DAILY : self::KEEP_TAGGED)->each(fn ($b) => $this->delete($b['name']));
        });
    }
}
