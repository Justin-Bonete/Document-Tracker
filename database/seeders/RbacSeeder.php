<?php
namespace Database\Seeders;

use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'documents.view', 'documents.view_all', 'documents.view_confidential',
            'documents.create', 'documents.update', 'documents.void',
            'files.upload', 'files.download',
            'routes.create', 'routes.acknowledge', 'routes.complete',
            'decisions.create',
            'reports.view', 'audit.view',
            'users.manage', 'settings.manage',
        ];
        foreach ($permissions as $code) {
            Permission::firstOrCreate(['code' => $code], ['name' => ucfirst(str_replace(['.', '_'], ' ', $code))]);
        }

        $roles = [
            'system_admin'    => ['System Administrator', ['users.manage', 'settings.manage', 'reports.view']],
            'records_officer' => ['Records Officer / Encoder', ['documents.view', 'documents.view_confidential', 'documents.create', 'documents.update', 'documents.void', 'files.upload', 'files.download', 'routes.create']],
            'receiving_clerk' => ['Receiving Clerk', ['documents.view', 'routes.acknowledge']],
            'office_head'     => ['Office Head / Approver', ['documents.view', 'documents.view_confidential', 'files.download', 'routes.create', 'routes.acknowledge', 'routes.complete', 'decisions.create', 'reports.view']],
            'staff'           => ['Staff / Assignee', ['documents.view', 'files.download', 'routes.create', 'routes.acknowledge', 'routes.complete']],
            'auditor'         => ['Auditor / Compliance', ['documents.view', 'documents.view_all', 'documents.view_confidential', 'reports.view', 'audit.view']],
            'viewer'          => ['Read-only Viewer', ['documents.view']],
        ];
        foreach ($roles as $code => [$name, $perms]) {
            $role = Role::firstOrCreate(['code' => $code], ['name' => $name]);
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('code', $perms)->pluck('id'));
        }

        $this->call(DocumentTypeSeeder::class);

        $office = Office::firstOrCreate(['office_code' => 'MAIN'], ['name' => 'Main Office']);

        $pw = env('DTS_ADMIN_PASSWORD');
        if (! $pw) {
            $this->command?->warn('DTS_ADMIN_PASSWORD not set, skipping users.');
            return;
        }

        $this->makeUser(env('DTS_ADMIN_EMAIL', 'admin@example.com'), 'System Administrator', $pw, 'system_admin', $office);

        // Local development only: a records officer so you can test document registration.
        if (app()->environment('local')) {
            $this->makeUser('records@example.com', 'Records Officer (dev)', $pw, 'records_officer', $office);
        }
    }

    private function makeUser(string $email, string $name, string $pw, string $roleCode, Office $office): void
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($pw), 'status' => 'active']
        );
        $user->offices()->syncWithoutDetaching([$office->id]);
        DB::table('user_roles')->insertOrIgnore([
            'user_id' => $user->id, 'role_id' => Role::where('code', $roleCode)->value('id'), 'office_id' => null,
        ]);
    }
}
