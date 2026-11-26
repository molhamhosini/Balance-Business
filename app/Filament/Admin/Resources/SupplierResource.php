<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\Base\PartyResource;
use App\Filament\Admin\Resources\SupplierResource\Pages;

class SupplierResource extends PartyResource
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'suppliers';

    public static function partyType(): string { return 'supplier'; }
    public static function invoiceResource(): string { return PurchaseInvoiceResource::class; }
    public static function getNavigationGroup(): ?string { return __('Purchases'); }
    public static function getModelLabel(): string { return __('Supplier'); }
    public static function getPluralModelLabel(): string { return __('Suppliers'); }

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
