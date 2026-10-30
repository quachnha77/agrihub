<?php

namespace Tests\Feature\Tenancy;

use App\Services\Monitoring\SystemHealth;
use App\Support\Tenancy\HostTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Mỗi doanh nghiệp một subdomain: chọn đúng doanh nghiệp ở trang đăng nhập, từ chối đăng nhập/phiên sai địa chỉ. */
class SubdomainTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const PASSWORD = 'Mat-khau-cu-2026';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function enable(): void
    {
        config(['tenancy.base_domain' => 'agrihub.test', 'tenancy.platform_labels' => ['admin']]);
    }

    private function url(string $host, string $path): string
    {
        return "http://{$host}/api/v1{$path}";
    }

    private function login(string $host, string $login, string $password = self::PASSWORD)
    {
        return $this->freshBrowser()->spa()->postJson($this->url($host, '/auth/login'), ['login' => $login, 'password' => $password]);
    }

    // --- tắt mặc định -------------------------------------------------------------------------

    public function test_the_feature_is_off_until_a_base_domain_is_configured(): void
    {
        [, $owner] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'chu@nongphat.vn', 'password' => self::PASSWORD]);

        $this->getJson('/api/v1/tenant-context')->assertOk()->assertJsonPath('data.kind', 'unrestricted')->assertJsonPath('data.tenant', null);
        $this->login('localhost', 'chu@nongphat.vn')->assertOk()->assertJsonPath('data.id', $owner->uuid);
        $this->login('bat-ky-dau.example.com', 'chu@nongphat.vn')->assertOk();
    }

    // --- xác định địa chỉ ---------------------------------------------------------------------------

    public function test_hosts_are_classified_as_platform_tenant_or_unknown(): void
    {
        $this->enable();
        $this->makeTenant(['name' => 'Cửa hàng Nông Phát', 'slug' => 'nong-phat']);

        $tenant = $this->getJson($this->url('nong-phat.agrihub.test', '/tenant-context'))->assertOk();
        $this->assertSame('tenant', $tenant->json('data.kind'));
        $this->assertSame(['name' => 'Cửa hàng Nông Phát', 'slug' => 'nong-phat'], $tenant->json('data.tenant'));

        foreach (['admin.agrihub.test', 'agrihub.test', 'ADMIN.Agrihub.test'] as $host) {
            $this->getJson($this->url($host, '/tenant-context'))->assertOk()->assertJsonPath('data.kind', 'platform')->assertJsonPath('data.tenant', null);
        }
    }

    public function test_unknown_subdomains_and_foreign_hosts_are_refused_but_health_checks_still_work(): void
    {
        $this->enable();
        $this->makeTenant(['slug' => 'nong-phat']);

        foreach (['khong-co.agrihub.test', 'a.b.agrihub.test', '-x.agrihub.test', 'agrihub.test.evil.com', 'evil.com', '10.0.0.5'] as $host) {
            $this->getJson($this->url($host, '/tenant-context'))->assertNotFound()->assertJsonPath('code', 'tenant_not_found');
            $this->postJson($this->url($host, '/auth/login'), ['login' => 'a@b.vn', 'password' => 'x'])->assertNotFound();
        }

        // Cân bằng tải kiểm tra bằng IP hoặc tên nội bộ.
        $this->getJson($this->url('10.0.0.5', '/health'))->assertOk();
        $this->getJson($this->url('may-noi-bo', '/health/ready'))->assertOk();
    }

    public function test_a_tenant_created_after_a_miss_works_immediately_and_a_deleted_one_stops(): void
    {
        $this->enable();

        $this->getJson($this->url('moi.agrihub.test', '/tenant-context'))->assertNotFound();
        [$tenant] = $this->makeTenant(['slug' => 'moi']);
        $this->getJson($this->url('moi.agrihub.test', '/tenant-context'))->assertOk()->assertJsonPath('data.kind', 'tenant');

        $this->platform(fn () => $tenant->delete());
        Cache::flush(); // bộ nhớ đệm 30 giây
        $this->getJson($this->url('moi.agrihub.test', '/tenant-context'))->assertNotFound();
    }

    // --- đăng nhập ---------------------------------------------------------------------------------

    public function test_each_user_logs_in_only_at_their_own_address(): void
    {
        $this->enable();
        [, $ownerA] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'a@a.vn', 'phone' => '0911111111', 'password' => self::PASSWORD]);
        [, $ownerB] = $this->makeTenant(['slug' => 'vat-tu-xanh'], ['email' => 'b@b.vn', 'password' => self::PASSWORD]);
        $admin = $this->makePlatformAdmin(['email' => 'root@agrihub.test', 'password' => self::PASSWORD]);

        $this->login('nong-phat.agrihub.test', 'a@a.vn')->assertOk()->assertJsonPath('data.id', $ownerA->uuid);
        $this->login('nong-phat.agrihub.test', '0911 111 111')->assertOk(); // số điện thoại cũng vậy
        $this->login('vat-tu-xanh.agrihub.test', 'b@b.vn')->assertOk()->assertJsonPath('data.id', $ownerB->uuid);
        $this->login('admin.agrihub.test', 'root@agrihub.test')->assertOk()->assertJsonPath('data.id', $admin->uuid);
        $this->login('agrihub.test', 'root@agrihub.test')->assertOk();

        // Sai địa chỉ (mật khẩu đúng): báo rõ và chỉ đúng đường dẫn của chính họ.
        $this->login('vat-tu-xanh.agrihub.test', 'a@a.vn')->assertForbidden()
            ->assertJsonPath('code', 'wrong_host')->assertJsonPath('login_url', 'http://nong-phat.agrihub.test');
        $this->login('admin.agrihub.test', 'a@a.vn')->assertForbidden()->assertJsonPath('login_url', 'http://nong-phat.agrihub.test');
        $this->login('nong-phat.agrihub.test', 'root@agrihub.test')->assertForbidden()->assertJsonPath('login_url', 'http://admin.agrihub.test');
    }

    public function test_the_wrong_address_is_never_confirmed_to_someone_without_the_password(): void
    {
        $this->enable();
        $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'a@a.vn', 'password' => self::PASSWORD]);
        $this->makeTenant(['slug' => 'vat-tu-xanh']);

        $wrongPasswordRightPlace = $this->login('nong-phat.agrihub.test', 'a@a.vn', 'sai-mat-khau');
        $wrongPasswordWrongPlace = $this->login('vat-tu-xanh.agrihub.test', 'a@a.vn', 'sai-mat-khau');
        $unknownAccount = $this->login('vat-tu-xanh.agrihub.test', 'khong-co@a.vn', 'sai-mat-khau');

        foreach ([$wrongPasswordRightPlace, $wrongPasswordWrongPlace, $unknownAccount] as $response) {
            $response->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
            $this->assertArrayNotHasKey('login_url', $response->json());
        }

        $this->assertSame($wrongPasswordRightPlace->json('detail'), $unknownAccount->json('detail'));
    }

    public function test_a_wrong_address_login_is_audited(): void
    {
        $this->enable();
        $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'a@a.vn', 'password' => self::PASSWORD]);
        $this->makeTenant(['slug' => 'vat-tu-xanh']);

        $this->login('vat-tu-xanh.agrihub.test', 'a@a.vn')->assertForbidden();

        $this->assertSame(1, $this->auditCount(['module' => 'auth', 'action' => 'login_failed', 'reason' => 'wrong_host']));
    }

    // --- phiên -----------------------------------------------------------------------------------------

    public function test_a_session_is_refused_at_any_address_but_its_own(): void
    {
        $this->enable();
        [, $ownerA] = $this->makeTenant(['slug' => 'nong-phat'], ['password' => self::PASSWORD]);
        $this->makeTenant(['slug' => 'vat-tu-xanh']);
        $admin = $this->makePlatformAdmin(['password' => self::PASSWORD]);

        $this->freshBrowser()->spa()->actingAs($ownerA)->getJson($this->url('nong-phat.agrihub.test', '/auth/me'))->assertOk();
        // Cookie bị dùng chung nhầm giữa các subdomain cũng không giúp được gì.
        $this->freshBrowser()->spa()->actingAs($ownerA)->getJson($this->url('vat-tu-xanh.agrihub.test', '/auth/me'))->assertForbidden()->assertJsonPath('code', 'wrong_host');
        $this->freshBrowser()->spa()->actingAs($ownerA)->getJson($this->url('admin.agrihub.test', '/auth/me'))->assertForbidden()->assertJsonPath('code', 'wrong_host');

        $this->freshBrowser()->spa()->actingAs($admin)->getJson($this->url('admin.agrihub.test', '/auth/me'))->assertOk();
        $this->freshBrowser()->spa()->actingAs($admin)->getJson($this->url('nong-phat.agrihub.test', '/auth/me'))->assertForbidden()->assertJsonPath('code', 'wrong_host');
    }

    // --- đường dẫn của doanh nghiệp ------------------------------------------------------------------------

    public function test_the_tenant_list_shows_each_tenants_own_address(): void
    {
        $this->enable();
        config(['tenancy.url_port' => 5173]);
        $this->makeTenant(['slug' => 'nong-phat']);
        $admin = $this->makePlatformAdmin(['password' => self::PASSWORD]);

        $this->freshBrowser()->spa()->actingAs($admin)->getJson($this->url('admin.agrihub.test', '/platform/tenants'))->assertOk()
            ->assertJsonPath('data.0.url', 'http://nong-phat.agrihub.test:5173');

        config(['tenancy.base_domain' => null, 'tenancy.url_port' => null]);
        $this->freshBrowser()->spa()->actingAs($admin)->getJson('/api/v1/platform/tenants')->assertOk()->assertJsonPath('data.0.url', null);
    }

    public function test_subdomain_labels_used_by_other_services_cannot_be_taken_by_a_tenant(): void
    {
        $this->enable();
        $admin = $this->makePlatformAdmin(['password' => self::PASSWORD]);

        foreach (['admin', 'www', 'api', 'mail', 'smtp', 'staging', 'grafana'] as $slug) {
            $this->freshBrowser()->spa()->actingAs($admin)->postJson($this->url('admin.agrihub.test', '/platform/tenants'), [
                'tenant' => ['name' => 'Thử', 'slug' => $slug], 'owner' => ['name' => 'Chủ', 'email' => "chu-{$slug}@example.com"],
            ])->assertStatus(422)->assertJsonValidationErrors(['tenant.slug']);
        }
    }

    // --- cookie phiên (Sanctum) -----------------------------------------------------------------------------------

    public function test_every_subdomain_of_the_base_domain_counts_as_our_web_app_for_session_cookies(): void
    {
        $this->assertSame([], HostTenant::statefulDomains(null, null));
        $this->assertSame(['agrihub.vn', '*.agrihub.vn'], HostTenant::statefulDomains('agrihub.vn', null));
        $this->assertSame(['localhost', '*.localhost', 'localhost:5173', '*.localhost:5173'], HostTenant::statefulDomains('localhost', '5173'));

        config(['sanctum.stateful' => HostTenant::statefulDomains('agrihub.vn', null)]);
        $from = fn (string $referer) => EnsureFrontendRequestsAreStateful::fromFrontend(Request::create('/api/v1/auth/login', 'POST', server: ['HTTP_REFERER' => $referer]));

        $this->assertTrue($from('https://nong-phat.agrihub.vn/login'));
        $this->assertTrue($from('https://admin.agrihub.vn/'));
        $this->assertFalse($from('https://agrihub.vn.evil.com/'));
        $this->assertFalse($from('https://evilagrihub.vn/'));
    }

    // --- giám sát ----------------------------------------------------------------------------------------------------

    public function test_a_shared_session_cookie_domain_is_flagged_critical_when_subdomains_are_on(): void
    {
        $health = fn () => collect(app(SystemHealth::class)->evaluate()['components'])->firstWhere('key', 'config');

        config(['session.domain' => '.agrihub.test']);
        $this->assertSame('ok', $health()['status'], 'tính năng tắt thì không áp dụng');

        $this->enable();
        $this->assertSame('critical', $health()['status']);
        $this->assertStringContainsString('SESSION_DOMAIN', $health()['summary']);

        config(['session.domain' => null]);
        $this->assertSame('ok', $health()['status']);
    }

    public function test_production_without_a_base_domain_is_a_warning(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'https://app.example.com', 'mail.default' => 'smtp', 'session.driver' => 'database', 'queue.default' => 'database', 'cache.default' => 'database']);

        $config = collect(app(SystemHealth::class)->evaluate()['components'])->firstWhere('key', 'config');

        $this->assertSame('warning', $config['status']);
        $this->assertStringContainsString('TENANT_BASE_DOMAIN', $config['summary']);
    }
}
