<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\Base\PartyResource;
use App\Filament\Admin\Resources\CustomerResource\Pages;

class CustomerResource extends PartyResource
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'customers';

    public static function partyType(): string { return 'customer'; }
    public static function invoiceResource(): string { return SalesInvoiceResource::class; }
    public static function getNavigationGroup(): ?string { return __('Sales'); }
    public static function getModelLabel(): string { return __('Customer'); }
    public static function getPluralModelLabel(): string { return __('Customers'); }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParties::route('/'),
            'create' => Pages\CreateParty::route('/create'),
            'view' => Pages\ViewParty::route('/{record}'),
            'edit' => Pages\EditParty::route('/{record}/edit'),
        ];
    }
}
