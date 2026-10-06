<?php

namespace App\Filament\Resources\CashRegisterSessions;

use App\Enums\RegisterSessionStatus;
use App\Filament\Resources\CashRegisterSessions\Pages\ListCashRegisterSessions;
use App\Filament\Resources\CashRegisterSessions\Pages\ViewCashRegisterSession;
use App\Filament\Resources\CashRegisterSessions\RelationManagers\MovementsRelationManager;
use App\Filament\Support\Domain;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Services\CashRegisterService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CashRegisterSessionResource extends Resource
{
    protected static ?string $model = CashRegisterSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'الصندوق والمصروفات';

    protected static ?string $modelLabel = 'جلسة صندوق';

    protected static ?string $pluralModelLabel = 'جلسات الصندوق';

    protected static ?int $navigationSort = 1;

    public static function openAction(): Action
    {
        return Action::make('openRegister')->label('فتح صندوق')->icon(Heroicon::OutlinedLockOpen)->color('success')
            ->visible(fn () => auth()->user()->can('cash_register.open') && ! app(CashRegisterService::class)->currentSession(auth()->user()))
            ->schema([
                Select::make('cash_register_id')->label('الصندوق')->required()
                    ->options(fn () => CashRegister::query()->where('is_active', true)->whereDoesntHave('openSession')->pluck('name', 'id')),
                TextInput::make('opening_balance')->label('الرصيد الافتتاحي (النقد في الدرج)')->numeric()->minValue(0)->required()->default(0),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(fn (array $data) => Domain::run(fn () => app(CashRegisterService::class)->openRegister(
                CashRegister::query()->findOrFail($data['cash_register_id']), $data['opening_balance'], auth()->user(), $data['notes'] ?? null,
            ), 'تم فتح الصندوق'));
    }

    public static function cashInOutAction(string $direction): Action
    {
        $in = $direction === 'in';

        return Action::make($in ? 'cashIn' : 'cashOut')->label($in ? 'إيداع نقدي' : 'سحب نقدي')
            ->icon($in ? Heroicon::OutlinedArrowDownTray : Heroicon::OutlinedArrowUpTray)->color($in ? 'info' : 'warning')
            ->visible(fn (CashRegisterSession $record) => $record->isOpen() && auth()->user()->can('cash_register.cash_in_out'))
            ->schema([
                TextInput::make('amount')->label('المبلغ')->numeric()->minValue(0.01)->required(),
                TextInput::make('reason')->label('السبب')->required()->maxLength(255),
            ])
            ->action(fn (CashRegisterSession $record, array $data) => Domain::run(fn () => $in
                ? app(CashRegisterService::class)->cashIn($record, $data['amount'], $data['reason'], auth()->user())
                : app(CashRegisterService::class)->cashOut($record, $data['amount'], $data['reason'], auth()->user()),
                'تم تسجيل الحركة'));
    }

    public static function closeAction(): Action
    {
        return Action::make('close')->label('إغلاق الصندوق')->icon(Heroicon::OutlinedLockClosed)->color('danger')
            ->visible(fn (CashRegisterSession $record) => $record->isOpen() && auth()->user()->can('cash_register.close')
                && ($record->user_id === auth()->id() || auth()->user()->can('cash_register.view_all')))
            ->modalDescription('عُدّ النقد الموجود فعليًا في الدرج وأدخله. سيُحسب الفرق تلقائيًا.')
            ->schema([
                TextInput::make('actual_cash')->label('النقد الفعلي المعدود')->numeric()->minValue(0)->required(),
                Textarea::make('notes')->label('ملاحظات الإغلاق')->rows(2),
            ])
            ->action(function (CashRegisterSession $record, array $data) {
                $session = Domain::run(fn () => app(CashRegisterService::class)->closeRegister($record, $data['actual_cash'], auth()->user(), $data['notes'] ?? null));
                Notification::make()->title('تم إغلاق الصندوق')
                    ->body('المتوقع: '.money($session->expected_cash).' | الفعلي: '.money($session->actual_cash).' | الفرق: '.money($session->difference))
                    ->color(Money::isZero($session->difference) ? 'success' : 'warning')->persistent()->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        $service = fn () => app(CashRegisterService::class);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('رقم الجلسة')->weight('bold'),
                TextEntry::make('register.name')->label('الصندوق'),
                TextEntry::make('user.name')->label('الكاشير'),
                TextEntry::make('status')->label('الحالة')->badge(),
                TextEntry::make('opened_at')->label('وقت الفتح')->dateTime('Y-m-d H:i'),
                TextEntry::make('closed_at')->label('وقت الإغلاق')->dateTime('Y-m-d H:i')->placeholder('-'),
                TextEntry::make('closer.name')->label('أغلقه')->placeholder('-'),
                TextEntry::make('opening_balance')->label('الرصيد الافتتاحي')->formatStateUsing(fn ($state) => money($state)),
            ]),
            Section::make('ملخص النقد')->columns(4)->schema([
                TextEntry::make('summary_sales')->label('مبيعات نقدية')
                    ->state(fn (CashRegisterSession $record) => money($service()->summary($record)['sale']['in'] ?? 0)),
                TextEntry::make('summary_payments')->label('دفعات العملاء')
                    ->state(fn (CashRegisterSession $record) => money($service()->summary($record)['customer_payment']['in'] ?? 0)),
                TextEntry::make('summary_refunds')->label('مستردات')
                    ->state(fn (CashRegisterSession $record) => money($service()->summary($record)['refund']['out'] ?? 0)),
                TextEntry::make('summary_expenses')->label('مصروفات')
                    ->state(fn (CashRegisterSession $record) => money($service()->summary($record)['expense']['out'] ?? 0)),
                TextEntry::make('summary_in')->label('إيداعات')
                    ->state(fn (CashRegisterSession $record) => money($service()->summary($record)['cash_in']['in'] ?? 0)),
                TextEntry::make('summary_out')->label('سحوبات')
                    ->state(fn (CashRegisterSession $record) => money($service()->summary($record)['cash_out']['out'] ?? 0)),
                TextEntry::make('expected_live')->label('النقد المتوقع')->weight('bold')
                    ->state(fn (CashRegisterSession $record) => money($record->isOpen() ? $service()->expectedCash($record) : $record->expected_cash)),
                TextEntry::make('actual_cash')->label('النقد الفعلي')->formatStateUsing(fn ($state) => money($state))->placeholder('-'),
                TextEntry::make('difference')->label('الفرق')->placeholder('-')->badge()
                    ->formatStateUsing(fn ($state) => money($state))
                    ->color(fn ($state) => $state === null ? 'gray' : (Money::isZero($state) ? 'success' : 'danger')),
                TextEntry::make('closing_notes')->label('ملاحظات الإغلاق')->placeholder('-')->columnSpan(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('register.name')->label('الصندوق'),
                TextColumn::make('user.name')->label('الكاشير'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('opened_at')->label('الفتح')->dateTime('Y-m-d H:i'),
                TextColumn::make('closed_at')->label('الإغلاق')->dateTime('Y-m-d H:i')->placeholder('-'),
                TextColumn::make('opening_balance')->label('الافتتاحي')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('expected_cash')->label('المتوقع')->formatStateUsing(fn ($state) => money($state))->placeholder('-'),
                TextColumn::make('actual_cash')->label('الفعلي')->formatStateUsing(fn ($state) => money($state))->placeholder('-'),
                TextColumn::make('difference')->label('الفرق')->placeholder('-')->formatStateUsing(fn ($state) => money($state))
                    ->color(fn ($state) => $state !== null && ! Money::isZero($state) ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(RegisterSessionStatus::class),
                SelectFilter::make('user_id')->label('الكاشير')->relationship('user', 'name')
                    ->visible(fn () => auth()->user()->can('cash_register.view_all')),
            ])
            ->recordActions([ViewAction::make(), self::closeAction()]);
    }

    /** Cashiers only ever query their own sessions. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['register', 'user'])
            ->when(! auth()->user()?->can('cash_register.view_all'), fn ($q) => $q->where('user_id', auth()->id()));
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashRegisterSessions::route('/'),
            'view' => ViewCashRegisterSession::route('/{record}'),
        ];
    }
}
