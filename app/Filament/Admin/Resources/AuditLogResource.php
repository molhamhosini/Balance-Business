<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Support\Perm;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;
    protected static ?string $navigationIcon = 'heroicon-o-finger-print';
    protected static ?int $navigationSort = 9;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('Audit entry'); }
    public static function getPluralModelLabel(): string { return __('Audit trail'); }

    public static function canViewAny(): bool { return auth()->user()->canAny([Perm::AUDIT_VIEW, Perm::SETTINGS_MANAGE]); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->columns(3)->schema([
            Infolists\Components\TextEntry::make('created_at')->label(__('Time'))->dateTime('Y-m-d H:i:s'),
            Infolists\Components\TextEntry::make('user.name')->label(__('User')),
            Infolists\Components\TextEntry::make('action')->label(__('Action'))->formatStateUsing(fn ($state) => __(AuditLog::ACTIONS[$state] ?? $state)),
            Infolists\Components\TextEntry::make('auditable_type')->label(__('Record'))->formatStateUsing(fn ($state, AuditLog $r) => class_basename($state).' #'.$r->auditable_id),
            Infolists\Components\TextEntry::make('reason')->label(__('Reason'))->placeholder('—'),
            Infolists\Components\TextEntry::make('ip')->label('IP'),
            Infolists\Components\TextEntry::make('before')->label(__('Before'))->columnSpanFull()->formatStateUsing(fn ($state) => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->fontFamily('mono'),
            Infolists\Components\TextEntry::make('after')->label(__('After'))->columnSpanFull()->formatStateUsing(fn ($state) => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->fontFamily('mono'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->label(__('Time'))->dateTime('Y-m-d H:i'),
            Tables\Columns\TextColumn::make('user.name')->label(__('User'))->searchable(),
            Tables\Columns\TextColumn::make('action')->label(__('Action'))->badge()->formatStateUsing(fn ($state) => __(AuditLog::ACTIONS[$state] ?? $state)),
            Tables\Columns\TextColumn::make('auditable_type')->label(__('Record'))->formatStateUsing(fn ($state, AuditLog $r) => class_basename($state).' #'.$r->auditable_id),
            Tables\Columns\TextColumn::make('reason')->label(__('Reason'))->limit(40),
        ])->filters([
            Tables\Filters\SelectFilter::make('action')->label(__('Action'))->options(fn () => collect(AuditLog::ACTIONS)->map(fn ($l) => __($l))->all()),
            Tables\Filters\SelectFilter::make('user_id')->label(__('User'))->relationship('user', 'name'),
        ])->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
