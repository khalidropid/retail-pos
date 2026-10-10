<?php

namespace App\Filament\Resources\Sales;

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\RelationManagers\PaymentAllocationsRelationManager;
use App\Filament\Resources\Sales\RelationManagers\ReturnsRelationManager;
use App\Filament\Support\Domain;
use App\Filament\Support\PaymentFields;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'المبيعات';

    protected static ?string $modelLabel = 'فاتورة بيع';

    protected static ?string $pluralModelLabel = 'فواتير البيع';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function printAction(): Action
    {
        return Action::make('print')->label('طباعة الإيصال')->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->url(fn (Sale $record) => route('print.sale-receipt', $record))->openUrlInNewTab();
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('إلغاء الفاتورة')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (Sale $record) => ! $record->isCancelled() && Money::isZero($record->returned_amount) && auth()->user()->can('sales.cancel'))
            ->modalDescription('سيتم إرجاع الأصناف إلى المخزون، وإرجاع المبلغ المدفوع عند البيع، وإلغاء الدين. لا يمكن التراجع.')
            ->schema([TextInput::make('reason')->label('سبب الإلغاء')->required()->maxLength(255)])
            ->action(fn (Sale $record, array $data) => Domain::run(
                fn () => app(SaleService::class)->cancelSale($record, $data['reason'], auth()->user()), 'تم إلغاء الفاتورة'));
    }

    public static function returnAction(): Action
    {
        return Action::make('return')->label('مرتجع')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
            ->modalWidth('4xl')
            ->visible(fn (Sale $record) => ! $record->isCancelled()
                && $record->items->contains(fn (SaleItem $i) => Money::isPositive($i->returnableQuantity()))
                && auth()->user()->can('returns.create'))
            ->fillForm(fn (Sale $record) => [
                'lines' => $record->items->filter(fn (SaleItem $i) => Money::isPositive($i->returnableQuantity()))
                    ->map(fn (SaleItem $i) => [
                        'sale_item_id' => $i->id,
                        'product' => $i->product_name,
                        'sold' => Money::formatQty($i->quantity),
                        'returnable' => Money::formatQty($i->returnableQuantity()),
                        'quantity' => 0,
                    ])->values()->all(),
                'refund_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'),
            ])
            ->modalDescription(fn (Sale $record) => Money::isPositive($record->balance_due)
                ? 'قيمة المرتجع تُخصم أولًا من المتبقي على الفاتورة ('.money($record->balance_due).')، وما يزيد يُرد للعميل.'
                : 'الفاتورة مدفوعة بالكامل، ستُرد قيمة المرتجع للعميل.')
            ->schema([
                Repeater::make('lines')->label('الأصناف')->addable(false)->deletable(false)->reorderable(false)->columns(4)
                    ->schema([
                        Hidden::make('sale_item_id'),
                        TextInput::make('product')->label('المنتج')->disabled()->dehydrated(false),
                        TextInput::make('sold')->label('المباع')->disabled()->dehydrated(false),
                        TextInput::make('returnable')->label('المتاح للإرجاع')->disabled()->dehydrated(false),
                        TextInput::make('quantity')->label('الكمية المرتجعة')->numeric()->minValue(0)->required(),
                    ]),
                ...PaymentFields::make(method: 'refund_method_id', accountType: 'refund_account_type_id', reference: 'refund_reference',
                    methodLabel: 'طريقة رد المبلغ (إن وُجد مبلغ يُرد)', required: false),
                TextInput::make('reason')->label('سبب الإرجاع')->required()->maxLength(255),
            ])
            ->action(fn (Sale $record, array $data) => Domain::run(fn () => app(ReturnService::class)->returnSale(
                $record, array_values($data['lines'] ?? []), $data['reason'], auth()->user(),
                $data['refund_method_id'] ? (int) $data['refund_method_id'] : null,
                $data['refund_account_type_id'] ?? null, $data['refund_reference'] ?? null,
            ), 'تم تسجيل المرتجع'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('رقم الفاتورة')->weight('bold'),
                TextEntry::make('sale_date')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextEntry::make('customer.name')->label('العميل')->placeholder('عميل نقدي'),
                TextEntry::make('user.name')->label('الكاشير'),
                TextEntry::make('sale_type')->label('نوع البيع')->badge(),
                TextEntry::make('status')->label('حالة الفاتورة')->badge(),
                TextEntry::make('payment_state')->label('حالة الدفع')->badge()
                    ->state(fn (Sale $record) => $record->displayPaymentStatus())
                    ->visible(fn (Sale $record) => ! $record->isCancelled()),
                TextEntry::make('paymentMethod.name')->label('طريقة الدفع')->placeholder('-'),
                TextEntry::make('due_date')->label('تاريخ الاستحقاق')->date('Y-m-d')->placeholder('-'),
                TextEntry::make('days_overdue')->label('أيام التأخير')->state(fn (Sale $record) => $record->daysOverdue())
                    ->visible(fn (Sale $record) => $record->isOverdue())->color('danger'),
                TextEntry::make('cancel_reason')->label('سبب الإلغاء')->visible(fn (Sale $record) => $record->isCancelled()),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('-'),
            ]),
            Section::make('المبالغ')->columns(4)->schema([
                TextEntry::make('subtotal')->label('المجموع')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('discount_amount')->label('الخصم')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('tax_amount')->label('الضريبة')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('total')->label('الإجمالي')->formatStateUsing(fn ($state) => money($state))->weight('bold'),
                TextEntry::make('paid_amount')->label('المدفوع')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('returned_amount')->label('المرتجع')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('refunded_amount')->label('المسترد للعميل')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('balance_due')->label('المتبقي')->formatStateUsing(fn ($state) => money($state))
                    ->badge()->color(fn ($state) => Money::isPositive($state) ? 'danger' : 'success'),
                TextEntry::make('gross_profit')->label('ربح الفاتورة')
                    ->visible(fn () => auth()->user()->can('reports.profit'))
                    ->state(fn (Sale $record) => money($record->items->reduce(fn ($c, $i) => Money::add($c,
                        Money::sub(Money::sub($i->line_total, $i->tax_amount), $i->costTotal())), '0'))),
            ]),
            RepeatableEntry::make('items')->label('الأصناف')->table([
                RepeatableEntry\TableColumn::make('المنتج'),
                RepeatableEntry\TableColumn::make('الكمية'),
                RepeatableEntry\TableColumn::make('المرتجع'),
                RepeatableEntry\TableColumn::make('السعر'),
                RepeatableEntry\TableColumn::make('الخصم'),
                RepeatableEntry\TableColumn::make('الضريبة'),
                RepeatableEntry\TableColumn::make('الإجمالي'),
            ])->schema([
                TextEntry::make('product_name'),
                TextEntry::make('quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('returned_quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('unit_price')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('discount_amount')->formatStateUsing(fn ($state) => money($state)),
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
                TextColumn::make('number')->label('رقم الفاتورة')->searchable(),
                TextColumn::make('sale_date')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('customer.name')->label('العميل')->placeholder('نقدي')->searchable(),
                TextColumn::make('user.name')->label('الكاشير')->toggleable(),
                TextColumn::make('sale_type')->label('النوع')->badge(),
                TextColumn::make('total')->label('الإجمالي')->formatStateUsing(fn ($state) => money($state))->sortable(),
                TextColumn::make('paid_amount')->label('المدفوع')->formatStateUsing(fn ($state) => money($state))->toggleable(),
                TextColumn::make('balance_due')->label('المتبقي')->formatStateUsing(fn ($state) => money($state))->sortable()
                    ->color(fn ($state) => Money::isPositive($state) ? 'danger' : null),
                TextColumn::make('due_date')->label('الاستحقاق')->date('Y-m-d')->placeholder('-')->sortable()->toggleable(),
                TextColumn::make('payment_state')->label('الدفع')->badge()->state(fn (Sale $record) => $record->displayPaymentStatus()),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->filters([
                SelectFilter::make('sale_type')->label('نوع البيع')->options(SaleType::class),
                SelectFilter::make('status')->label('حالة الفاتورة')->options(SaleStatus::class),
                SelectFilter::make('payment_status')->label('حالة الدفع')->options(PaymentStatus::class)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'overdue' => $query->overdue(),
                        default => $query->notCancelled()->where('payment_status', $data['value']),
                    }),
                SelectFilter::make('customer_id')->label('العميل')->relationship('customer', 'name')->searchable(),
                SelectFilter::make('user_id')->label('الكاشير')->relationship('user', 'name')
                    ->visible(fn () => auth()->user()->can('sales.view_all')),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('من تاريخ'),
                    DatePicker::make('until')->label('إلى تاريخ'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('sale_date', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('sale_date', '<=', $d))),
            ])
            ->recordActions([ViewAction::make(), self::printAction(), self::returnAction()]);
    }

    /** Cashiers without sales.view_all only list their own invoices. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'user'])
            ->when(! auth()->user()?->can('sales.view_all'), fn ($q) => $q->where('user_id', auth()->id()));
    }

    public static function getRelations(): array
    {
        return [PaymentAllocationsRelationManager::class, ReturnsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
