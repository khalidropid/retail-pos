<?php

namespace App\Filament\Resources\CashRegisters;

use App\Filament\Resources\CashRegisters\Pages\ManageCashRegisters;
use App\Models\CashRegister;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CashRegisterResource extends Resource
{
    protected static ?string $model = CashRegister::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'الصندوق والمصروفات';

    protected static ?string $modelLabel = 'صندوق';

    protected static ?string $pluralModelLabel = 'الصناديق';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('اسم الصندوق')->required()->maxLength(100),
            TextInput::make('location')->label('الموقع')->maxLength(150),
            Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الصندوق'),
                TextColumn::make('location')->label('الموقع')->placeholder('-'),
                TextColumn::make('openSession.user.name')->label('مفتوح حاليًا بواسطة')->placeholder('مغلق'),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCashRegisters::route('/')];
    }
}
