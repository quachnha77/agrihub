<?php

namespace Tests\Unit;

use App\Support\Rbac\Catalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Kiểm tra cấu hình config/rbac.php nhất quán (không cần DB). */
class RbacCatalogTest extends TestCase
{
    public static function roleKeys(): array
    {
        // Data provider chạy trước khi app khởi động nên chưa dùng được config().
        $config = require __DIR__.'/../../config/rbac.php';

        return array_map(fn ($k) => [$k], array_keys($config['roles']));
    }

    #[DataProvider('roleKeys')]
    public function test_every_role_grant_refers_to_an_existing_permission_and_scope(string $role): void
    {
        $grants = Catalog::roleGrants($role); // ném lỗi nếu resource/hành động/scope không hợp lệ

        $this->assertNotEmpty($grants);
        foreach ($grants as $key => $scope) {
            $this->assertTrue(Catalog::has($key), $key);
            $this->assertArrayHasKey($scope, Catalog::SCOPES);
        }
    }

    public function test_owner_has_every_permission_at_tenant_scope(): void
    {
        $grants = Catalog::roleGrants('owner');

        $this->assertSame(array_keys(Catalog::permissions()), array_keys($grants));
        $this->assertSame(['tenant'], array_values(array_unique($grants)));
    }

    public function test_only_owner_can_manage_backups_and_subscription_changes(): void
    {
        foreach (array_diff(Catalog::roleKeys(), ['owner']) as $role) {
            $grants = Catalog::roleGrants($role);
            $this->assertArrayNotHasKey('backup.backup.restore', $grants, $role);
            $this->assertArrayNotHasKey('backup.backup.create', $grants, $role);
            $this->assertArrayNotHasKey('billing.subscription.update', $grants, $role);
        }
    }

    public function test_sales_can_only_edit_or_cancel_own_orders(): void
    {
        $g = Catalog::roleGrants('sales');

        $this->assertSame('branch', $g['sales.order.view']);
        $this->assertSame('branch', $g['sales.order.create']);
        $this->assertSame('own', $g['sales.order.update']);
        $this->assertSame('own', $g['sales.order.cancel']);
        $this->assertArrayNotHasKey('sales.order.approve', $g);
    }

    public function test_warehouse_cannot_touch_money_or_approve_stock(): void
    {
        $g = Catalog::roleGrants('warehouse');

        $this->assertArrayNotHasKey('finance.cashbook.view', $g);
        $this->assertArrayNotHasKey('finance.receivable.view', $g);
        $this->assertArrayNotHasKey('inventory.movement.approve', $g);
        $this->assertArrayNotHasKey('inventory.adjustment.approve', $g);
    }

    public function test_covers_compares_scope_ranks(): void
    {
        $have = ['a.b.view' => 'branch'];

        $this->assertTrue(Catalog::covers($have, ['a.b.view' => 'own']));
        $this->assertTrue(Catalog::covers($have, ['a.b.view' => 'branch']));
        $this->assertFalse(Catalog::covers($have, ['a.b.view' => 'tenant']));
        $this->assertFalse(Catalog::covers($have, ['a.b.create' => 'own']));
        $this->assertTrue(Catalog::covers($have, []));
    }
}
