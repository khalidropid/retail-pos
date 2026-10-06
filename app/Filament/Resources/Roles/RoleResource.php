<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?string $modelLabel = 'دور';

    protected static ?string $pluralModelLabel = 'الأدوار والصلاحيات';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (config('pos_permissions.groups') as $group => $permissions) {
            $sections[] = Section::make($group)->collapsible()->schema([
                CheckboxList::make('permission_names.'.md5($group))
                    ->hiddenLabel()
                    ->options($permissions)
                    ->columns(3)
                    ->bulkToggleable(),
            ]);
        }

        return $schema->components([
            Section::make('الدور')->schema([
                TextInput::make('name')->label('الاسم البرمجي')->required()->maxLength(100)
                    ->alphaDash()->unique(ignoreRecord: true)
                    ->helperText('بالإنجليزية، مثال: senior_cashier'),
            ]),
            ...$sections,
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('الدور'),
                TextColumn::make('name')->label('الاسم البرمجي')->searchable(),
                TextColumn::make('permissions_count')->counts('permissions')->label('عدد الصلاحيات'),
                TextColumn::make('users_count')->counts('users')->label('عدد المستخدمين'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * Split a flat permission list into the per-group form state.
     *
     * @param  array<int, string>  $names
     * @return array<string, array<int, string>>
     */
    public static function toFormState(array $names): array
    {
        $state = [];
        foreach (config('pos_permissions.groups') as $group => $permissions) {
            $state[md5($group)] = array_values(array_intersect(array_keys($permissions), $names));
        }

        return $state;
    }

    /** @return array<int, string> */
    public static function fromFormState(?array $state): array
    {
        $valid = collect(config('pos_permissions.groups'))->flatMap(fn ($p) => array_keys($p))->all();

        return array_values(array_intersect(collect($state ?? [])->flatten()->all(), $valid));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
