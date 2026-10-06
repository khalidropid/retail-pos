<?php

namespace App\Filament\Resources\CashRegisterSessions\RelationManagers;

use App\Enums\CashMovementType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'الحركات النقدية';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                TextColumn::make('type')->label('النوع')->badge(),
                TextColumn::make('direction')->label('الاتجاه')->badge(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('notes')->label('البيان')->wrap()->placeholder('-'),
                TextColumn::make('user.name')->label('المستخدم'),
            ])
            ->filters([SelectFilter::make('type')->label('النوع')->options(CashMovementType::class)]);
    }
}
