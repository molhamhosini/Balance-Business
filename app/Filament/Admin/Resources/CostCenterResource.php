<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Pages\CostCenterReport;
use App\Filament\Admin\Resources\CostCenterResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\CostCenter;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Cost centers / projects: a tree of its own, chosen on each journal, invoice or voucher line. */
class CostCenterResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = CostCenter::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';
    protected static ?int $navigationSort = 7;
    protected static ?string $viewPermission = Perm::ENTRIES_VIEW;
    protected static ?string $createPermission = Perm::ACCOUNTS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('Cost center'); }
    public static function getPluralModelLabel(): string { return __('Cost centers'); }

    public static function canDelete(Model $record): bool { return static::allowed(Perm::ACCOUNTS_MANAGE) && ! \App\Models\JournalLine::where('cost_center_id', $record->id)->exists() && ! $record->children()->exists(); }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('code')->label(__('Code'))->required()->maxLength(20)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', Options::companyId())),
            Forms\Components\Select::make('parent_id')->label(__('Parent'))->options(fn (?CostCenter $record) => Options::costCenters($record?->id)),
            Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
            Forms\Components\TextInput::make('name_en')->label(__('English name')),
            Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->columns([
            Tables\Columns\TextColumn::make('code')->label(__('Code'))->weight('bold')->searchable(),
            Tables\Columns\TextColumn::make('name_ar')->formatStateUsing(\App\Support\Localized::name())->label(__('Name'))->searchable(['name_ar', 'name_en']),
            Tables\Columns\TextColumn::make('parent.name_ar')->formatStateUsing(\App\Support\Localized::name('parent'))->label(__('Parent'))->placeholder('—'),
            Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
        ])->actions([
            Tables\Actions\Action::make('report')->label(__('Profitability'))->icon('heroicon-o-chart-bar')->iconButton()->url(fn () => CostCenterReport::getUrl()),
            Tables\Actions\EditAction::make()->iconButton(),
            Tables\Actions\DeleteAction::make()->iconButton(),
        ]);
    }

    public static function getPages(): array { return ['index' => Pages\ManageCostCenters::route('/')]; }
}
