<?php

namespace App\Filament\Admin\Pages\Tenancy;

use App\Models\Branch;
use App\Models\Company;
use App\Services\Accounting\CompanySetup;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\RegisterTenant;
use Illuminate\Support\Facades\Storage;

/** New company with its own TRN and books, set up from the standard chart of accounts. */
class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string { return __('New company'); }

    public static function canView(): bool
    {
        return auth()->user()->can(Perm::SETTINGS_MANAGE);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
            Forms\Components\TextInput::make('name_en')->label(__('English name'))->required(),
            Forms\Components\TextInput::make('trn')->label(__('TRN'))->length(15),
            Forms\Components\Select::make('emirate')->label(__('Emirate'))->options(Branch::EMIRATES)->default('AUH')->required(),
            Forms\Components\Select::make('activity')->label(__('Activity'))->options(['slaughterhouse' => __('Slaughterhouse'), 'restaurant' => __('Restaurant'), 'other' => __('Other')])->required(),
            Forms\Components\DatePicker::make('fiscal_year_start')->label(__('Fiscal year start'))->required()->live()->native(false)->displayFormat('Y-m-d')
                ->helperText(__('Any date; the year lasts 12 months from it.')),
            Forms\Components\Placeholder::make('end_preview')->label(__('End date (12 months)'))
            ->content(fn (\Filament\Forms\Get $get) => $get('fiscal_year_start') ? \Carbon\CarbonImmutable::parse($get('fiscal_year_start'))->addMonthsNoOverflow(12)->subDay()->format('Y-m-d') : '—'),
            Forms\Components\FileUpload::make('chart')->label(__('Chart of accounts (optional, Excel)'))->disk('local')->directory('imports')
                ->helperText(__('Leave empty to use the standard chart supplied with the system.')),
        ]);
    }

    protected function handleRegistration(array $data): Company
    {
        $chart = ! empty($data['chart']) ? Storage::disk('local')->path($data['chart']) : database_path('data/chart-of-accounts.xlsx');
        $sisters = auth()->user()->companies()->pluck('name_ar')->all();
        $company = app(CompanySetup::class)->create(
            ['name_ar' => $data['name_ar'], 'name_en' => $data['name_en'], 'trn' => $data['trn'] ?? null, 'emirate' => $data['emirate']],
            $data['activity'], $chart, $data['fiscal_year_start'], $sisters,
        );
        $company->users()->attach(auth()->id());

        return $company;
    }
}
