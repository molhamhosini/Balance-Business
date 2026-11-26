<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\Base\InvoiceResource;
use App\Filament\Admin\Resources\SalesInvoiceResource\Pages;

class SalesInvoiceResource extends InvoiceResource
{
    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'sales-invoices';

    public static function side(): string { return 'sale'; }
    public static function partyResource(): string { return CustomerResource::class; }
    public static function getNavigationGroup(): ?string { return __('Sales'); }
    public static function getModelLabel(): string { return __('Sales invoice'); }
    public static function getPluralModelLabel(): string { return __('Sales invoices'); }

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
