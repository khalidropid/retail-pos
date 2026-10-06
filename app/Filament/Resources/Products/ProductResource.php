<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\StockMovementsRelationManager;
use App\Filament\Support\Domain;
use App\Models\Product;
use App\Models\Unit;
use App\Services\InventoryService;
use App\Services\ProductService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'المنتجات';

    protected static ?string $modelLabel = 'منتج';

    protected static ?string $pluralModelLabel = 'المنتجات';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewCost(): bool
    {
        return auth()->user()?->can('products.view_cost') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        $canPrice = fn (string $operation) => $operation === 'create' || auth()->user()?->can('products.update_price');

        return $schema->columns(3)->components([
            Section::make('البيانات الأساسية')->columnSpan(2)->columns(2)->schema([
                TextInput::make('name')->label('اسم المنتج')->required()->maxLength(255)->columnSpanFull(),
                TextInput::make('sku')->label('رمز المنتج SKU')->required()->maxLength(64)
                    ->default(fn () => 'SKU-'.Str::upper(Str::random(8)))
                    ->unique(table: 'products', column: 'sku', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')),
                TextInput::make('barcode')->label('الباركود')->maxLength(64)
                    ->helperText('امسح الباركود بالقارئ مباشرة في هذا الحقل')
                    ->unique(table: 'products', column: 'barcode', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')),
                Select::make('category_id')->label('التصنيف')->relationship('category', 'name')->searchable()->preload(),
                Select::make('brand_id')->label('العلامة التجارية')->relationship('brand', 'name')->searchable()->preload(),
                Select::make('unit_id')->label('الوحدة')->relationship('unit', 'name')->required()->preload()
                    ->default(fn () => Unit::query()->value('id')),
                Textarea::make('description')->label('الوصف')->rows(3)->columnSpanFull(),
            ]),
            Section::make('الصورة والحالة')->columnSpan(1)->schema([
                FileUpload::make('image_path')->label('صورة المنتج')->image()->disk('public')
                    ->directory('products')->maxSize(2048)->imageEditor(),
                Toggle::make('is_active')->label('منتج نشط')->default(true),
                Toggle::make('track_stock')->label('تتبع المخزون')->default(true)
                    ->helperText('عطّله للخدمات والمنتجات غير المخزنية'),
            ]),
            Section::make('الأسعار والضريبة')->columnSpan(2)->columns(3)->schema([
                TextInput::make('cost_price')->label('سعر التكلفة')->numeric()->minValue(0)->required()->default(0)
                    ->visible(fn () => self::canViewCost())->disabled(fn (string $operation) => ! $canPrice($operation)),
                TextInput::make('sale_price')->label('سعر البيع')->numeric()->minValue(0)->required()
                    ->disabled(fn (string $operation) => ! $canPrice($operation)),
                TextInput::make('tax_rate')->label('نسبة الضريبة %')->numeric()->minValue(0)->maxValue(100)->required()
                    ->default(fn () => settings('tax.default_rate', 0))
                    ->disabled(fn (string $operation) => ! $canPrice($operation)),
            ]),
            Section::make('مستويات المخزون')->columnSpan(1)->schema([
                TextInput::make('reorder_level')->label('حد إعادة الطلب')->numeric()->minValue(0)->default(0),
                TextInput::make('min_stock')->label('الحد الأدنى للمخزون')->numeric()->minValue(0)->default(0),
                TextInput::make('opening_stock')->label('رصيد افتتاحي')->numeric()->minValue(0)->default(0)
                    ->visibleOn('create')->dehydrated(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('المنتج')->columnSpan(2)->columns(3)->schema([
                TextEntry::make('name')->label('الاسم')->columnSpanFull()->weight('bold'),
                TextEntry::make('sku')->label('SKU')->copyable(),
                TextEntry::make('barcode')->label('الباركود')->placeholder('-')->copyable(),
                TextEntry::make('unit.name')->label('الوحدة'),
                TextEntry::make('category.name')->label('التصنيف')->placeholder('-'),
                TextEntry::make('brand.name')->label('العلامة التجارية')->placeholder('-'),
                IconEntry::make('is_active')->label('نشط')->boolean(),
                TextEntry::make('sale_price')->label('سعر البيع')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('cost_price')->label('التكلفة')->formatStateUsing(fn ($state) => money($state))
                    ->visible(fn () => self::canViewCost()),
                TextEntry::make('tax_rate')->label('الضريبة')->suffix('%'),
                TextEntry::make('description')->label('الوصف')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('المخزون')->columnSpan(1)->schema([
                ImageEntry::make('image_path')->hiddenLabel()->disk('public')->visible(fn ($record) => filled($record->image_path)),
                TextEntry::make('stock_quantity')->label('الكمية الحالية')
                    ->formatStateUsing(fn ($state, Product $record) => Money::formatQty($state).' '.$record->unit?->short_name)
                    ->badge()->color(fn (Product $record) => $record->isLowStock() ? 'danger' : 'success'),
                TextEntry::make('reorder_level')->label('حد إعادة الطلب')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('min_stock')->label('الحد الأدنى')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('stock_value')->label('قيمة المخزون')->state(fn (Product $record) => money($record->stockValue()))
                    ->visible(fn () => self::canViewCost()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->searchable()
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->circular()->size(36),
                TextColumn::make('name')->label('المنتج')->sortable()->wrap()
                    ->description(fn (Product $record) => $record->sku),
                TextColumn::make('barcode')->label('الباركود')->placeholder('-')->toggleable(),
                TextColumn::make('category.name')->label('التصنيف')->placeholder('-')->toggleable(),
                TextColumn::make('brand.name')->label('العلامة')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sale_price')->label('سعر البيع')->sortable()->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('cost_price')->label('التكلفة')->sortable()->formatStateUsing(fn ($state) => money($state))
                    ->visible(fn () => self::canViewCost())->toggleable(),
                TextColumn::make('stock_quantity')->label('المخزون')->sortable()->badge()
                    ->formatStateUsing(fn ($state, Product $record) => $record->track_stock ? Money::formatQty($state) : 'غير متتبع')
                    ->color(fn (Product $record) => ! $record->track_stock ? 'gray' : ($record->isLowStock() ? 'danger' : 'success')),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('التصنيف')->relationship('category', 'name')->preload(),
                SelectFilter::make('brand_id')->label('العلامة التجارية')->relationship('brand', 'name')->preload(),
                TernaryFilter::make('is_active')->label('الحالة')->trueLabel('النشطة')->falseLabel('غير النشطة'),
                Filter::make('low_stock')->label('مخزون منخفض')->toggle()->query(fn (Builder $q) => $q->lowStock()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('adjust')->label('تسوية')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('warning')
                    ->visible(fn (Product $record) => $record->track_stock && auth()->user()->can('inventory.adjust'))
                    ->schema([
                        TextInput::make('quantity')->label('الكمية (موجبة للزيادة، سالبة للنقص)')->numeric()->required()
                            ->notIn(['0']),
                        TextInput::make('reason')->label('السبب')->required()->maxLength(255),
                    ])
                    ->action(fn (Product $record, array $data) => Domain::run(
                        fn () => app(InventoryService::class)->adjustStock(
                            [['product_id' => $record->id, 'quantity' => $data['quantity']]], $data['reason'], auth()->user()),
                        'تمت تسوية المخزون',
                    )),
                Action::make('delete')->label('حذف')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()->visible(fn (Product $record) => ! $record->trashed() && auth()->user()->can('products.delete'))
                    ->action(fn (Product $record) => Domain::run(
                        fn () => app(ProductService::class)->delete($record, auth()->user()), 'تم حذف المنتج')),
                RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category', 'brand', 'unit'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku', 'barcode'];
    }

    public static function getRelations(): array
    {
        return [StockMovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
