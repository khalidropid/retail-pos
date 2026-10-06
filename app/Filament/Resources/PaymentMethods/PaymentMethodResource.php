<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Enums\PaymentMethodType;
use App\Filament\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Models\PaymentMethod;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?string $modelLabel = 'طريقة دفع';

    protected static ?string $pluralModelLabel = 'طرق الدفع';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('الاسم')->required()->maxLength(100),
            TextInput::make('code')->label('الرمز البرمجي')->required()->alphaDash()->maxLength(30)
                ->unique(ignoreRecord: true)->disabledOn('edit'),
            Select::make('type')->label('النوع')->options(PaymentMethodType::class)->required()->disabledOn('edit')
                ->helperText('الطرق النقدية تُسجَّل في الصندوق'),
            TextInput::make('sort_order')->label('الترتيب')->integer()->default(0),
            Toggle::make('is_active')->label('فعّالة')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('الاسم'),
                TextColumn::make('code')->label('الرمز'),
                TextColumn::make('type')->label('النوع')->badge(),
                IconColumn::make('is_active')->label('فعّالة')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentMethods::route('/')];
    }
}
