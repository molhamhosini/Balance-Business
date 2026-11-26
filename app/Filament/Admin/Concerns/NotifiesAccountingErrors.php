<?php

namespace App\Filament\Admin\Concerns;

use App\Exceptions\AccountingException;
use Filament\Notifications\Notification;

trait NotifiesAccountingErrors
{
    /** Run an accounting action; show rule violations as a red notification instead of an error page. */
    protected static function attempt(callable $fn, ?string $success = null): bool
    {
        try {
            $fn();
            if ($success) {
                Notification::make()->success()->title($success)->send();
            }

            return true;
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->persistent()->send();

            return false;
        }
    }
}
