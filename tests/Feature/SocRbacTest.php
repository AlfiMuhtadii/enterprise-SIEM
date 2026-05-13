<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_soc_dashboard(): void
    {
        $this->get('/soc')->assertRedirect('/login');
    }

    public function test_viewer_can_view_dashboard_but_cannot_manage_rules(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/soc')->assertOk();
        $this->actingAs($viewer)->get('/soc/rules')->assertForbidden();

        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $viewer->email,
            'action' => 'rbac.denied',
            'target_type' => 'permission',
            'target_id' => 'rules.manage',
        ]);
    }

    public function test_admin_can_access_rule_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/soc/rules')->assertOk();
    }
}
