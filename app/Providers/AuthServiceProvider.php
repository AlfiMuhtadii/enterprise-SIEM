<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [];

    public function boot(): void
    {
        // Forward every `soc:<permission>` Gate check to Rbac::can() so that
        // Blade @can('soc:scenario.run') and similar directives work correctly.
        Gate::before(function (?User $user, string $ability): ?bool {
            if (!str_starts_with($ability, 'soc:')) {
                return null; // let other gates handle non-soc abilities
            }
            if (!$user) {
                return false;
            }
            $permission = substr($ability, 4);
            return Rbac::can($user, $permission) ? true : false;
        });
    }
}
