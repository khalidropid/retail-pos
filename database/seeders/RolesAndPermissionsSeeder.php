<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = collect(config('pos_permissions.groups'))->flatMap(fn ($p) => array_keys($p))->values();

        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('pos_permissions.roles') as $roleName => $definition) {
            $role = Role::findOrCreate($roleName, 'web');
            $permissions = $definition['permissions'];

            if ($permissions === '*') {
                $names = $all->all();
            } elseif (isset($permissions['except'])) {
                $names = $all->diff($permissions['except'])->values()->all();
            } else {
                $names = $permissions;
            }

            $role->syncPermissions($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
