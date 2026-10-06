<?php

namespace App\Filament\Resources\SupplierPayments;

use App\Enums\RecordStatus;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Filament\Support\Domain;
use App\Models\SupplierPayment;
use App\Services\SupplierPaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'المشتريات';

    protected static ?string $modelLabel = 'دفعة مورد';

    protected static ?string $pluralModelLabel = 'دفعات الموردين';

    protected static ?int $navigationSort = 3;

    public static function reverseAction(): Action
    {
        return Action::make('reverse')->label('عكس الدفعة')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->visible(fn (SupplierPayment $record) => ! $record->isVoided() && auth()->user()->can('supplier_payments.reverse'))
            ->modalDescription('سيتم إرجاع المبلغ إلى رصيد المورد وتسجيل قيد عكسي. لا يمكن حذف الدفعات.')
            ->schema([TextInput::make('reason')->label('سبب العكس')->required()->maxLength(255)])
            ->action(fn (SupplierPayment $record, array $data) => Domain::run(
                fn () => app(SupplierPaymentService::class)->reverse($record, $data['reason'], auth()->user()), 'تم عكس الدفعة'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('payment_date')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('supplier.name')->label('المورد')->searchable(),
                TextColumn::make('purchase.number')->label('أمر الشراء')->placeholder('-'),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('paymentMethod.name')->label('طريقة الدفع'),
                TextColumn::make('reference')->label('المرجع')->placeholder('-'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('user.name')->label('المستخدم'),
                TextColumn::make('void_reason')->label('سبب العكس')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(RecordStatus::class),
                SelectFilter::make('supplier_id')->label('المورد')->relationship('supplier', 'name')->searchable(),
            ])
            ->recordActions([self::reverseAction()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'purchase', 'paymentMethod', 'user']);
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierPayments::route('/')];
    }
}
