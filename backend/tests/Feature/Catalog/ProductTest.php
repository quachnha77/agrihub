<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Sản phẩm: tạo / sửa / xóa, thông tin theo loại hàng, đơn vị + giá + mã vạch, và quy tắc theo chi nhánh. */
class ProductTest extends TestCase
{
    use CreatesTenants, MakesCatalog, ReadsAuditLog, RefreshDatabase;

    private function createProduct(object $user, array $payload)
    {
        return $this->spa()->actingAs($user)->postJson('/api/v1/products', $payload);
    }

    private function editProduct(object $user, string $id, array $payload)
    {
        return $this->spa()->actingAs($user)->patchJson("/api/v1/products/{$id}", $payload);
    }

    // --- tạo -------------------------------------------------------------------------------------

    public function test_the_owner_creates_a_product_with_a_base_unit_and_a_pack_unit(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $category = $this->makeCategory($tenant, 'Phân NPK');
        $brand = $this->makeBrand($tenant, 'Đầu Trâu');

        $response = $this->createProduct($owner, $this->productPayload($branch, $kg, [
            'type' => 'fertilizer', 'sku' => 'NPK-16', 'name' => 'NPK 16-16-8', 'category_id' => $category->uuid, 'brand_id' => $brand->uuid,
            'specs' => ['composition' => 'N16 P16 K8', 'origin' => 'Việt Nam'],
            'units' => [
                ['unit_id' => $bao->uuid, 'factor' => 50, 'price_cash' => 750000, 'price_credit' => 780000, 'barcode' => '8930000000012'],
                ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 15500, 'price_credit' => 16000],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('data.sku', 'NPK-16')
            ->assertJsonPath('data.type', 'fertilizer')->assertJsonPath('data.type_label', 'Phân bón')
            ->assertJsonPath('data.branch.id', $branch->uuid)->assertJsonPath('data.branch.code', $branch->code)
            ->assertJsonPath('data.category.name', 'Phân NPK')->assertJsonPath('data.brand.name', 'Đầu Trâu')
            ->assertJsonPath('data.base_unit.name', 'Kg')->assertJsonPath('data.track_batch', false)
            ->assertJsonPath('data.specs.composition', 'N16 P16 K8');

        // Đơn vị cơ sở đứng trước, rồi các đơn vị quy đổi từ nhỏ đến lớn; tiền và hệ số là chuỗi thập phân chính xác.
        $this->assertSame(['Kg', 'Bao'], array_column(array_column($response->json('data.units'), 'unit'), 'name'));
        $this->assertSame([true, false], array_column($response->json('data.units'), 'is_base'));
        $this->assertSame(['1', '50'], array_column($response->json('data.units'), 'factor'));
        $this->assertSame(['15500.00', '750000.00'], array_column($response->json('data.units'), 'price_cash'));
        $this->assertSame(['16000.00', '780000.00'], array_column($response->json('data.units'), 'price_credit'));
        $this->assertSame([null, '8930000000012'], array_column($response->json('data.units'), 'barcode'));

        $this->assertSame($tenant->id, $this->platform(fn () => Product::firstOrFail()->tenant_id), 'tenant_id lấy từ context, không từ request');
    }

    public function test_creating_writes_audit_entries_for_the_product_and_its_prices(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');

        $this->createProduct($owner, $this->productPayload($branch, $kg))->assertCreated();

        $created = $this->lastAudit(['module' => 'product', 'action' => 'created']);
        $this->assertSame($branch->id, $created->branch_id, 'audit gắn chi nhánh để quản lý chi nhánh xem được');

        $units = $this->lastAudit(['module' => 'product', 'action' => 'units_changed']);
        $this->assertNull($units->before);
        // So sánh không phụ thuộc thứ tự khóa: cột JSONB của PostgreSQL không giữ thứ tự.
        $this->assertEquals([['unit' => 'Kg', 'factor' => '1', 'price_cash' => '10000.00', 'price_credit' => '11000.00', 'barcode' => null]], $units->after['units']);
    }

    public function test_a_pesticide_needs_its_registration_data_and_always_tracks_batches(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $chai = $this->makeUnit($tenant, 'Chai');

        // Thiếu số đăng ký và hoạt chất.
        $this->createProduct($owner, $this->pesticidePayload($branch, $chai, ['specs' => ['toxicity_group' => 'II']]))
            ->assertStatus(422)->assertJsonValidationErrors(['specs.registration_no', 'specs.active_ingredient']);
        $this->createProduct($owner, $this->pesticidePayload($branch, $chai, ['specs' => null]))->assertStatus(422)->assertJsonValidationErrors('specs');

        // Không gửi track_batch: tự bật. Gửi false: bị từ chối (cả ở database).
        $this->createProduct($owner, $this->pesticidePayload($branch, $chai))->assertCreated()->assertJsonPath('data.track_batch', true)
            ->assertJsonPath('data.specs.registration_no', '1234/CP-BVTV')->assertJsonPath('data.specs.phi_days', 7);
        $this->createProduct($owner, $this->pesticidePayload($branch, $chai, ['sku' => 'T-002', 'track_batch' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('track_batch');

        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::table('products')->where('type', 'pesticide')->update(['track_batch' => false]));
    }

    public function test_pesticide_fields_are_validated(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $chai = $this->makeUnit($tenant, 'Chai');
        $bad = fn (array $specs) => $this->createProduct($owner, $this->pesticidePayload($branch, $chai, ['specs' => $specs + ['registration_no' => 'A', 'active_ingredient' => 'B']]));

        $bad(['toxicity_group' => 'V'])->assertStatus(422)->assertJsonValidationErrors('specs.toxicity_group');
        $bad(['phi_days' => 400])->assertStatus(422)->assertJsonValidationErrors('specs.phi_days');
        $bad(['phi_days' => -1])->assertStatus(422)->assertJsonValidationErrors('specs.phi_days');
        $bad(['phi_days' => 'bảy'])->assertStatus(422)->assertJsonValidationErrors('specs.phi_days');
        // Khóa của loại hàng khác không được nhét vào.
        $bad(['variety' => 'ST25'])->assertStatus(422)->assertJsonValidationErrors('specs');
        $bad(['evil' => '<script>'])->assertStatus(422)->assertJsonValidationErrors('specs');
    }

    public function test_a_seed_needs_a_variety_and_a_sane_germination_rate(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $seed = fn (array $specs, string $sku = 'H-001') => $this->createProduct($owner, $this->productPayload($branch, $kg, ['type' => 'seed', 'sku' => $sku, 'name' => 'Lúa', 'specs' => $specs]));

        $seed(['origin' => 'Cần Thơ'])->assertStatus(422)->assertJsonValidationErrors('specs.variety');
        $seed(['variety' => 'ST25', 'germination_rate' => 120])->assertStatus(422)->assertJsonValidationErrors('specs.germination_rate');
        $seed(['variety' => 'ST25', 'germination_rate' => 85.5, 'origin' => ' Sóc Trăng '])->assertCreated()
            ->assertJsonPath('data.track_batch', true)->assertJsonPath('data.specs.germination_rate', 85.5)->assertJsonPath('data.specs.origin', 'Sóc Trăng');
    }

    public function test_other_goods_take_no_specific_fields_and_empty_values_are_dropped(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $cai = $this->makeUnit($tenant, 'Cái');

        $this->createProduct($owner, $this->productPayload($branch, $cai, ['specs' => ['bat_ky' => 'x']]))->assertStatus(422)->assertJsonValidationErrors('specs');
        $this->createProduct($owner, $this->productPayload($branch, $cai))->assertCreated()->assertJsonPath('data.track_batch', false)->assertJsonPath('data.specs', []);

        $this->createProduct($owner, $this->productPayload($branch, $cai, ['sku' => 'P-1', 'type' => 'fertilizer', 'specs' => ['composition' => '   ', 'origin' => null]]))
            ->assertCreated()->assertJsonPath('data.specs', []);

        // Loại hàng "vật tư khác" và "phân bón" cho phép bật theo dõi lô tùy ý.
        $this->createProduct($owner, $this->productPayload($branch, $cai, ['sku' => 'P-2', 'track_batch' => true]))->assertCreated()->assertJsonPath('data.track_batch', true);
    }

    public function test_required_fields_and_formats_are_checked_with_vietnamese_messages(): void
    {
        [, $owner] = $this->makeTenant();

        $response = $this->createProduct($owner, [])->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'sku', 'name', 'base_unit_id', 'branch_id', 'units']);
        $this->assertStringContainsStringIgnoringCase('mã sản phẩm', $response->json('errors.sku.0'));

        [$tenant, $owner2, $branch] = $this->makeTenant();
        $cai = $this->makeUnit($tenant, 'Cái');
        $this->createProduct($owner2, $this->productPayload($branch, $cai, ['sku' => 'có dấu cách']))->assertStatus(422)->assertJsonValidationErrors('sku');
        $this->createProduct($owner2, $this->productPayload($branch, $cai, ['type' => 'khong-co']))->assertStatus(422)->assertJsonValidationErrors('type');
        $this->createProduct($owner2, $this->productPayload($branch, $cai, ['name' => str_repeat('a', 256)]))->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // --- đơn vị, giá, mã vạch ---------------------------------------------------------------------

    public function test_the_unit_list_must_contain_exactly_one_base_unit_with_factor_one(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $row = fn ($unit, $factor = 1) => ['unit_id' => $unit->uuid, 'factor' => $factor, 'price_cash' => 1000, 'price_credit' => 1000];

        // Thiếu dòng đơn vị cơ sở.
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['units' => [$row($bao, 50)]]))->assertStatus(422)->assertJsonValidationErrors('units');
        // Đơn vị cơ sở phải có hệ số 1.
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['units' => [$row($kg, 2)]]))->assertStatus(422)->assertJsonValidationErrors('units');
        // Không trùng đơn vị.
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['units' => [$row($kg), $row($kg)]]))->assertStatus(422)->assertJsonValidationErrors('units.0.unit_id');
        // Hệ số phải dương; tối đa 6 chữ số thập phân.
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['units' => [$row($kg), $row($bao, 0)]]))->assertStatus(422)->assertJsonValidationErrors('units.1.factor');
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['units' => [$row($kg), $row($bao, 0.0000001)]]))->assertStatus(422)->assertJsonValidationErrors('units.1.factor');
        // Không có sản phẩm nào được tạo dở.
        $this->assertSame(0, $this->platform(fn () => Product::count()));
    }

    public function test_prices_are_exact_decimals_and_credit_may_differ_from_cash(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $price = fn (array $over) => $this->createProduct($owner, $this->productPayload($branch, $kg, ['units' => [array_merge(['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 100, 'price_credit' => 100], $over)]]));

        $price(['price_cash' => -1])->assertStatus(422)->assertJsonValidationErrors('units.0.price_cash');
        $price(['price_credit' => 12.345])->assertStatus(422)->assertJsonValidationErrors('units.0.price_credit');
        $price(['price_cash' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('units.0.price_cash');
        $price(['price_credit' => 1000000000000])->assertStatus(422)->assertJsonValidationErrors('units.0.price_credit');
        $price(['price_cash' => null])->assertStatus(422)->assertJsonValidationErrors('units.0.price_cash');

        // Giá công nợ không bắt buộc cao hơn giá tiền mặt; 0.1 + 0.2 không sinh sai số.
        $this->assertSame('0.30', $price(['price_cash' => 0.3, 'price_credit' => 0])->assertCreated()->json('data.units.0.price_cash'));
    }

    public function test_barcodes_are_unique_within_a_branch_only(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $other = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $with = fn ($code, $sku) => $this->productPayload($branch, $kg, ['sku' => $sku, 'units' => [['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 1, 'price_credit' => 1, 'barcode' => $code]]]);

        $this->createProduct($owner, $with('8930001', 'A'))->assertCreated();
        $this->createProduct($owner, $with('8930001', 'B'))->assertStatus(422)->assertJsonValidationErrors('units.0.barcode');

        // Trùng ngay trong một sản phẩm.
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['sku' => 'C', 'units' => [
            ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 1, 'price_credit' => 1, 'barcode' => '777'],
            ['unit_id' => $bao->uuid, 'factor' => 50, 'price_cash' => 1, 'price_credit' => 1, 'barcode' => '777'],
        ]]))->assertStatus(422)->assertJsonValidationErrors('units.1.barcode');

        // Chi nhánh khác được dùng lại cùng mã vạch (cùng mặt hàng ở chi nhánh khác).
        $this->createProduct($owner, array_merge($with('8930001', 'A'), ['branch_id' => $other->uuid]))->assertCreated();
    }

    // --- mã sản phẩm theo chi nhánh -----------------------------------------------------------------

    public function test_sku_is_unique_per_branch_ignoring_case_and_is_released_by_deleting(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $other = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');

        $id = $this->createProduct($owner, $this->productPayload($branch, $kg, ['sku' => 'Abc-1']))->assertCreated()->json('data.id');
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['sku' => 'ABC-1']))->assertStatus(422)->assertJsonValidationErrors('sku');
        $this->createProduct($owner, $this->productPayload($other, $kg, ['sku' => 'ABC-1']))->assertCreated();

        $this->spa()->actingAs($owner)->deleteJson("/api/v1/products/{$id}")->assertNoContent();
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['sku' => 'abc-1']))->assertCreated();
    }

    public function test_deleting_releases_the_barcodes_and_hides_the_product(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $payload = fn ($sku) => $this->productPayload($branch, $kg, ['sku' => $sku, 'units' => [['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 1, 'price_credit' => 1, 'barcode' => '999']]]);

        $id = $this->createProduct($owner, $payload('A'))->assertCreated()->json('data.id');
        $this->spa()->actingAs($owner)->deleteJson("/api/v1/products/{$id}")->assertNoContent();

        $this->spa()->actingAs($owner)->getJson("/api/v1/products/{$id}")->assertNotFound();
        $this->spa()->actingAs($owner)->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, $this->platform(fn () => Product::onlyTrashed()->count()), 'xóa mềm: dữ liệu còn để truy vết');
        $this->createProduct($owner, $payload('B'))->assertCreated();
        $this->assertNotNull($this->lastAudit(['module' => 'product', 'action' => 'deleted']));
    }

    // --- sửa --------------------------------------------------------------------------------------

    public function test_updating_changes_only_what_is_sent(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $chai = $this->makeUnit($tenant, 'Chai');
        $id = $this->createProduct($owner, $this->pesticidePayload($branch, $chai))->assertCreated()->json('data.id');

        $this->editProduct($owner, $id, ['name' => 'Thuốc ABC 1.8EC', 'status' => 'inactive'])->assertOk()
            ->assertJsonPath('data.name', 'Thuốc ABC 1.8EC')->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.sku', 'T-001')->assertJsonPath('data.specs.registration_no', '1234/CP-BVTV')
            ->assertJsonCount(1, 'data.units');
    }

    public function test_specs_are_replaced_as_a_whole_so_required_fields_must_be_sent_again(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $chai = $this->makeUnit($tenant, 'Chai');
        $id = $this->createProduct($owner, $this->pesticidePayload($branch, $chai))->assertCreated()->json('data.id');

        // Chỉ gửi một phần sẽ làm mất số đăng ký đã lưu nên bị từ chối.
        $this->editProduct($owner, $id, ['specs' => ['phi_days' => 14]])->assertStatus(422)->assertJsonValidationErrors(['specs.registration_no', 'specs.active_ingredient']);
        $this->editProduct($owner, $id, ['specs' => null])->assertStatus(422);

        $this->editProduct($owner, $id, ['specs' => ['registration_no' => '999/CP', 'active_ingredient' => 'Emamectin', 'phi_days' => 14]])->assertOk()
            ->assertJsonPath('data.specs.registration_no', '999/CP')->assertJsonPath('data.specs.phi_days', 14)->assertJsonMissingPath('data.specs.toxicity_group');
    }

    public function test_type_branch_and_base_unit_cannot_change_after_creation(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $other = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $id = $this->createProduct($owner, $this->productPayload($branch, $kg))->assertCreated()->json('data.id');

        $this->editProduct($owner, $id, ['type' => 'seed'])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->editProduct($owner, $id, ['branch_id' => $other->uuid])->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->editProduct($owner, $id, ['base_unit_id' => $bao->uuid])->assertStatus(422)->assertJsonValidationErrors('base_unit_id');
        // Danh sách đơn vị mới vẫn phải giữ đơn vị cơ sở cũ.
        $this->editProduct($owner, $id, ['units' => [['unit_id' => $bao->uuid, 'factor' => 1, 'price_cash' => 1, 'price_credit' => 1]]])->assertStatus(422)->assertJsonValidationErrors('units');
    }

    public function test_changing_prices_is_audited_with_before_and_after_and_units_can_be_replaced(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $id = $this->createProduct($owner, $this->productPayload($branch, $kg, ['units' => [
            ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 10000, 'price_credit' => 11000, 'barcode' => 'K1'],
            ['unit_id' => $bao->uuid, 'factor' => 50, 'price_cash' => 480000, 'price_credit' => 500000, 'barcode' => 'B1'],
        ]]))->assertCreated()->json('data.id');

        // Hoán đổi mã vạch giữa hai đơn vị + đổi giá, trong một lần sửa.
        $this->editProduct($owner, $id, ['units' => [
            ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 12000, 'price_credit' => 13000, 'barcode' => 'B1'],
            ['unit_id' => $bao->uuid, 'factor' => 50, 'price_cash' => 480000, 'price_credit' => 500000, 'barcode' => 'K1'],
        ]])->assertOk()->assertJsonPath('data.units.0.price_cash', '12000.00')->assertJsonPath('data.units.0.barcode', 'B1')->assertJsonPath('data.units.1.barcode', 'K1');

        $audit = $this->lastAudit(['module' => 'product', 'action' => 'units_changed']);
        $this->assertSame('10000.00', $audit->before['units'][0]['price_cash']);
        $this->assertSame('12000.00', $audit->after['units'][0]['price_cash']);

        // Bỏ đơn vị quy đổi: dòng đó bị xóa.
        $this->editProduct($owner, $id, ['units' => [['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 12000, 'price_credit' => 13000]]])->assertOk()->assertJsonCount(1, 'data.units');

        // Sửa không đổi gì ở đơn vị thì không ghi thêm sự kiện.
        $count = $this->auditCount(['module' => 'product', 'action' => 'units_changed']);
        $this->editProduct($owner, $id, ['name' => 'Chỉ đổi tên'])->assertOk();
        $this->assertSame($count, $this->auditCount(['module' => 'product', 'action' => 'units_changed']));
    }

    // --- danh sách và tìm kiếm ----------------------------------------------------------------------

    public function test_listing_filters_and_searches(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $other = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');
        $cat = $this->makeCategory($tenant, 'Phân đạm');
        $brand = $this->makeBrand($tenant, 'Phú Mỹ');
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['sku' => 'URE-01', 'name' => 'Phân Urê', 'type' => 'fertilizer', 'category_id' => $cat->uuid, 'brand_id' => $brand->uuid,
            'units' => [['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 1, 'price_credit' => 1, 'barcode' => '8931234']]]))->assertCreated();
        $this->createProduct($owner, $this->productPayload($branch, $kg, ['sku' => 'DAY-01', 'name' => 'Dây tưới']))->assertCreated();
        $this->createProduct($owner, $this->productPayload($other, $kg, ['sku' => 'URE-01', 'name' => 'Phân Urê', 'type' => 'fertilizer', 'status' => 'inactive']))->assertCreated();

        $list = fn (string $query) => $this->spa()->actingAs($owner)->getJson('/api/v1/products?'.$query)->assertOk();

        $this->assertCount(3, $list('')->json('data'));
        $this->assertCount(2, $list('q=ure')->json('data'), 'tìm theo tên/mã, không phân biệt hoa thường');
        $this->assertCount(1, $list('q=8931234')->json('data'), 'tìm theo mã vạch');
        $this->assertCount(2, $list('type=fertilizer')->json('data'));
        $this->assertCount(2, $list('branch='.$branch->uuid)->json('data'));
        $this->assertCount(1, $list('status=inactive')->json('data'));
        $this->assertCount(1, $list('category='.$cat->uuid)->json('data'));
        $this->assertCount(1, $list('brand='.$brand->uuid)->json('data'));
        $this->assertCount(2, $list('per_page=2')->json('data'));
        $this->spa()->actingAs($owner)->getJson('/api/v1/products?type=khong-co')->assertStatus(422);
    }

    public function test_the_types_endpoint_describes_every_type_for_the_forms(): void
    {
        [, $owner] = $this->makeTenant();

        $data = $this->spa()->actingAs($owner)->getJson('/api/v1/products/types')->assertOk()->json('data');

        $this->assertSame(['fertilizer', 'pesticide', 'seed', 'other'], array_column($data, 'key'));
        $pesticide = collect($data)->firstWhere('key', 'pesticide');
        $this->assertTrue($pesticide['track_batch_required']);
        $this->assertSame(['registration_no', 'active_ingredient', 'toxicity_group', 'phi_days'], array_column($pesticide['fields'], 'key'));
        $this->assertTrue(collect($pesticide['fields'])->firstWhere('key', 'registration_no')['required']);
        $this->assertSame(['I', 'II', 'III', 'IV'], collect($pesticide['fields'])->firstWhere('key', 'toxicity_group')['options']);
    }

    // --- phạm vi theo chi nhánh và vai trò -------------------------------------------------------------

    public function test_a_branch_manager_works_only_in_their_own_branch(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branchA);
        $kg = $this->makeUnit($tenant, 'Kg');
        $inA = $this->createProduct($owner, $this->productPayload($branchA, $kg, ['sku' => 'A-1']))->assertCreated()->json('data.id');
        $inB = $this->createProduct($owner, $this->productPayload($branchB, $kg, ['sku' => 'B-1']))->assertCreated()->json('data.id');

        // Thấy: chỉ chi nhánh của mình, kể cả khi lọc cố tình sang chi nhánh khác.
        $this->assertSame([$inA], array_column($this->spa()->actingAs($manager)->getJson('/api/v1/products')->assertOk()->json('data'), 'id'));
        $this->assertCount(0, $this->spa()->actingAs($manager)->getJson('/api/v1/products?branch='.$branchB->uuid)->assertOk()->json('data'));
        $this->spa()->actingAs($manager)->getJson("/api/v1/products/{$inB}")->assertForbidden();

        // Tạo / sửa: chỉ ở chi nhánh của mình.
        $this->createProduct($manager, $this->productPayload($branchA, $kg, ['sku' => 'A-2']))->assertCreated();
        $this->createProduct($manager, $this->productPayload($branchB, $kg, ['sku' => 'B-2']))->assertForbidden();
        $this->editProduct($manager, $inA, ['name' => 'Đổi tên'])->assertOk();
        $this->editProduct($manager, $inB, ['name' => 'Đổi tên'])->assertForbidden();

        // Quản lý chi nhánh không có quyền xóa sản phẩm.
        $this->spa()->actingAs($manager)->deleteJson("/api/v1/products/{$inA}")->assertForbidden();
    }

    public function test_view_only_roles_cannot_change_the_catalog(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $id = $this->createProduct($owner, $this->productPayload($branch, $kg))->assertCreated()->json('data.id');

        foreach (['accountant', 'warehouse', 'sales'] as $role) {
            $user = $this->makeUserIn($tenant, [$role], $branch);

            $this->spa()->actingAs($user)->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data');
            $this->createProduct($user, $this->productPayload($branch, $kg, ['sku' => "X-{$role}"]))->assertForbidden();
            // 403 đến trước kiểm tra dữ liệu: người không có quyền không nhận được thông báo lỗi của form.
            $this->createProduct($user, [])->assertForbidden();
            $this->spa()->actingAs($user)->postJson('/api/v1/products/copy', [])->assertForbidden();
            $this->editProduct($user, $id, ['name' => 'x'])->assertForbidden();
            $this->spa()->actingAs($user)->deleteJson("/api/v1/products/{$id}")->assertForbidden();
        }
    }

    public function test_a_user_without_catalog_access_gets_403(): void
    {
        [$tenant, , $branch] = $this->makeTenant();
        $delivery = $this->makeUserIn($tenant, ['delivery'], $branch);

        $this->spa()->actingAs($delivery)->getJson('/api/v1/products')->assertForbidden();
        $this->spa()->actingAs($delivery)->getJson('/api/v1/products/types')->assertForbidden();
    }

    // --- cô lập giữa các doanh nghiệp ----------------------------------------------------------------

    public function test_one_business_never_sees_or_touches_anothers_products(): void
    {
        [$tenantA, $ownerA, $branchA] = $this->makeTenant();
        [$tenantB, $ownerB, $branchB] = $this->makeTenant();
        $kgA = $this->makeUnit($tenantA, 'Kg');
        $kgB = $this->makeUnit($tenantB, 'Kg');
        $idB = $this->createProduct($ownerB, $this->productPayload($branchB, $kgB))->assertCreated()->json('data.id');

        $this->assertCount(0, $this->spa()->actingAs($ownerA)->getJson('/api/v1/products')->json('data'));
        $this->spa()->actingAs($ownerA)->getJson("/api/v1/products/{$idB}")->assertNotFound();
        $this->editProduct($ownerA, $idB, ['name' => 'x'])->assertNotFound();
        $this->spa()->actingAs($ownerA)->deleteJson("/api/v1/products/{$idB}")->assertNotFound();

        // Chi nhánh / đơn vị / nhóm hàng của doanh nghiệp khác không dùng được (báo như không tồn tại).
        $this->createProduct($ownerA, $this->productPayload($branchB, $kgA))->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->createProduct($ownerA, $this->productPayload($branchA, $kgB))->assertStatus(422)->assertJsonValidationErrors(['base_unit_id', 'units.0.unit_id']);
        $this->createProduct($ownerA, $this->productPayload($branchA, $kgA, ['category_id' => $this->makeCategory($tenantB, 'Của B')->uuid]))->assertStatus(422)->assertJsonValidationErrors('category_id');

        // Cùng mã sản phẩm ở hai doanh nghiệp vẫn được.
        $this->createProduct($ownerA, $this->productPayload($branchA, $kgA))->assertCreated();
    }

    public function test_row_level_security_hides_other_tenants_products_even_from_raw_queries(): void
    {
        [$tenantA, , $branchA] = $this->makeTenant();
        [$tenantB, $ownerB, $branchB] = $this->makeTenant();
        $kgA = $this->makeUnit($tenantA, 'Kg');
        $kgB = $this->makeUnit($tenantB, 'Kg');
        $this->createProduct($ownerB, $this->productPayload($branchB, $kgB))->assertCreated();
        $this->platform(fn () => $this->createProductRow($tenantA->id, $branchA->id, $kgA->id));

        $seen = app(TenantContext::class)->runForTenant($tenantA->id, fn () => [
            DB::table('products')->count(), DB::table('product_units')->count(), DB::table('units')->count(),
        ]);

        $this->assertSame([1, 0, 1], $seen);
        $this->assertSame(0, app(TenantContext::class)->runForTenant($tenantA->id, fn () => DB::table('products')->where('tenant_id', $tenantB->id)->count()));
    }

    private function createProductRow(int $tenantId, int $branchId, int $unitId): void
    {
        DB::table('products')->insert([
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'type' => 'other', 'sku' => 'RAW', 'name' => 'Raw', 'base_unit_id' => $unitId,
            'specs' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_duplicate_sku_in_a_branch(): void
    {
        [$tenant, , $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $this->platform(fn () => $this->createProductRow($tenant->id, $branch->id, $kg->id));

        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::transaction(fn () => $this->createProductRow($tenant->id, $branch->id, $kg->id)));
    }

    // --- sao chép sang chi nhánh khác --------------------------------------------------------------------

    public function test_copying_to_another_branch_clones_everything_and_skips_existing_skus(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $a = $this->createProduct($owner, $this->pesticidePayload($branchA, $kg, ['sku' => 'T-1', 'units' => [
            ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 100, 'price_credit' => 110, 'barcode' => '111'],
            ['unit_id' => $bao->uuid, 'factor' => 25, 'price_cash' => 2400, 'price_credit' => 2600, 'barcode' => '222'],
        ]]))->assertCreated()->json('data.id');
        $b = $this->createProduct($owner, $this->productPayload($branchA, $kg, ['sku' => 'D-1']))->assertCreated()->json('data.id');
        $this->createProduct($owner, $this->productPayload($branchB, $kg, ['sku' => 'd-1']))->assertCreated();

        $response = $this->spa()->actingAs($owner)->postJson('/api/v1/products/copy', ['product_ids' => [$a, $b], 'branch_id' => $branchB->uuid])->assertOk();

        $response->assertJsonPath('meta.copied', 1)->assertJsonPath('meta.skipped.0.sku', 'D-1')->assertJsonPath('meta.skipped.0.reason', 'sku_exists');
        $copy = $response->json('data.0');
        $this->assertSame($branchB->uuid, $copy['branch']['id']);
        $this->assertSame('1234/CP-BVTV', $copy['specs']['registration_no']);
        $this->assertTrue($copy['track_batch']);
        $this->assertSame(['100.00', '2400.00'], array_column($copy['units'], 'price_cash'));
        $this->assertSame(['111', '222'], array_column($copy['units'], 'barcode'));
        $this->assertNotSame($a, $copy['id']);
        $this->assertSame(4, $this->platform(fn () => Product::count()));

        // Sao chép lại: mọi mã đã có nên bỏ qua hết.
        $again = $this->spa()->actingAs($owner)->postJson('/api/v1/products/copy', ['product_ids' => [$a], 'branch_id' => $branchB->uuid])->assertOk();
        $again->assertJsonPath('meta.copied', 0)->assertJsonCount(1, 'meta.skipped');
    }

    public function test_copy_respects_the_users_branch_scope(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $branchC = $this->makeBranch($tenant);
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branchA);
        $this->platform(fn () => $manager->branches()->attach($branchB->id, ['tenant_id' => $tenant->id]));
        $kg = $this->makeUnit($tenant, 'Kg');
        $inA = $this->createProduct($owner, $this->productPayload($branchA, $kg, ['sku' => 'A-1']))->assertCreated()->json('data.id');
        $inC = $this->createProduct($owner, $this->productPayload($branchC, $kg, ['sku' => 'C-1']))->assertCreated()->json('data.id');
        $copy = fn (array $ids, $to) => $this->spa()->actingAs($manager)->postJson('/api/v1/products/copy', ['product_ids' => $ids, 'branch_id' => $to->uuid]);

        $copy([$inA], $branchB)->assertOk()->assertJsonPath('meta.copied', 1);   // giữa hai chi nhánh của mình
        $copy([$inA], $branchC)->assertForbidden();                              // chi nhánh đích ngoài phạm vi
        $copy([$inC], $branchB)->assertNotFound();                               // sản phẩm nguồn ngoài phạm vi: như không tồn tại
        $this->assertSame(0, $this->platform(fn () => Product::where('branch_id', $branchC->id)->where('sku', 'A-1')->count()));
    }

    public function test_copy_validates_its_input(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $closed = $this->makeBranch($tenant, ['status' => 'inactive']);
        $kg = $this->makeUnit($tenant, 'Kg');
        $id = $this->createProduct($owner, $this->productPayload($branch, $kg))->assertCreated()->json('data.id');
        $send = fn (array $body) => $this->spa()->actingAs($owner)->postJson('/api/v1/products/copy', $body);

        $send([])->assertStatus(422)->assertJsonValidationErrors(['product_ids', 'branch_id']);
        $send(['product_ids' => [$id], 'branch_id' => $closed->uuid])->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $send(['product_ids' => [$id, $id], 'branch_id' => $branch->uuid])->assertStatus(422)->assertJsonValidationErrors('product_ids.0');
        $send(['product_ids' => ['00000000-0000-4000-8000-000000000000'], 'branch_id' => $branch->uuid])->assertNotFound();
    }

    public function test_a_closed_branch_cannot_receive_new_products(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $closed = $this->makeBranch($tenant, ['status' => 'inactive']);
        $kg = $this->makeUnit($tenant, 'Kg');

        $this->createProduct($owner, $this->productPayload($closed, $kg))->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_ids_that_are_not_uuids_are_404_not_500(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->getJson('/api/v1/products/khong-phai-uuid')->assertNotFound();
        $this->spa()->actingAs($owner)->deleteJson('/api/v1/units/123')->assertNotFound();
    }
}
