<?php

namespace App\Filament\Resources\Sales\RelationManagers;

use App\Filament\Resources\SaleReturns\SaleReturnResource;
use App\Models\SaleReturn;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    protected static ?string $title = 'المرتجعات';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('returns.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('number')->label('رقم المرتجع'),
                TextColumn::make('returned_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextColumn::make('total')->label('القيمة')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('debt_reduction')->label('من الدين')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('refund_amount')->label('المردود')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('reason')->label('السبب'),
                TextColumn::make('user.name')->label('المستخدم'),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->url(fn (SaleReturn $record) => SaleReturnResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
