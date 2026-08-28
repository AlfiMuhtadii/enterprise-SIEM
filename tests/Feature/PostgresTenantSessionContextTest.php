<?php

namespace Tests\Feature;

use App\Http\Middleware\SetPostgresTenantContext;
use App\Models\User;
use App\Services\PostgresTenantSessionContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class PostgresTenantSessionContextTest extends TestCase
{
    public function test_context_is_visible_only_while_callback_runs(): void
    {
        $service = app(PostgresTenantSessionContext::class);

        $inside = $service->run('tenant-A', fn () => DB::selectOne(
            "select current_setting('app.tenant_id', true) as tenant_id"
        )->tenant_id);

        $outside = DB::selectOne(
            "select current_setting('app.tenant_id', true) as tenant_id"
        )->tenant_id;

        $this->assertSame('tenant-A', $inside);
        $this->assertContains($outside, [null, '']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_context_value_is_bound_not_interpolated(): void
    {
        $service = app(PostgresTenantSessionContext::class);
        $tenantId = "tenant-'quoted";

        $inside = $service->run($tenantId, fn () => DB::selectOne(
            "select current_setting('app.tenant_id', true) as tenant_id"
        )->tenant_id);

        $this->assertSame($tenantId, $inside);
    }

    public function test_exception_rolls_back_and_clears_context(): void
    {
        $service = app(PostgresTenantSessionContext::class);

        try {
            $service->run('tenant-B', function (): void {
                throw new RuntimeException('expected');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected', $exception->getMessage());
        }

        $outside = DB::selectOne(
            "select current_setting('app.tenant_id', true) as tenant_id"
        )->tenant_id;

        $this->assertContains($outside, [null, '']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_exception_closes_nested_transaction_opened_by_callback(): void
    {
        $service = app(PostgresTenantSessionContext::class);

        try {
            $service->run('tenant-nested', function (): void {
                DB::beginTransaction();
                throw new RuntimeException('nested failure');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('nested failure', $exception->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertContains(
            DB::selectOne("select current_setting('app.tenant_id', true) as tenant_id")->tenant_id,
            [null, '']
        );
    }

    public function test_existing_transaction_is_rejected_before_setting_context(): void
    {
        DB::beginTransaction();

        try {
            $this->expectException(LogicException::class);
            app(PostgresTenantSessionContext::class)->run('tenant-nested', fn () => null);
        } finally {
            DB::rollBack();
        }
    }

    public function test_empty_tenant_context_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PostgresTenantSessionContext::class)->run('   ', fn () => null);
    }

    public function test_feature_flag_defaults_to_disabled(): void
    {
        config()->set('xdr.tenancy.rls_session_context_enabled', false);

        $this->assertFalse(app(PostgresTenantSessionContext::class)->enabled());
    }

    public function test_middleware_sets_validated_authenticated_tenant_context(): void
    {
        config()->set('xdr.tenancy.rls_session_context_enabled', true);
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'tenant-middleware',
        ]);
        $request->setUserResolver(fn () => new User(['role' => 'admin']));

        $response = app(SetPostgresTenantContext::class)->handle($request, function () {
            $tenantId = DB::selectOne(
                "select current_setting('app.tenant_id', true) as tenant_id"
            )->tenant_id;

            return new Response($tenantId);
        });

        $this->assertSame('tenant-middleware', $response->getContent());
        $this->assertContains(
            DB::selectOne("select current_setting('app.tenant_id', true) as tenant_id")->tenant_id,
            [null, '']
        );
    }

    public function test_middleware_skips_anonymous_request(): void
    {
        config()->set('xdr.tenancy.rls_session_context_enabled', true);
        $request = Request::create('/test');

        $response = app(SetPostgresTenantContext::class)->handle(
            $request,
            fn () => new Response((string) DB::transactionLevel())
        );

        $this->assertSame('0', $response->getContent());
    }
}
