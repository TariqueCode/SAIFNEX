<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class NetworkFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['key' => 'network.read', 'name' => 'View network'],
            ['key' => 'network.manage', 'name' => 'Manage network'],
            ['key' => 'device.read', 'name' => 'View devices'],
            ['key' => 'device.manage', 'name' => 'Manage devices'],
            ['key' => 'policy.read', 'name' => 'View policies'],
            ['key' => 'policy.manage', 'name' => 'Manage policies'],
            ['key' => 'configuration.read', 'name' => 'View configuration history'],
            ['key' => 'configuration.manage', 'name' => 'Generate and stage configurations'],
            ['key' => 'configuration.publish', 'name' => 'Publish signed configurations'],
            ['key' => 'deployment.read', 'name' => 'View deployments'],
            ['key' => 'deployment.manage', 'name' => 'Create deployment requests'],
            ['key' => 'monitoring.read', 'name' => 'View monitoring'],
            ['key' => 'security.read', 'name' => 'View security'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['key' => $permission['key']],
                $permission
            );
        }

        $roles = [
            'viewer' => ['name' => 'Viewer', 'permissions' => ['network.read', 'device.read', 'policy.read', 'configuration.read', 'deployment.read', 'monitoring.read', 'security.read']],
            'network_admin' => ['name' => 'Network Admin', 'permissions' => ['network.read', 'network.manage', 'device.read', 'device.manage', 'policy.read', 'policy.manage', 'configuration.read', 'configuration.manage', 'configuration.publish', 'deployment.read', 'deployment.manage', 'monitoring.read', 'security.read']],
        ];

        foreach ($roles as $key => $definition) {
            $role = Role::updateOrCreate(
                ['key' => $key],
                ['name' => $definition['name'], 'is_system' => true]
            );

            $role->permissions()->sync(
                Permission::whereIn('key', $definition['permissions'])->pluck('id')
            );
        }
    }
}
