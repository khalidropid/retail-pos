<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?string $modelLabel = 'سجل عملية';

    protected static ?string $pluralModelLabel = 'سجل العمليات';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit_logs.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i:s'),
                TextEntry::make('user.name')->label('المستخدم')->placeholder('النظام'),
                TextEntry::make('event_label')->label('العملية')->badge(),
                TextEntry::make('auditable_type')->label('نوع السجل')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '-'),
                TextEntry::make('auditable_id')->label('رقم السجل')->placeholder('-'),
                TextEntry::make('ip_address')->label('عنوان IP')->placeholder('-'),
                TextEntry::make('description')->label('الوصف')->placeholder('-')->columnSpanFull(),
                TextEntry::make('reason')->label('السبب')->placeholder('-')->columnSpanFull(),
            ]),
            Grid::make(2)->schema([
                KeyValueEntry::make('old_values')->label('قبل')->keyLabel('الحقل')->valueLabel('القيمة')
                    ->state(fn (AuditLog $record) => self::flatten($record->old_values)),
                KeyValueEntry::make('new_values')->label('بعد')->keyLabel('الحقل')->valueLabel('القيمة')
                    ->state(fn (AuditLog $record) => self::flatten($record->new_values)),
            ]),
        ]);
    }

    /** @return array<string, string> */
    private static function flatten(?array $values): array
    {
        return collect($values ?? [])->map(fn ($v) => is_scalar($v) || $v === null
            ? (string) ($v === true ? 'نعم' : ($v === false ? 'لا' : $v))
            : json_encode($v, JSON_UNESCAPED_UNICODE))->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('user.name')->label('المستخدم')->placeholder('النظام')->searchable(),
                TextColumn::make('event')->label('العملية')->badge()
                    ->formatStateUsing(fn (string $state) => AuditLog::eventLabels()[$state] ?? $state),
                TextColumn::make('auditable_type')->label('السجل')
                    ->formatStateUsing(fn (?string $state, AuditLog $record) => $state ? class_basename($state).' #'.$record->auditable_id : '-'),
                TextColumn::make('description')->label('الوصف')->limit(50)->placeholder('-'),
                TextColumn::make('reason')->label('السبب')->limit(40)->placeholder('-')->toggleable(),
                TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')->label('العملية')->options(AuditLog::eventLabels())->multiple(),
                SelectFilter::make('user_id')->label('المستخدم')->relationship('user', 'name'),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('من تاريخ'),
                    DatePicker::make('until')->label('إلى تاريخ'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
