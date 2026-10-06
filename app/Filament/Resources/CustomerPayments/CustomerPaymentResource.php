<?php

namespace App\Filament\Resources\CustomerPayments;

use App\Enums\RecordStatus;
use App\Filament\Resources\CustomerPayments\Pages\CreateCustomerPayment;
use App\Filament\Resources\CustomerPayments\Pages\ListCustomerPayments;
use App\Filament\Resources\CustomerPayments\Pages\ViewCustomerPayment;
use App\Filament\Support\Domain;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Services\CustomerPaymentService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CustomerPaymentResource extends Resource
{
    protected static ?string $model = CustomerPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'العملاء والذمم';

    protected static ?string $modelLabel = 'دفعة عميل';

    protected static ?string $pluralModelLabel = 'دفعات العملاء';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    /** @return array<int, array<string, mixed>> */
    public static function openInvoiceRows(?int $customerId): array
    {
        if (! $customerId) {
            return [];
        }

        return Sale::query()->where('customer_id', $customerId)->outstanding()
            ->orderByRaw('due_date NULLS FIRST')->orderBy('sale_date')->orderBy('id')->get()
            ->map(fn (Sale $s) => [
                'sale_id' => $s->id,
                'number' => $s->number,
                'due_date' => $s->due_date?->format('Y-m-d') ?? '-',
                'status' => $s->isOverdue() ? 'متأخرة '.$s->daysOverdue().' يوم' : $s->payment_status->getLabel(),
                'balance' => Money::format($s->balance_due),
                'amount' => 0,
            ])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('بيانات الدفعة')->columns(3)->schema([
                Select::make('customer_id')->label('العميل')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()->where('balance', '>', 0)->search($search)->limit(30)->get()
                        ->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->name} - الدين: ".money($c->balance)]))
                    ->getOptionLabelUsing(fn ($value) => Customer::withTrashed()->find($value)?->name)
                    ->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('allocations', self::openInvoiceRows($state ? (int) $state : null))),
                TextInput::make('amount')->label('المبلغ')->numeric()->minValue(0.01)->required()
                    ->helperText(fn (Get $get) => ($c = Customer::query()->find($get('customer_id'))) ? 'إجمالي الدين: '.money($c->balance) : null),
                Select::make('payment_method_id')->label('طريقة الدفع')->required()
                    ->options(fn () => PaymentMethod::query()->active()->pluck('name', 'id'))
                    ->default(fn () => PaymentMethod::query()->where('code', 'cash')->value('id')),
                TextInput::make('reference')->label('المرجع (رقم حوالة/إيصال بنك)')->maxLength(100),
                Textarea::make('notes')->label('ملاحظات')->rows(1)->columnSpan(2),
            ]),
            Section::make('توزيع الدفعة على الفواتير')->schema([
                Toggle::make('auto_allocate')->label('توزيع تلقائي على الفواتير الأقدم استحقاقًا')->default(true)->live(),
                Repeater::make('allocations')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(6)
                    ->hidden(fn (Get $get) => (bool) $get('auto_allocate'))
                    ->schema([
                        Hidden::make('sale_id'),
                        TextInput::make('number')->label('الفاتورة')->disabled()->dehydrated(false),
                        TextInput::make('due_date')->label('الاستحقاق')->disabled()->dehydrated(false),
                        TextInput::make('status')->label('الحالة')->disabled()->dehydrated(false),
                        TextInput::make('balance')->label('المتبقي')->disabled()->dehydrated(false),
                        TextInput::make('amount')->label('المبلغ المخصص')->numeric()->minValue(0)->default(0)->columnSpan(2),
                    ]),
            ]),
        ]);
    }

    public static function printAction(): Action
    {
        return Action::make('printReceipt')->label('طباعة إيصال السداد')->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->url(fn (CustomerPayment $record) => route('print.payment-receipt', $record))->openUrlInNewTab();
    }

    public static function reverseAction(): Action
    {
        return Action::make('reverse')->label('عكس الدفعة')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->visible(fn (CustomerPayment $record) => ! $record->isVoided() && $record->customer_id && auth()->user()->can('customer_payments.reverse'))
            ->modalDescription('لن تُحذف الدفعة. سيُعاد المبلغ إلى دين العميل والفواتير المرتبطة، وإن كانت نقدية فسيُصرف المبلغ من الصندوق، وتُسجّل العملية في سجل العمليات.')
            ->schema([TextInput::make('reason')->label('سبب العكس')->required()->maxLength(255)])
            ->action(fn (CustomerPayment $record, array $data) => Domain::run(
                fn () => app(CustomerPaymentService::class)->reversePayment($record, $data['reason'], auth()->user()), 'تم عكس الدفعة'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('رقم السند')->weight('bold'),
                TextEntry::make('payment_date')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextEntry::make('customer.name')->label('العميل')->placeholder('عميل نقدي'),
                TextEntry::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => money($state))->weight('bold'),
                TextEntry::make('paymentMethod.name')->label('طريقة الدفع'),
                TextEntry::make('source')->label('المصدر')->formatStateUsing(fn ($state) => $state === 'pos' ? 'عند البيع' : 'سداد دين'),
                TextEntry::make('balance_before')->label('الرصيد قبل الدفع')->formatStateUsing(fn ($state) => money($state))->placeholder('-'),
                TextEntry::make('balance_after')->label('الرصيد بعد الدفع')->formatStateUsing(fn ($state) => money($state))->placeholder('-'),
                TextEntry::make('user.name')->label('الموظف'),
                TextEntry::make('reference')->label('المرجع')->placeholder('-'),
                TextEntry::make('status')->label('الحالة')->badge(),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('-'),
                TextEntry::make('void_reason')->label('سبب العكس')->visible(fn (CustomerPayment $record) => $record->isVoided()),
                TextEntry::make('voider.name')->label('عكسها')->visible(fn (CustomerPayment $record) => $record->isVoided()),
                TextEntry::make('voided_at')->label('تاريخ العكس')->dateTime('Y-m-d H:i')->visible(fn (CustomerPayment $record) => $record->isVoided()),
            ]),
            RepeatableEntry::make('allocations')->label('الفواتير المسددة')->table([
                RepeatableEntry\TableColumn::make('الفاتورة'),
                RepeatableEntry\TableColumn::make('إجمالي الفاتورة'),
                RepeatableEntry\TableColumn::make('المبلغ المسدد'),
                RepeatableEntry\TableColumn::make('المتبقي حاليًا'),
            ])->schema([
                TextEntry::make('sale.number'),
                TextEntry::make('sale.total')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('amount')->formatStateUsing(fn ($state) => money($state)),
                TextEntry::make('sale.balance_due')->formatStateUsing(fn ($state) => money($state)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('رقم الدفعة')->searchable(),
                TextColumn::make('payment_date')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('customer.name')->label('العميل')->placeholder('نقدي')->searchable(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => money($state))->sortable(),
                TextColumn::make('paymentMethod.name')->label('طريقة الدفع'),
                TextColumn::make('invoices')->label('الفواتير المسددة')->wrap()
                    ->state(fn (CustomerPayment $record) => $record->allocations->map(fn ($a) => $a->sale->number)->implode('، ')),
                TextColumn::make('user.name')->label('المستخدم'),
                TextColumn::make('reference')->label('المرجع')->placeholder('-')->toggleable(),
                TextColumn::make('notes')->label('ملاحظات')->limit(30)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label('المصدر')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'pos' ? 'عند البيع' : 'سداد دين')->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->filters([
                SelectFilter::make('source')->label('المصدر')->options(['account' => 'سداد دين', 'pos' => 'عند البيع'])->default('account'),
                SelectFilter::make('status')->label('الحالة')->options(RecordStatus::class),
                SelectFilter::make('customer_id')->label('العميل')->relationship('customer', 'name')->searchable(),
                SelectFilter::make('payment_method_id')->label('طريقة الدفع')->relationship('paymentMethod', 'name'),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('من تاريخ'),
                    DatePicker::make('until')->label('إلى تاريخ'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '<=', $d))),
            ])
            ->recordActions([ViewAction::make(), self::printAction(), self::reverseAction()]);
    }

    /** Without customers.view_debts users only see payments they recorded. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'paymentMethod', 'user', 'allocations.sale'])
            ->when(! auth()->user()?->can('customers.view_debts'), fn ($q) => $q->where('user_id', auth()->id()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerPayments::route('/'),
            'create' => CreateCustomerPayment::route('/create'),
            'view' => ViewCustomerPayment::route('/{record}'),
        ];
    }
}
