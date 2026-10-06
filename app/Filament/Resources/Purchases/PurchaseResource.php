<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Filament\Resources\Purchases\RelationManagers\ReceiptsRelationManager;
use App\Filament\Resources\Purchases\RelationManagers\ReturnsRelationManager;
use App\Filament\Support\Domain;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Services\PurchaseService;
use App\Services\SupplierPaymentService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'المشتريات';

    protected static ?string $modelLabel = 'أمر شراء';

    protected static ?string $pluralModelLabel = 'المشتريات';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                Select::make('supplier_id')->label('المورد')->required()->searchable()->preload()
                    ->relationship('supplier', 'name', fn ($query) => $query->where('is_active', true)),
                DatePicker::make('order_date')->label('تاريخ الطلب')->required()->default(today()),
                DatePicker::make('expected_date')->label('تاريخ الوصول المتوقع'),
                TextInput::make('supplier_invoice_number')->label('رقم فاتورة المورد')->maxLength(100),
                Textarea::make('notes')->label('ملاحظات')->rows(1)->columnSpanFull(),
            ]),
            Repeater::make('items')->label('الأصناف')->required()->minItems(1)->columns(5)
                ->addActionLabel('إضافة صنف')->defaultItems(1)
                ->schema([
                    Select::make('product_id')->label('المنتج')->required()->searchable()->columnSpan(2)
                        ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->search($search)->limit(30)
                            ->get()->mapWithKeys(fn (Product $p) => [$p->id => "{$p->name} ({$p->sku})"]))
                        ->getOptionLabelUsing(fn ($value) => Product::withTrashed()->find($value)?->name)
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            if ($product = Product::query()->find($state)) {
                                $set('unit_cost', $product->cost_price);
                                $set('tax_rate', $product->tax_rate);
                            }
                        })
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('quantity')->label('الكمية')->numeric()->required()->minValue(0.001)->default(1)->live(onBlur: true),
                    TextInput::make('unit_cost')->label('تكلفة الوحدة')->numeric()->required()->minValue(0)->live(onBlur: true),
                    TextInput::make('tax_rate')->label('الضريبة %')->numeric()->minValue(0)->maxValue(100)->default(0),
                ]),
            TextEntry::make('estimated_total')->label('الإجمالي التقديري (قبل الضريبة)')
                ->state(fn (Get $get) => money(collect($get('items') ?? [])->reduce(
                    fn ($carry, $i) => Money::add($carry, Money::mul($i['quantity'] ?? 0, $i['unit_cost'] ?? 0)), '0'))),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('رقم الأمر')->weight('bold'),
                TextEntry::make('supplier.name')->label('المورد'),
                TextEntry::make('status')->label('الحالة')->badge(),
                TextEntry::make('order_date')->label('تاريخ الطلب')->date('Y-m-d'),
                TextEntry::make('total')->label('إجمالي الطلب')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('received_total')->label('قيمة المستلم')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('returned_total')->label('المرتجع')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('paid_amount')->label('المدفوع')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('amount_due')->label('المستحق للمورد')->state(fn (Purchase $record) => money($record->amountDue()))
                    ->badge()->color(fn (Purchase $record) => Money::isPositive($record->amountDue()) ? 'danger' : 'success'),
                TextEntry::make('supplier_invoice_number')->label('فاتورة المورد')->placeholder('-'),
                TextEntry::make('user.name')->label('أنشأه'),
                TextEntry::make('cancel_reason')->label('سبب الإلغاء')->visible(fn (Purchase $record) => filled($record->cancel_reason)),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('-')->columnSpanFull(),
            ]),
            RepeatableEntry::make('items')->label('الأصناف')->table([
                RepeatableEntry\TableColumn::make('المنتج'),
                RepeatableEntry\TableColumn::make('المطلوب'),
                RepeatableEntry\TableColumn::make('المستلم'),
                RepeatableEntry\TableColumn::make('المرتجع'),
                RepeatableEntry\TableColumn::make('تكلفة الوحدة'),
                RepeatableEntry\TableColumn::make('الإجمالي'),
            ])->schema([
                TextEntry::make('product.name'),
                TextEntry::make('quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('received_quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('returned_quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('unit_cost')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('line_total')->formatStateUsing(fn ($state) => money($state)),
            ]),
        ]);
    }

    public static function orderAction(): Action
    {
        return Action::make('order')->label('تأكيد الطلب')->icon(Heroicon::OutlinedPaperAirplane)->color('info')
            ->requiresConfirmation()
            ->visible(fn (Purchase $record) => $record->status === PurchaseStatus::Draft && auth()->user()->can('purchases.create'))
            ->action(fn (Purchase $record) => Domain::run(fn () => app(PurchaseService::class)->markOrdered($record, auth()->user()), 'تم تأكيد الطلب'));
    }

    public static function receiveAction(): Action
    {
        return Action::make('receive')->label('استلام البضاعة')->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
            ->modalWidth('5xl')
            ->visible(fn (Purchase $record) => $record->canReceive() && auth()->user()->can('purchases.receive'))
            ->fillForm(fn (Purchase $record) => [
                'lines' => $record->items()->with('product')->get()
                    ->filter(fn (PurchaseItem $i) => Money::isPositive($i->remainingToReceive()))
                    ->map(fn (PurchaseItem $i) => [
                        'purchase_item_id' => $i->id,
                        'product' => $i->product->name,
                        'remaining' => Money::formatQty($i->remainingToReceive()),
                        'quantity' => Money::formatQty($i->remainingToReceive()),
                        'unit_cost' => $i->unit_cost,
                    ])->values()->all(),
            ])
            ->schema([
                Repeater::make('lines')->label('الأصناف')->addable(false)->deletable(false)->reorderable(false)->columns(4)
                    ->schema([
                        Hidden::make('purchase_item_id'),
                        TextInput::make('product')->label('المنتج')->disabled()->dehydrated(false),
                        TextInput::make('remaining')->label('المتبقي')->disabled()->dehydrated(false),
                        TextInput::make('quantity')->label('الكمية المستلمة')->numeric()->minValue(0)->required(),
                        TextInput::make('unit_cost')->label('التكلفة الفعلية')->numeric()->minValue(0)->required(),
                    ]),
                Textarea::make('notes')->label('ملاحظات الاستلام')->rows(1),
                Section::make('دفعة فورية (اختياري)')->description('اتركها فارغة إذا كان الشراء آجلًا للمورد')->columns(3)->collapsed()
                    ->visible(fn () => auth()->user()->can('supplier_payments.create'))
                    ->schema([
                        TextInput::make('payment_amount')->label('المبلغ المدفوع الآن')->numeric()->minValue(0),
                        Select::make('payment_method_id')->label('طريقة الدفع')
                            ->options(fn () => PaymentMethod::query()->active()->pluck('name', 'id'))
                            ->requiredWith('payment_amount'),
                        TextInput::make('payment_reference')->label('المرجع')->maxLength(100),
                    ]),
            ])
            ->action(fn (Purchase $record, array $data) => Domain::run(fn () => app(PurchaseService::class)->receive(
                $record, array_values($data['lines'] ?? []), auth()->user(), $data['notes'] ?? null, [
                    'amount' => $data['payment_amount'] ?? null,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'reference' => $data['payment_reference'] ?? null,
                ]), 'تم الاستلام وتحديث المخزون'));
    }

    public static function returnAction(): Action
    {
        return Action::make('return')->label('مرتجع للمورد')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
            ->modalWidth('4xl')
            ->visible(fn (Purchase $record) => Money::isPositive($record->received_total) && auth()->user()->can('purchases.return'))
            ->fillForm(fn (Purchase $record) => [
                'lines' => $record->items()->with('product')->get()
                    ->filter(fn (PurchaseItem $i) => Money::isPositive($i->returnableQuantity()))
                    ->map(fn (PurchaseItem $i) => [
                        'purchase_item_id' => $i->id,
                        'product' => $i->product->name,
                        'returnable' => Money::formatQty($i->returnableQuantity()),
                        'quantity' => 0,
                    ])->values()->all(),
            ])
            ->schema([
                Repeater::make('lines')->label('الأصناف')->addable(false)->deletable(false)->reorderable(false)->columns(3)
                    ->schema([
                        Hidden::make('purchase_item_id'),
                        TextInput::make('product')->label('المنتج')->disabled()->dehydrated(false),
                        TextInput::make('returnable')->label('المتاح للإرجاع')->disabled()->dehydrated(false),
                        TextInput::make('quantity')->label('الكمية المرتجعة')->numeric()->minValue(0)->required(),
                    ]),
                TextInput::make('reason')->label('سبب الإرجاع')->required()->maxLength(255),
            ])
            ->action(fn (Purchase $record, array $data) => Domain::run(fn () => app(PurchaseService::class)->returnToSupplier(
                $record, array_values($data['lines'] ?? []), $data['reason'], auth()->user()), 'تم تسجيل المرتجع'));
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('إلغاء الأمر')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (Purchase $record) => $record->isEditable() && auth()->user()->can('purchases.cancel'))
            ->schema([TextInput::make('reason')->label('سبب الإلغاء')->required()->maxLength(255)])
            ->action(fn (Purchase $record, array $data) => Domain::run(fn () => app(PurchaseService::class)->cancel(
                $record, $data['reason'], auth()->user()), 'تم إلغاء أمر الشراء'));
    }

    public static function payAction(): Action
    {
        return Action::make('pay')->label('دفع للمورد')->icon(Heroicon::OutlinedBanknotes)->color('success')
            ->visible(fn (Purchase $record) => Money::isPositive($record->amountDue()) && auth()->user()->can('supplier_payments.create'))
            ->schema(fn (Purchase $record) => [
                TextInput::make('amount')->label('المبلغ')->numeric()->required()->minValue(0.01)
                    ->maxValue((float) $record->amountDue())->default($record->amountDue()),
                Select::make('payment_method_id')->label('طريقة الدفع')->required()
                    ->options(PaymentMethod::query()->active()->pluck('name', 'id')),
                TextInput::make('reference')->label('المرجع')->maxLength(100),
            ])
            ->action(fn (Purchase $record, array $data) => Domain::run(fn () => app(SupplierPaymentService::class)->pay(
                $record->supplier, (string) $data['amount'], (int) $data['payment_method_id'], auth()->user(),
                $record->id, $data['reference'] ?? null), 'تم تسجيل الدفعة'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('supplier.name')->label('المورد')->searchable(),
                TextColumn::make('order_date')->label('التاريخ')->date('Y-m-d')->sortable(),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('total')->label('الإجمالي')->formatStateUsing(fn ($state) => money($state))->sortable(),
                TextColumn::make('received_total')->label('المستلم')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('amount_due')->label('المستحق')->state(fn (Purchase $record) => money($record->amountDue())),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(PurchaseStatus::class),
                SelectFilter::make('supplier_id')->label('المورد')->relationship('supplier', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), self::receiveAction()]);
    }

    public static function getRelations(): array
    {
        return [ReceiptsRelationManager::class, ReturnsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'view' => ViewPurchase::route('/{record}'),
            'edit' => EditPurchase::route('/{record}/edit'),
        ];
    }
}
