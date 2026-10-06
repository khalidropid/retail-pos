<?php

namespace App\Filament\Resources\Expenses;

use App\Enums\RecordStatus;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Support\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\ExpenseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'الصندوق والمصروفات';

    protected static ?string $modelLabel = 'مصروف';

    protected static ?string $pluralModelLabel = 'المصروفات';

    protected static ?int $navigationSort = 2;

    public static function createAction(): Action
    {
        return Action::make('createExpense')->label('تسجيل مصروف')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('expenses.create'))
            ->schema([
                Select::make('expense_category_id')->label('التصنيف')->required()
                    ->options(fn () => ExpenseCategory::query()->where('is_active', true)->pluck('name', 'id')),
                TextInput::make('amount')->label('المبلغ')->numeric()->minValue(0.01)->required(),
                Select::make('payment_method_id')->label('طريقة الدفع')->required()
                    ->options(fn () => PaymentMethod::query()->active()->pluck('name', 'id'))
                    ->default(fn () => PaymentMethod::query()->where('code', 'cash')->value('id'))
                    ->helperText('الدفع النقدي يُخصم من صندوقك المفتوح'),
                DatePicker::make('expense_date')->label('التاريخ')->required()->default(today())->maxDate(today()),
                TextInput::make('reference')->label('المرجع / رقم الوصل')->maxLength(100),
                Textarea::make('description')->label('الوصف')->required()->maxLength(500)->rows(2),
            ])
            ->action(fn (array $data) => Domain::run(fn () => app(ExpenseService::class)->create($data, auth()->user()), 'تم تسجيل المصروف'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('expense_date')->label('التاريخ')->date('Y-m-d')->sortable(),
                TextColumn::make('category.name')->label('التصنيف'),
                TextColumn::make('description')->label('الوصف')->limit(50)->searchable(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => money($state))->sortable(),
                TextColumn::make('paymentMethod.name')->label('الدفع'),
                TextColumn::make('session.number')->label('الصندوق')->placeholder('-')->toggleable(),
                TextColumn::make('user.name')->label('المستخدم'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('void_reason')->label('سبب الإلغاء')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('expense_category_id')->label('التصنيف')->relationship('category', 'name'),
                SelectFilter::make('status')->label('الحالة')->options(RecordStatus::class),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('من تاريخ'),
                    DatePicker::make('until')->label('إلى تاريخ'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '<=', $d))),
            ])
            ->recordActions([
                Action::make('void')->label('إلغاء')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (Expense $record) => ! $record->isVoided() && auth()->user()->can('expenses.void'))
                    ->schema([TextInput::make('reason')->label('سبب الإلغاء')->required()->maxLength(255)])
                    ->action(fn (Expense $record, array $data) => Domain::run(
                        fn () => app(ExpenseService::class)->void($record, $data['reason'], auth()->user()), 'تم إلغاء المصروف')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'paymentMethod', 'user', 'session']);
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenses::route('/')];
    }
}
