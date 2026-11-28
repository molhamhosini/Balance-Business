<?php

namespace App\Support;

/** Table columns that store name_ar show name_en instead when the interface is in English (falling back to Arabic). */
class Localized
{
    public static function name(?string $relation = null): \Closure
    {
        return function ($state, $record) use ($relation) {
            $model = $relation ? data_get($record, $relation) : $record;

            return app()->getLocale() === 'en' && filled($model?->name_en ?? null) ? $model->name_en : $state;
        };
    }
}
