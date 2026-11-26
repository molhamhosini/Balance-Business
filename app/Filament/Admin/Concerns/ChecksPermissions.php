<?php

namespace App\Filament\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;

/** Maps Filament abilities to Spatie permissions declared on the resource. */
trait ChecksPermissions
{
    protected static function allowed(?string $perm): bool
    {
        return $perm === null || (bool) auth()->user()?->can($perm);
    }

    public static function canViewAny(): bool { return static::allowed(static::$viewPermission ?? null); }
    public static function canCreate(): bool { return static::allowed(static::$createPermission ?? null); }
    public static function canEdit(Model $record): bool { return static::allowed(static::$createPermission ?? null) && static::isEditable($record); }
    public static function canDelete(Model $record): bool { return static::allowed(static::$createPermission ?? null) && static::isEditable($record); }
    public static function canDeleteAny(): bool { return false; }
    public static function isEditable(Model $record): bool { return true; }
}
