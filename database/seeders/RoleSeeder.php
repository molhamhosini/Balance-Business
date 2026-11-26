<?php

namespace Database\Seeders;

use App\Support\Perm;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (array_keys(Perm::LABELS) as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (Perm::ROLES as $name => $def) {
            Role::findOrCreate($name, 'web')->syncPermissions($def['perms']);
        }
    }
}
