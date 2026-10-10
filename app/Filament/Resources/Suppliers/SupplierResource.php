<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\PurchasesRelationManager;
use App\Filament\Support\Domain;
use App\Filament\Support\PaymentFields;
use App\Models\Supplier;
use App\Services\SupplierPaymentService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'المشتريات';

    protected static ?string $modelLabel = 'مورد';

    protected static ?string $pluralModelLabel = 'الموردون';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('اسم المورد')->required()->maxLength(200),
            TextInput::make('phone')->label('الهاتف')->tel()->maxLength(30),
            TextInput::make('email')->label('البريد الإلكتروني')->email()->maxLength(150),
            TextInput::make('tax_number')->label('الرقم الضريبي')->maxLength(50),
            Textarea::make('address')->label('العنوان')->rows(2)->maxLength(500),
            Textarea::make('notes')->label('ملاحظات')->rows(2),
            Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('name')->label('المورد')->weight('bold'),
                TextEntry::make('phone')->label('الهاتف')->placeholder('-'),
                TextEntry::make('email')->label('البريد')->placeholder('-'),
                TextEntry::make('tax_number')->label('الرقم الضريبي')->placeholder('-'),
                TextEntry::make('address')->label('العنوان')->placeholder('-')->columnSpan(2),
                TextEntry::make('balance')->label('الرصيد المستحق للمورد')->formatStateUsing(fn ($state) => money($state))
                    ->badge()->color(fn ($state) => Money::isPositive($state) ? 'danger' : 'success'),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('-'),
            ]),
        ]);
    }

    public static function payAction(): Action
    {
        return Action::make('pay')->label('تسجيل دفعة للمورد')->icon(Heroicon::OutlinedBanknotes)->color('success')
            ->visible(fn (Supplier $record) => auth()->user()->can('supplier_payments.create') && Money::isPositive($record->balance))
            ->schema(fn (Supplier $record) => [
                TextInput::make('amount')->label('المبلغ')->numeric()->required()->minValue(0.01)
                    ->maxValue((float) $record->balance)->default($record->balance)
                    ->helperText('الرصيد المستحق: '.money($record->balance)),
                Select::make('purchase_id')->label('ربط بأمر شراء (اختياري)')
                    ->options(fn () => $record->purchases()->where('received_total', '>', 0)->latest('id')->limit(50)->pluck('number', 'id')),
                ...PaymentFields::make(),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(fn (Supplier $record, array $data) => Domain::run(fn () => app(SupplierPaymentService::class)->pay(
                $record, (string) $data['amount'], (int) $data['payment_method_id'], auth()->user(),
                $data['purchase_id'] ? (int) $data['purchase_id'] : null, $data['reference'] ?? null, $data['notes'] ?? null,
                $data['payment_account_type_id'] ?? null,
            ), 'تم تسجيل الدفعة'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->searchable()
            ->columns([
                TextColumn::make('name')->label('المورد')->sortable(),
                TextColumn::make('phone')->label('الهاتف')->placeholder('-'),
                TextColumn::make('purchases_count')->counts('purchases')->label('أوامر الشراء'),
                TextColumn::make('balance')->label('المستحق له')->sortable()->formatStateUsing(fn ($state) => money($state))
                    ->color(fn ($state) => Money::isPositive($state) ? 'danger' : null),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->filters([
                Filter::make('has_balance')->label('عليه رصيد مستحق')->toggle()->query(fn (Builder $q) => $q->where('balance', '>', 0)),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), self::payAction(), DeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [LedgerEntriesRelationManager::class, PurchasesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'view' => ViewSupplier::route('/{record}'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
