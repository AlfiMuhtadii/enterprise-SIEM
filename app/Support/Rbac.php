<?php

namespace App\Support;

use App\Models\User;

class Rbac
{
    public static function role(?User $user): string
    {
        $role = $user?->role ?: 'viewer';

        $validRoles = ['admin', 'analyst', 'viewer', 'scenario_operator', 'detection_engineer'];
        return in_array($role, $validRoles, true) ? $role : 'viewer';
    }

    public static function can(?User $user, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        $role = self::role($user);
        $permissions = config("soc.permissions.{$role}", []);

        return in_array($permission, $permissions, true);
    }
}
