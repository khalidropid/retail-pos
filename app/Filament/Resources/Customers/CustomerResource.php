<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Support\Domain;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'العملاء والذمم';

    protected static ?string $modelLabel = 'عميل';

    protected static ?string $pluralModelLabel = 'العملاء';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewDebts(): bool
    {
        return auth()->user()?->can('customers.view_debts') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        $canCredit = fn () => auth()->user()?->can('customers.update_credit_limit') ?? false;

        return $schema->columns(3)->components([
            Section::make('بيانات العميل')->columnSpan(2)->columns(2)->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(200),
                TextInput::make('phone')->label('الهاتف')->tel()->maxLength(30)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')),
                TextInput::make('email')->label('البريد الإلكتروني')->email()->maxLength(150),
                TextInput::make('tax_number')->label('الرقم الضريبي')->maxLength(50),
                Textarea::make('address')->label('العنوان')->rows(2)->maxLength(500)->columnSpanFull(),
                Textarea::make('notes')->label('ملاحظات')->rows(2)->columnSpanFull(),
            ]),
            Section::make('الائتمان (الآجل)')->columnSpan(1)->schema([
                TextInput::make('credit_limit')->label('الحد الائتماني')->numeric()->minValue(0)
                    ->default(fn () => settings('credit.default_limit', 0))
                    ->helperText('صفر = لا يُسمح بالبيع الآجل')
                    ->disabled(fn () => ! $canCredit()),
                TextInput::make('credit_days')->label('مدة السداد (يوم)')->integer()->minValue(0)->maxValue(365)
                    ->placeholder(fn () => 'الافتراضي: '.settings('credit.default_days', 30))
                    ->disabled(fn () => ! $canCredit()),
                Toggle::make('is_active')->label('نشط')->default(true),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('name')->label('العميل')->weight('bold'),
                TextEntry::make('phone')->label('الهاتف')->placeholder('-'),
                TextEntry::make('email')->label('البريد')->placeholder('-'),
                TextEntry::make('tax_number')->label('الرقم الضريبي')->placeholder('-'),
                TextEntry::make('address')->label('العنوان')->placeholder('-')->columnSpan(2),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('-')->columnSpan(2),
            ]),
            Section::make('الحساب')->columns(4)->visible(fn () => self::canViewDebts())->schema([
                TextEntry::make('balance')->label('الدين الحالي')->formatStateUsing(fn ($state) => money($state))
                    ->badge()->color(fn ($state) => Money::isPositive($state) ? 'danger' : 'success'),
                TextEntry::make('credit_limit')->label('الحد الائتماني')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('available_credit')->label('الرصيد المتاح')->state(fn (Customer $record) => money($record->availableCredit())),
                TextEntry::make('credit_days_label')->label('مدة السداد')->state(fn (Customer $record) => $record->effectiveCreditDays().' يوم'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->searchable()
            ->columns([
                TextColumn::make('name')->label('العميل')->sortable(),
                TextColumn::make('phone')->label('الهاتف')->placeholder('-'),
                TextColumn::make('balance')->label('الدين الحالي')->sortable()->formatStateUsing(fn ($state) => money($state))
                    ->color(fn ($state) => Money::isPositive($state) ? 'danger' : null)->visible(fn () => self::canViewDebts()),
                TextColumn::make('credit_limit')->label('الحد الائتماني')->formatStateUsing(fn ($state) => money($state))
                    ->visible(fn () => self::canViewDebts())->toggleable(),
                TextColumn::make('available')->label('المتاح')->state(fn (Customer $record) => money($record->availableCredit()))
                    ->visible(fn () => self::canViewDebts())->toggleable(),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->filters([
                Filter::make('has_debt')->label('عليه دين')->toggle()->query(fn (Builder $query) => $query->where('balance', '>', 0))
                    ->visible(fn () => self::canViewDebts()),
                TernaryFilter::make('is_active')->label('الحالة'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('delete')->label('حذف')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                    ->visible(fn (Customer $record) => ! $record->trashed() && auth()->user()->can('customers.delete'))
                    ->action(fn (Customer $record) => Domain::run(fn () => app(CustomerService::class)->delete($record, auth()->user()), 'تم حذف العميل')),
                RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone'];
    }

    public static function getRelations(): array
    {
        return [LedgerEntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
