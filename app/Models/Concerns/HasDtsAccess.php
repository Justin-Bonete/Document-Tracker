<?php
namespace App\Models\Concerns;

use App\Models\Office;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/** Add to App\Models\User:  use HasApiTokens, HasDtsAccess; */
trait HasDtsAccess
{
    private ?array $permissionCache = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('office_id');
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class, 'office_user');
    }

    public function permissionCodes(): array
    {
        return $this->permissionCache ??= DB::table('permissions')
            ->join('role_permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('user_roles', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $this->id)
            ->distinct()
            ->pluck('permissions.code')
            ->all();
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
