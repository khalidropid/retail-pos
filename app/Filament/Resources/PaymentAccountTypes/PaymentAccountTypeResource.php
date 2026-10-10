<?php

namespace App\Filament\Resources\PaymentAccountTypes;

use App\Filament\Resources\PaymentAccountTypes\Pages\ManagePaymentAccountTypes;
use App\Models\PaymentAccountType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentAccountTypeResource extends Resource
{
    protected static ?string $model = PaymentAccountType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?string $modelLabel = 'نوع حساب';

    protected static ?string $pluralModelLabel = 'أنواع الحسابات (المحافظ)';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('الاسم المعروض')->required()->maxLength(100),
            TextInput::make('code')->label('الرمز البرمجي')->required()->alphaDash()->maxLength(40)
                ->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('provider')->label('الجهة المقدمة')->maxLength(150),
            TextInput::make('sort_order')->label('الترتيب')->integer()->default(0),
            Toggle::make('is_active')->label('مفعّل')->default(true),
            Toggle::make('available_at_pos')->label('متاح في نقطة البيع')->default(true),
            Toggle::make('requires_reference')->label('يتطلب رقم مرجع العملية')->default(true),
            Textarea::make('notes')->label('ملاحظات')->rows(2)->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('provider')->label('الجهة')->placeholder('-'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
                IconColumn::make('available_at_pos')->label('نقطة البيع')->boolean(),
                IconColumn::make('requires_reference')->label('يتطلب مرجعًا')->boolean(),
                TextColumn::make('notes')->label('ملاحظات')->limit(40)->placeholder('-')->wrap(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentAccountTypes::route('/')];
    }
}
