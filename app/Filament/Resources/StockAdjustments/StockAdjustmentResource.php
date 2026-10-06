<?php

namespace App\Filament\Resources\StockAdjustments;

use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class StockAdjustmentResource extends Resource
{
    protected static ?string $model = StockAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'المخزون';

    protected static ?string $modelLabel = 'تسوية مخزون';

    protected static ?string $pluralModelLabel = 'تسويات المخزون';

    protected static ?int $navigationSort = 2;

    public static function productSelect(string $name = 'product_id'): Select
    {
        return Select::make($name)->label('المنتج')->required()->searchable()
            ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->where('track_stock', true)
                ->search($search)->limit(30)->get()
                ->mapWithKeys(fn (Product $p) => [$p->id => "{$p->name} ({$p->sku}) - المتوفر: ".Money::formatQty($p->stock_quantity)]))
            ->getOptionLabelUsing(fn ($value) => Product::withTrashed()->find($value)?->name)
            ->distinct()
            ->disableOptionsWhenSelectedInSiblingRepeaterItems();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('reason')->label('سبب التسوية')->required()->maxLength(255)
                    ->datalist(['تالف', 'منتهي الصلاحية', 'مفقود', 'خطأ إدخال', 'هدية/عينة', 'تصحيح رصيد']),
                Textarea::make('notes')->label('ملاحظات')->rows(1),
            ]),
            Repeater::make('items')->label('الأصناف')->required()->minItems(1)->columns(3)->defaultItems(1)
                ->addActionLabel('إضافة صنف')
                ->schema([
                    self::productSelect()->columnSpan(1),
                    TextInput::make('quantity')->label('الكمية (+ زيادة / - نقص)')->numeric()->required()->notIn(['0', '0.0', '0.00']),
                    TextInput::make('notes')->label('ملاحظة')->maxLength(255),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('رقم التسوية'),
                TextEntry::make('adjusted_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextEntry::make('user.name')->label('المستخدم'),
                TextEntry::make('reason')->label('السبب'),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('-')->columnSpanFull(),
            ]),
            RepeatableEntry::make('items')->label('الأصناف')->table([
                RepeatableEntry\TableColumn::make('المنتج'),
                RepeatableEntry\TableColumn::make('الكمية'),
                RepeatableEntry\TableColumn::make('قبل'),
                RepeatableEntry\TableColumn::make('بعد'),
                RepeatableEntry\TableColumn::make('ملاحظة'),
            ])->schema([
                TextEntry::make('product.name'),
                TextEntry::make('quantity')->formatStateUsing(fn ($state) => Money::formatQty($state))
                    ->color(fn ($state) => Money::isNegative($state) ? 'danger' : 'success'),
                TextEntry::make('quantity_before')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('quantity_after')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('notes')->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('adjusted_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('reason')->label('السبب')->searchable(),
                TextColumn::make('items_count')->counts('items')->label('عدد الأصناف'),
                TextColumn::make('user.name')->label('المستخدم'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockAdjustments::route('/'),
            'create' => CreateStockAdjustment::route('/create'),
            'view' => ViewStockAdjustment::route('/{record}'),
        ];
    }
}
