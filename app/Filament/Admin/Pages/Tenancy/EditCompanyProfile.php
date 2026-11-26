<?php

namespace App\Filament\Admin\Pages\Tenancy;

use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\EditTenantProfile;

class EditCompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string { return __('Company settings'); }

    public static function canView(\Illuminate\Database\Eloquent\Model $tenant): bool
    {
        return auth()->user()->can(Perm::SETTINGS_MANAGE);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Company details'))->description(__('Shown on printed documents.'))->columns(2)->schema([
                Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
                Forms\Components\TextInput::make('name_en')->label(__('English name')),
                Forms\Components\TextInput::make('trn')->label(__('TRN'))->length(15),
                Forms\Components\TextInput::make('phone')->label(__('Phone')),
                Forms\Components\TextInput::make('email')->label(__('Email'))->email(),
                Forms\Components\TextInput::make('address')->label(__('Address')),
                Forms\Components\FileUpload::make('logo_path')->label(__('Logo'))->image()->disk('public')->directory('logos'),
                Forms\Components\TextInput::make('base_currency')->label(__('Base currency'))->default('AED')->length(3)->disabled(),
                Forms\Components\Textarea::make('invoice_footer')->label(__('Invoice footer'))->rows(3)->columnSpanFull()
                    ->helperText(__('Printed at the bottom of every invoice, e.g. bank account / IBAN and payment terms.')),
            ]),
            Forms\Components\Section::make(__('Controls'))->columns(3)->schema([
                Forms\Components\Toggle::make('allow_negative_stock')->label(__('Allow selling or issuing more than the quantity in stock'))->inline(false),
                Forms\Components\TextInput::make('voucher_approval_limit')->label(__('Payment approval limit (chief accountant above)'))->numeric()->required(),
                Forms\Components\Toggle::make('allow_negative_cash')->label(__('Allow negative cash boxes'))->inline(false),
            ]),
        ]);
    }
}
