<?php

namespace App\Filament\Resources\SaleReturns;

use App\Filament\Resources\SaleReturns\Pages\ListSaleReturns;
use App\Filament\Resources\SaleReturns\Pages\ViewSaleReturn;
use App\Models\SaleReturn;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SaleReturnResource extends Resource
{
    protected static ?string $model = SaleReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'المبيعات';

    protected static ?string $modelLabel = 'مرتجع مبيعات';

    protected static ?string $pluralModelLabel = 'مرتجعات المبيعات';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('رقم المرتجع')->weight('bold'),
                TextEntry::make('returned_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextEntry::make('sale.number')->label('الفاتورة الأصلية'),
                TextEntry::make('customer.name')->label('العميل')->placeholder('عميل نقدي'),
                TextEntry::make('total')->label('قيمة المرتجع')->formatStateUsing(fn ($state) => money($state))->weight('bold'),
                TextEntry::make('debt_reduction')->label('خُصم من الدين')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('refund_amount')->label('رُدّ للعميل')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('refundMethod.name')->label('طريقة الرد')->placeholder('-'),
                TextEntry::make('user.name')->label('المستخدم'),
                TextEntry::make('reason')->label('السبب')->columnSpan(3),
            ]),
            RepeatableEntry::make('items')->label('الأصناف المرتجعة')->table([
                RepeatableEntry\TableColumn::make('المنتج'),
                RepeatableEntry\TableColumn::make('الكمية'),
                RepeatableEntry\TableColumn::make('سعر الوحدة'),
                RepeatableEntry\TableColumn::make('الضريبة'),
                RepeatableEntry\TableColumn::make('القيمة'),
            ])->schema([
                TextEntry::make('saleItem.product_name'),
                TextEntry::make('quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('unit_price')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('tax_amount')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('line_total')->formatStateUsing(fn ($state) => money($state)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('returned_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('sale.number')->label('الفاتورة')->searchable(),
                TextColumn::make('customer.name')->label('العميل')->placeholder('نقدي'),
                TextColumn::make('total')->label('القيمة')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('debt_reduction')->label('من الدين')->formatStateUsing(fn ($state) => money($state))->toggleable(),
                TextColumn::make('refund_amount')->label('المردود')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('reason')->label('السبب')->limit(40),
                TextColumn::make('user.name')->label('المستخدم'),
            ])
            ->filters([
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('من تاريخ'),
                    DatePicker::make('until')->label('إلى تاريخ'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('returned_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('returned_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['sale', 'customer', 'user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSaleReturns::route('/'),
            'view' => ViewSaleReturn::route('/{record}'),
        ];
    }
}
