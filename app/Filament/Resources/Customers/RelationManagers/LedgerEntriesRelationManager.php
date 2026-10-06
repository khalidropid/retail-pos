<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'حركات الحساب';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('customers.view_debts') ?? false;
    }

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
                TextColumn::make('debit')->label('مدين')->formatStateUsing(fn ($state) => $state > 0 ? money($state, false) : ''),
                TextColumn::make('credit')->label('دائن')->formatStateUsing(fn ($state) => $state > 0 ? money($state, false) : ''),
                TextColumn::make('balance_after')->label('الرصيد')->formatStateUsing(fn ($state) => money($state))->weight('bold'),
                TextColumn::make('user.name')->label('المستخدم'),
            ]);
    }
}
