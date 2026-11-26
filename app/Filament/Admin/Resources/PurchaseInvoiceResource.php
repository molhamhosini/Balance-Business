<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\Base\InvoiceResource;
use App\Filament\Admin\Resources\PurchaseInvoiceResource\Pages;

class PurchaseInvoiceResource extends InvoiceResource
{
    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'purchase-bills';

    public static function side(): string { return 'purchase'; }
    public static function partyResource(): string { return SupplierResource::class; }
    public static function getNavigationGroup(): ?string { return __('Purchases'); }
    public static function getModelLabel(): string { return __('Purchase bill'); }
    public static function getPluralModelLabel(): string { return __('Purchase bills'); }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'view' => Pages\ViewInvoice::route('/{record}'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
