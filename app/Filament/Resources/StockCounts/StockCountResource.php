<?php

namespace App\Filament\Resources\StockCounts;

use App\Enums\StockCountStatus;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Support\Domain;
use App\Models\StockCount;
use App\Services\InventoryService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
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

class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'المخزون';

    protected static ?string $modelLabel = 'جرد';

    protected static ?string $pluralModelLabel = 'الجرد';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Textarea::make('notes')->label('ملاحظات')->rows(2),
            Repeater::make('items')->label('الأصناف المعدودة')->required()->minItems(1)->columns(2)
                ->addActionLabel('إضافة صنف')
                ->schema([
                    StockAdjustmentResource::productSelect(),
                    TextInput::make('counted_quantity')->label('الكمية المعدودة فعليًا')->numeric()->minValue(0)->required(),
                ]),
        ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')->label('اعتماد الجرد')->icon(Heroicon::OutlinedCheckBadge)->color('success')
            ->requiresConfirmation()
            ->modalDescription('سيتم احتساب الفروقات مقابل الرصيد الحالي وتسجيل حركات مخزون بها. لا يمكن التراجع.')
            ->visible(fn (StockCount $record) => $record->status === StockCountStatus::Draft
                && auth()->user()->can('inventory.count') && auth()->user()->can('inventory.adjust'))
            ->action(fn (StockCount $record) => Domain::run(
                fn () => app(InventoryService::class)->approveCount($record, auth()->user()), 'تم اعتماد الجرد'));
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('إلغاء')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->requiresConfirmation()
            ->visible(fn (StockCount $record) => $record->status === StockCountStatus::Draft && auth()->user()->can('inventory.count'))
            ->action(fn (StockCount $record) => Domain::run(
                fn () => app(InventoryService::class)->cancelCount($record, auth()->user()), 'تم إلغاء الجرد'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('رقم الجرد'),
                TextEntry::make('status')->label('الحالة')->badge(),
                TextEntry::make('user.name')->label('أنشأه'),
                TextEntry::make('approver.name')->label('اعتمده')->placeholder('-'),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('-')->columnSpanFull(),
            ]),
            RepeatableEntry::make('items')->label('الأصناف')->table([
                RepeatableEntry\TableColumn::make('المنتج'),
                RepeatableEntry\TableColumn::make('الرصيد الدفتري'),
                RepeatableEntry\TableColumn::make('المعدود'),
                RepeatableEntry\TableColumn::make('الفرق'),
            ])->schema([
                TextEntry::make('product.name'),
                TextEntry::make('expected_quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('counted_quantity')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextEntry::make('difference')->formatStateUsing(fn ($state) => Money::formatQty($state))
                    ->color(fn ($state) => Money::isNegative($state) ? 'danger' : (Money::isZero($state) ? 'gray' : 'success')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('items_count')->counts('items')->label('عدد الأصناف'),
                TextColumn::make('user.name')->label('أنشأه'),
                TextColumn::make('approved_at')->label('تاريخ الاعتماد')->dateTime('Y-m-d H:i')->placeholder('-'),
            ])
            ->recordActions([ViewAction::make(), self::approveAction(), self::cancelAction()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCounts::route('/'),
            'create' => CreateStockCount::route('/create'),
            'view' => ViewStockCount::route('/{record}'),
        ];
    }
}
