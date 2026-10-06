<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'كشف حساب المورد';

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
                TextColumn::make('entry_date')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextColumn::make('type')->label('النوع')->badge(),
                TextColumn::make('description')->label('البيان')->wrap(),
                TextColumn::make('credit')->label('له (دائن)')->formatStateUsing(fn ($state) => $state > 0 ? money($state, false) : ''),
                TextColumn::make('debit')->label('عليه (مدين)')->formatStateUsing(fn ($state) => $state > 0 ? money($state, false) : ''),
                TextColumn::make('balance_after')->label('الرصيد')->formatStateUsing(fn ($state) => money($state))->weight('bold'),
                TextColumn::make('user.name')->label('المستخدم'),
            ]);
    }
}
