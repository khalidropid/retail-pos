<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات المستخدم')->columns(2)->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(255),
                TextInput::make('email')->label('البريد الإلكتروني')->email()->required()
                    ->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('phone')->label('الهاتف')->tel()->maxLength(30),
                TextInput::make('password')->label('كلمة المرور')->password()->revealable()
                    ->rule(Password::min(8))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية' : null),
                Select::make('roles')->label('الدور')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $record) => $record->label)
                    ->multiple()->preload()->required(),
                Toggle::make('is_active')->label('فعّال')->default(true)
                    ->disabled(fn (?User $record) => $record?->is(auth()->user())),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('email')->label('البريد')->searchable(),
                TextColumn::make('phone')->label('الهاتف')->toggleable(),
                TextColumn::make('roles.name')->label('الدور')->badge()
                    ->formatStateUsing(fn (string $state) => config("pos_permissions.roles.{$state}.label", $state)),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
                TextColumn::make('last_login_at')->label('آخر دخول')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('roles')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
