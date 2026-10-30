<?php

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    /** Gắn thuê bao cho Tenant dựng bằng factory (factory không tự onboarding). */
    private function subscribe(Tenant $tenant, array $attributes = [], ?Plan $plan = null): Subscription
    {
        return $this->platform(function () use ($tenant, $attributes, $plan) {
            $plan ??= Plan::where('key', 'standard')->firstOrFail();

            $s = new Subscription($attributes + [
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'seats' => 10,
                'unit_price' => 150000,
                'max_branches' => 3,
                'storage_mb' => 5120,
                'is_trial' => false,
                'period_start' => now()->subDays(10),
                'period_end' => now()->addDays(20),
            ]);
            $s->tenant_id = $tenant->id;
            $s->save();

            return $s;
        });
    }

    private function onboard(): array
    {
        $admin = $this->makePlatformAdmin();
        $response = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', [
            'tenant' => ['name' => 'Nông Phát'], 'owner' => ['name' => 'Chủ', 'email' => 'chu@nongphat.vn'],
        ])->assertCreated();

        return [$admin, $response->json('data.id')];
    }

    // --- Onboarding & xem gói --------------------------------------------------------------

    public function test_onboarding_starts_a_trial_with_default_plan_limits_and_a_ledger_row(): void
    {
        [, $tenantId] = $this->onboard();

        $tenant = $this->platform(fn () => Tenant::where('uuid', $tenantId)->firstOrFail());
        $s = $this->platform(fn () => Subscription::where('tenant_id', $tenant->id)->firstOrFail());

        $this->assertTrue($s->is_trial);
        $this->assertSame('trial', $s->state());
        $this->assertSame(config('billing.trial_seats'), $s->seats);
        $this->assertSame(3, $s->max_branches);
        $this->assertEqualsWithDelta(config('billing.trial_days'), $s->daysLeft(), 1);

        $rows = $this->platform(fn () => SubscriptionPeriod::where('tenant_id', $tenant->id)->get());
        $this->assertCount(1, $rows);
        $this->assertSame('trial', $rows[0]->kind);
        $this->assertSame(0, (int) $rows[0]->amount);

        $this->assertSame(1, $this->auditCount(['module' => 'billing', 'action' => 'trial_started', 'tenant_id' => $tenant->id]));
    }

    public function test_owner_sees_own_subscription_and_usage(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t, ['seats' => 7]);

        $this->spa()->actingAs($owner)->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.seats', 7)
            ->assertJsonPath('data.plan.key', 'standard')
            ->assertJsonPath('data.usage.users', 1)
            ->assertJsonPath('data.usage.branches', 1)
            ->assertJsonMissingPath('data.contact_note');
    }

    public function test_user_without_billing_permission_cannot_view_subscription(): void
    {
        [$t, , $b] = $this->makeTenant();
        $this->subscribe($t);
        $sales = $this->makeUserIn($t, ['sales'], $b);

        $this->spa()->actingAs($sales)->getJson('/api/v1/subscription')->assertForbidden();
    }

    public function test_tenant_without_subscription_is_not_restricted(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'CN-02', 'name' => 'Chi nhánh 2'])->assertCreated();
        $this->spa()->actingAs($owner)->getJson('/api/v1/subscription')->assertNotFound()->assertJsonPath('code', 'subscription_not_found');
    }

    public function test_a_tenant_never_sees_another_tenants_subscription(): void
    {
        [$a, $ownerA] = $this->makeTenant();
        [$b] = $this->makeTenant();
        $this->subscribe($a, ['seats' => 11]);
        $this->subscribe($b, ['seats' => 22]);

        $this->spa()->actingAs($ownerA)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.seats', 11);
    }

    // --- Hết hạn: ân hạn rồi chỉ đọc -------------------------------------------------------

    public function test_expired_within_grace_still_allows_writes(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t, ['period_end' => now()->subDays(2)]);

        $this->spa()->actingAs($owner)->getJson('/api/v1/subscription')->assertJsonPath('data.state', 'grace');
        $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'CN-02', 'name' => 'Chi nhánh 2'])->assertCreated();
    }

    public function test_after_grace_the_tenant_is_read_only_but_data_stays_readable(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t, ['period_end' => now()->subDays(config('billing.grace_days') + 1)]);

        $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'CN-02', 'name' => 'Chi nhánh 2'])
            ->assertStatus(402)->assertJsonPath('code', 'subscription_expired');

        $this->spa()->actingAs($owner)->getJson('/api/v1/branches')->assertOk();
        $this->spa()->actingAs($owner)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.read_only', true);
        $this->spa()->actingAs($owner)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_expiry_does_not_block_logout_or_password_change(): void
    {
        [$t, $owner] = $this->makeTenant(user: ['password' => 'Mat-khau-cu-2026']);
        $this->subscribe($t, ['period_end' => now()->subDays(60)]);

        $this->spa()->actingAs($owner)->postJson('/api/v1/auth/password', [
            'current_password' => 'Mat-khau-cu-2026', 'password' => 'Mat-khau-moi-2027', 'password_confirmation' => 'Mat-khau-moi-2027',
        ])->assertOk();
    }

    public function test_renewal_lifts_read_only(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t, ['period_end' => now()->subDays(60)]);
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", [])->assertOk();

        $this->freshBrowser()->spa()->actingAs($owner)
            ->postJson('/api/v1/branches', ['code' => 'CN-02', 'name' => 'Chi nhánh 2'])->assertCreated();
    }

    // --- Giới hạn gói ----------------------------------------------------------------------

    public function test_seat_limit_blocks_new_users_and_unlocking_but_not_after_a_seat_frees_up(): void
    {
        [$t, $owner, $b] = $this->makeTenant();
        $this->subscribe($t, ['seats' => 2]);
        $second = $this->makeUserIn($t, ['sales'], $b);

        $this->spa()->actingAs($owner)->postJson('/api/v1/users', ['name' => 'Thêm', 'email' => 'them@example.com'])
            ->assertStatus(402)->assertJsonPath('code', 'seat_limit_reached');

        // Khóa một người => trống một suất.
        $this->spa()->actingAs($owner)->patchJson("/api/v1/users/{$second->uuid}", ['status' => 'disabled'])->assertOk();
        $this->spa()->actingAs($owner)->postJson('/api/v1/users', ['name' => 'Thêm', 'email' => 'them@example.com'])->assertCreated();

        // Mở khóa lại người cũ thì đã hết suất.
        $this->spa()->actingAs($owner)->patchJson("/api/v1/users/{$second->uuid}", ['status' => 'active'])
            ->assertStatus(402)->assertJsonPath('code', 'seat_limit_reached');
    }

    public function test_deleted_and_disabled_users_do_not_use_seats(): void
    {
        [$t, $owner, $b] = $this->makeTenant();
        $this->subscribe($t, ['seats' => 2]);
        $this->makeUserIn($t, ['sales'], $b, ['status' => User::STATUS_DISABLED]);

        $this->spa()->actingAs($owner)->postJson('/api/v1/users', ['name' => 'Thêm', 'email' => 'them@example.com'])->assertCreated();
    }

    public function test_branch_limit_blocks_new_branches(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t, ['max_branches' => 2]);

        $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'CN-02', 'name' => 'Chi nhánh 2'])->assertCreated();
        $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'CN-03', 'name' => 'Chi nhánh 3'])
            ->assertStatus(402)->assertJsonPath('code', 'branch_limit_reached');
    }

    public function test_null_branch_limit_means_unlimited(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t, ['max_branches' => null]);

        foreach (range(2, 6) as $i) {
            $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => "CN-0{$i}", 'name' => "Chi nhánh {$i}"])->assertCreated();
        }
    }

    // --- Gia hạn ---------------------------------------------------------------------------

    public function test_monthly_renewal_extends_from_the_current_end_and_records_payment(): void
    {
        [$t] = $this->makeTenant();
        $s = $this->subscribe($t, ['seats' => 4, 'unit_price' => 150000, 'is_trial' => true]);
        $admin = $this->makePlatformAdmin();
        $oldEnd = $s->period_end->copy();

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", [
            'periods' => 2, 'payment_method' => 'bank_transfer', 'payment_ref' => 'VCB-123', 'note' => 'Khách chuyển khoản',
        ])->assertOk()
            ->assertJsonPath('data.is_trial', false)
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.periods.0.kind', 'renewal')
            ->assertJsonPath('data.periods.0.amount', 150000 * 4 * 2)
            ->assertJsonPath('data.periods.0.payment_ref', 'VCB-123');

        $this->platform(fn () => $s->refresh());
        $this->assertTrue($oldEnd->copy()->addMonthsNoOverflow(2)->equalTo($s->period_end));
        $this->assertSame(1, $this->auditCount(['module' => 'billing', 'action' => 'renewed', 'tenant_id' => $t->id]));
    }

    public function test_yearly_renewal_adds_twelve_months_per_period_at_the_yearly_price(): void
    {
        [$t] = $this->makeTenant();
        $s = $this->subscribe($t, ['billing_cycle' => 'yearly', 'seats' => 3, 'unit_price' => 1500000]);
        $admin = $this->makePlatformAdmin();
        $oldEnd = $s->period_end->copy();

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", [])
            ->assertOk()->assertJsonPath('data.periods.0.amount', 1500000 * 3);

        $this->assertTrue($oldEnd->copy()->addMonthsNoOverflow(12)->equalTo($this->platform(fn () => $s->refresh())->period_end));
    }

    public function test_renewing_an_expired_subscription_starts_from_today(): void
    {
        [$t] = $this->makeTenant();
        $s = $this->subscribe($t, ['period_end' => now()->subDays(90)]);
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", [])->assertOk();

        $this->platform(fn () => $s->refresh());
        $this->assertEqualsWithDelta(now()->addMonth()->getTimestamp(), $s->period_end->getTimestamp(), 5);
    }

    public function test_a_custom_amount_overrides_the_computed_one(): void
    {
        [$t] = $this->makeTenant();
        $this->subscribe($t);
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", ['amount' => 999000])
            ->assertOk()->assertJsonPath('data.periods.0.amount', 999000);
    }

    public function test_renew_validates_input(): void
    {
        [$t] = $this->makeTenant();
        $this->subscribe($t);
        $admin = $this->makePlatformAdmin();
        $url = "/api/v1/platform/tenants/{$t->uuid}/subscription/renew";

        $this->spa()->actingAs($admin)->postJson($url, ['periods' => 0])->assertUnprocessable();
        $this->spa()->actingAs($admin)->postJson($url, ['periods' => 999])->assertUnprocessable();
        $this->spa()->actingAs($admin)->postJson($url, ['payment_method' => 'bitcoin'])->assertUnprocessable();
        $this->spa()->actingAs($admin)->postJson($url, ['amount' => -5])->assertUnprocessable();
    }

    // --- Đổi gói ---------------------------------------------------------------------------

    public function test_changing_plan_snapshots_new_price_and_limits_without_moving_the_end_date(): void
    {
        [$t] = $this->makeTenant();
        $s = $this->subscribe($t);
        $pro = $this->platform(fn () => Plan::where('key', 'pro')->firstOrFail());
        $admin = $this->makePlatformAdmin();
        $end = $s->period_end->copy();

        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/tenants/{$t->uuid}/subscription", [
            'plan_id' => $pro->uuid, 'billing_cycle' => 'yearly', 'seats' => 12,
        ])->assertOk()
            ->assertJsonPath('data.plan.key', 'pro')
            ->assertJsonPath('data.unit_price', 2500000)
            ->assertJsonPath('data.max_branches', 10)
            ->assertJsonPath('data.seats', 12)
            ->assertJsonPath('data.periods.0.kind', 'change');

        $this->assertTrue($end->equalTo($this->platform(fn () => $s->refresh())->period_end));
        $this->assertSame(1, $this->auditCount(['module' => 'billing', 'action' => 'plan_changed', 'tenant_id' => $t->id]));
    }

    public function test_later_plan_price_changes_do_not_affect_existing_subscribers(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t, ['unit_price' => 150000]);
        $admin = $this->makePlatformAdmin();
        $plan = $this->platform(fn () => Plan::where('key', 'standard')->firstOrFail());

        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/plans/{$plan->uuid}", ['price_monthly' => 999999])->assertOk();

        $this->freshBrowser()->spa()->actingAs($owner)->getJson('/api/v1/subscription')->assertJsonPath('data.unit_price', 150000);
    }

    public function test_negotiated_unit_price_can_be_set(): void
    {
        [$t] = $this->makeTenant();
        $this->subscribe($t);
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/tenants/{$t->uuid}/subscription", ['unit_price' => 99000])
            ->assertOk()->assertJsonPath('data.unit_price', 99000);
    }

    public function test_seats_cannot_drop_below_active_users(): void
    {
        [$t, , $b] = $this->makeTenant();
        $this->makeUserIn($t, ['sales'], $b);
        $this->makeUserIn($t, ['sales'], $b);
        $this->subscribe($t, ['seats' => 5]);
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/tenants/{$t->uuid}/subscription", ['seats' => 2])
            ->assertUnprocessable()->assertJsonPath('code', 'subscription_limit_exceeded');
    }

    public function test_downgrade_below_branch_usage_is_rejected(): void
    {
        [$t, , $b] = $this->makeTenant();
        $this->platform(fn () => Branch::factory()->count(4)->create(['tenant_id' => $t->id]));
        $this->subscribe($t, ['max_branches' => 10]);
        $small = $this->platform(fn () => Plan::factory()->create(['max_branches' => 2]));
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/tenants/{$t->uuid}/subscription", ['plan_id' => $small->uuid])
            ->assertUnprocessable()->assertJsonPath('code', 'subscription_limit_exceeded');
    }

    public function test_inactive_plan_cannot_be_assigned(): void
    {
        [$t] = $this->makeTenant();
        $this->subscribe($t);
        $plan = $this->platform(fn () => Plan::factory()->create(['is_active' => false]));
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/tenants/{$t->uuid}/subscription", ['plan_id' => $plan->uuid])
            ->assertUnprocessable()->assertJsonPath('code', 'plan_inactive');
    }

    // --- Việc cần liên hệ ------------------------------------------------------------------

    public function test_renewal_queue_lists_expiring_and_expired_soonest_first_and_skips_the_rest(): void
    {
        [$soon] = $this->makeTenant(['name' => 'Sắp hết']);
        [$late] = $this->makeTenant(['name' => 'Đã hết']);
        [$far] = $this->makeTenant(['name' => 'Còn lâu']);
        [$locked] = $this->makeTenant(['name' => 'Bị khóa'] + ['status' => Tenant::STATUS_SUSPENDED]);
        $this->subscribe($soon, ['period_end' => now()->addDays(5)]);
        $this->subscribe($late, ['period_end' => now()->subDays(3)]);
        $this->subscribe($far, ['period_end' => now()->addDays(90)]);
        $this->subscribe($locked, ['period_end' => now()->addDays(1)]);
        $admin = $this->makePlatformAdmin();

        $names = $this->spa()->actingAs($admin)->getJson('/api/v1/platform/subscriptions/renewals')->assertOk()
            ->assertJsonPath('data.0.owner.email', fn ($v) => $v !== null)
            ->json('data.*.tenant.name');

        $this->assertSame(['Đã hết', 'Sắp hết'], $names);
    }

    public function test_recording_a_contact_marks_it_and_renewal_clears_it(): void
    {
        [$t] = $this->makeTenant();
        $this->subscribe($t, ['period_end' => now()->addDays(2)]);
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/contact", ['note' => 'Đã gọi, hẹn thanh toán thứ Sáu'])
            ->assertOk()->assertJsonPath('data.contact_note', 'Đã gọi, hẹn thanh toán thứ Sáu');

        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/subscriptions/renewals?contacted=0')->assertJsonCount(0, 'data');
        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/subscriptions/renewals?contacted=1')->assertJsonCount(1, 'data');
        $this->assertSame(1, $this->auditCount(['module' => 'billing', 'action' => 'contacted', 'tenant_id' => $t->id]));

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", [])->assertOk()
            ->assertJsonPath('data.contact_note', null);
    }

    // --- Banner trong ứng dụng -------------------------------------------------------------

    public function test_me_only_carries_a_notice_when_renewal_is_near_or_overdue(): void
    {
        [$t, $owner] = $this->makeTenant();
        $s = $this->subscribe($t, ['period_end' => now()->addDays(60)]);

        $this->spa()->actingAs($owner)->getJson('/api/v1/auth/me')->assertJsonPath('data.subscription_notice', null);

        $this->platform(fn () => $s->forceFill(['period_end' => now()->addDays(6)])->save());
        $this->spa()->actingAs($owner)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.subscription_notice.state', 'active')
            ->assertJsonPath('data.subscription_notice.days_left', 6)
            ->assertJsonPath('data.subscription_notice.read_only', false);

        $this->platform(fn () => $s->forceFill(['period_end' => now()->subDays(30)])->save());
        $this->spa()->actingAs($owner)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.subscription_notice.state', 'expired')
            ->assertJsonPath('data.subscription_notice.read_only', true);
    }

    public function test_reminder_level_follows_the_configured_milestones(): void
    {
        $level = fn (int $days) => (new Subscription(['period_end' => now()->addDays($days)->addHour()]))->reminderLevel();

        $this->assertNull($level(30));
        $this->assertSame(15, $level(14));
        $this->assertSame(7, $level(6));
        $this->assertSame(3, $level(2));
        $this->assertSame(1, $level(0));
        $this->assertSame(1, $level(-4));
    }

    // --- Quyền & cấu hình gói --------------------------------------------------------------

    public function test_tenant_users_cannot_use_platform_billing_endpoints(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t);

        $this->spa()->actingAs($owner)->getJson('/api/v1/platform/plans')->assertForbidden();
        $this->spa()->actingAs($owner)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", [])->assertForbidden();
        $this->spa()->actingAs($owner)->getJson('/api/v1/platform/subscriptions/renewals')->assertForbidden();
    }

    public function test_platform_admin_manages_plans(): void
    {
        $admin = $this->makePlatformAdmin();
        $payload = ['key' => 'gold', 'name' => 'Vàng', 'price_monthly' => 400000, 'price_yearly' => 4000000, 'max_branches' => null, 'storage_mb' => 102400];

        $id = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/plans', $payload)
            ->assertCreated()->assertJsonPath('data.key', 'gold')->json('data.id');

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/plans', $payload)->assertUnprocessable();
        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/plans', ['key' => 'Bad Key'] + $payload)->assertUnprocessable();
        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/plans/{$id}", ['key' => 'silver'])->assertUnprocessable();
        $this->spa()->actingAs($admin)->patchJson("/api/v1/platform/plans/{$id}", ['is_active' => false, 'price_monthly' => 450000])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.price_monthly', 450000);

        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/plans')->assertOk();
        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/plans/not-a-uuid', [])->assertNotFound();
        $this->assertGreaterThanOrEqual(1, $this->auditCount(['module' => 'billing', 'action' => 'created']));
    }

    public function test_tenant_owner_sees_billing_events_in_their_audit_log(): void
    {
        [$t, $owner] = $this->makeTenant();
        $this->subscribe($t);
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/tenants/{$t->uuid}/subscription/renew", [])->assertOk();

        $actions = $this->freshBrowser()->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?module=billing')->assertOk()->json('data.*.action');
        $this->assertContains('renewed', $actions);
    }
}
