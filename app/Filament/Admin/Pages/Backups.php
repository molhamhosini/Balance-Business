<?php

namespace App\Filament\Admin\Pages;

use App\Services\System\BackupService;
use App\Support\Perm;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Database backups: list, take one now, download, restore (with a safety copy first) and delete.
 * A restore rolls back the WHOLE database (all companies and users), so the page is admin-only.
 */
class Backups extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';
    protected static ?int $navigationSort = 95;
    protected static string $view = 'filament.pages.backups';

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getNavigationLabel(): string { return __('Backups'); }
    public function getTitle(): string { return __('Backup and restore'); }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Perm::USERS_MANAGE);
    }

    public function backups(): array
    {
        return app(BackupService::class)->list();
    }

    public static function tagLabel(string $tag): string
    {
        return __(BackupService::TAGS[$tag] ?? $tag);
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format(max(1, $bytes / 1024), 0).' KB';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('Create backup now'))->icon('heroicon-o-plus')
                ->form([Forms\Components\TextInput::make('note')->label(__('Note'))->maxLength(200)->placeholder(__('e.g. before closing the year'))])
                ->action(function (array $data) {
                    try {
                        $name = app(BackupService::class)->create('manual', $data['note'] ?? null);
                        Notification::make()->success()->title(__('Backup created'))->body($name)->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title(__('Backup failed'))->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }

    public function downloadAction(): Action
    {
        return Action::make('download')->label(__('Download'))->icon('heroicon-o-arrow-down-tray')->color('gray')->link()
            ->action(fn (array $arguments) => response()->download(app(BackupService::class)->path($arguments['name'] ?? '')));
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')->label(__('Restore'))->icon('heroicon-o-arrow-uturn-left')->color('warning')->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => __('Restore backup :n?', ['n' => $arguments['name'] ?? '']))
            ->modalDescription(__('All data of ALL companies (entries, invoices, users, settings) goes back to the moment of this backup. Anything done after it is removed. A backup of the current state is taken first, so you can undo this.'))
            ->modalSubmitActionLabel(__('Restore'))
            ->form([Forms\Components\TextInput::make('confirm')->label(__('Type RESTORE to confirm'))->required()
                ->rule(fn () => function ($attr, $value, $fail) {
                    if (mb_strtoupper(trim((string) $value)) !== 'RESTORE' && trim((string) $value) !== 'استرجاع') {
                        $fail(__('Type RESTORE to confirm'));
                    }
                })])
            ->action(function (array $arguments) {
                try {
                    $safety = app(BackupService::class)->restore($arguments['name'] ?? '');
                    Notification::make()->success()->persistent()->title(__('Backup restored'))
                        ->body(__('The state before the restore was saved as :n.', ['n' => $safety]))->send();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->danger()->persistent()->title(__('Restore failed'))->body($e->getMessage())->send();
                }
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')->label(__('Delete'))->icon('heroicon-o-trash')->color('danger')->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => __('Delete backup :n?', ['n' => $arguments['name'] ?? '']))
            ->action(function (array $arguments) {
                app(BackupService::class)->delete($arguments['name'] ?? '');
                Notification::make()->success()->title(__('Deleted'))->send();
            });
    }
}
