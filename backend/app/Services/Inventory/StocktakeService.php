<?php

namespace App\Services\Inventory;

use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Support\CodeSequence;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kiểm kê kho: bắt đầu một đợt (khóa kho + chụp tồn), ghi số đếm từng dòng, hoàn tất (ghi chênh lệch bằng MỘT phiếu điều chỉnh) hoặc hủy.
 *
 * - KHÔNG sửa tồn trực tiếp: chênh lệch luôn đi qua `StockService::post` (phiếu điều chỉnh lý do `count_correction`) nên sổ kho bất biến, tồn không
 *   âm, giá vốn và lô vẫn do một cổng duy nhất lo. Chênh lệch tính theo tồn HIỆN TẠI của kho (kho bị khóa nên khớp với tồn lúc chụp).
 * - Khóa kho: `start` giữ khóa độc quyền dòng kho; StockService giữ khóa chia sẻ cùng dòng khi ghi phiếu và từ chối nếu kho đang có đợt `counting`.
 * - Không cần duyệt (chủ sản phẩm chọn 2026-10-31): hoàn tất là sửa tồn luôn. Dòng chưa đếm giữ nguyên tồn.
 * - Mọi thao tác trên đợt khóa dòng của đợt (FOR UPDATE) nên hai yêu cầu cùng lúc (hoàn tất + hủy, hai lần hoàn tất) chạy tuần tự, bên sau thấy đợt đã đóng.
 */
class StocktakeService
{
    public const NOTE_PREFIX = 'Kiểm kê ';

    public function __construct(private StockService $stock, private AuditLogger $audit) {}

    /** Bắt đầu một đợt kiểm kê cho kho: chụp mọi dòng tồn còn > 0 (theo từng lô) của sản phẩm chưa xóa thành dòng đếm. */
    public function start(Warehouse $warehouse, ?string $note, ?User $by): Stocktake
    {
        return CodeSequence::retrying(Stocktake::class, 'KK', fn (string $code) => DB::transaction(function () use ($warehouse, $note, $by, $code) {
            // Khóa độc quyền dòng kho: đợi mọi phiếu đang ghi vào kho này xong, và chặn phiếu mới cho tới khi đợt kiểm kê được ghi nhận.
            $locked = Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $errors = [];

            if ($locked->status !== Warehouse::STATUS_ACTIVE) {
                $errors['warehouse_id'][] = 'Kho này đã ngừng hoạt động.';
            }

            $open = Stocktake::where('warehouse_id', $locked->id)->where('status', Stocktake::COUNTING)->first();

            if ($open !== null) {
                $errors['warehouse_id'][] = sprintf('Kho này đang có đợt kiểm kê chưa kết thúc (%s).', $open->code);
            }

            $this->failIfAny($errors);

            $stocktake = new Stocktake(['code' => $code, 'status' => Stocktake::COUNTING, 'note' => $this->blank($note), 'started_at' => now()]);
            $stocktake->branch_id = $locked->branch_id;
            $stocktake->warehouse_id = $locked->id;
            $stocktake->started_by = $by?->id;
            $stocktake->save();

            DB::insert(
                'INSERT INTO stocktake_lines (tenant_id, stocktake_id, product_id, batch_id, system_quantity, created_at, updated_at)
                 SELECT b.tenant_id, ?, b.product_id, b.batch_id, b.quantity, now(), now()
                 FROM stock_balances b JOIN products p ON p.id = b.product_id AND p.deleted_at IS NULL
                 WHERE b.warehouse_id = ? AND b.quantity > 0',
                [$stocktake->id, $locked->id],
            );

            $this->audit->record('inventory', 'stocktake_started', $stocktake, after: [
                'code' => $code, 'warehouse' => $locked->code, 'lines' => $stocktake->lines()->count(),
            ], branchId: $locked->branch_id);

            return $stocktake->load(['warehouse', 'branch', 'starter']);
        }));
    }

    /**
     * Ghi số đếm của một dòng. `$quantity` = null là xóa số đếm (đưa dòng về "chưa đếm"). Số đếm nhập theo ĐƠN VỊ người dùng chọn (`$unitUuid`)
     * và được quy về đơn vị cơ sở; đếm 0 là hợp lệ (hết hàng). Chỉ đợt đang đếm mới ghi được.
     */
    public function count(Stocktake $stocktake, StocktakeLine $line, ?string $unitUuid, mixed $quantity, ?User $by): StocktakeLine
    {
        return DB::transaction(function () use ($stocktake, $line, $unitUuid, $quantity, $by) {
            $this->lockCounting($stocktake);

            if ($quantity === null) {
                $line->forceFill([
                    'counted_quantity' => null, 'counted_unit_id' => null, 'counted_entered_quantity' => null, 'counted_factor' => null, 'counted_by' => null, 'counted_at' => null,
                ])->save();

                return $line;
            }

            $line->loadMissing('product.units.unit');
            $row = $line->product->units->first(fn ($u) => $u->unit?->uuid === $unitUuid);

            if (! $row) {
                throw ValidationException::withMessages(['unit_id' => ['Đơn vị này không thuộc sản phẩm.']]);
            }

            $entered = Decimal::round(Decimal::from($quantity), 3);
            $factor = (string) $row->factor;

            $line->forceFill([
                'counted_quantity' => Decimal::mul($entered, $factor, 3),
                'counted_unit_id' => $row->unit_id,
                'counted_entered_quantity' => $entered,
                'counted_factor' => $factor,
                'counted_by' => $by?->id,
                'counted_at' => now(),
            ])->save();

            return $line;
        });
    }

    /**
     * Hoàn tất: các dòng đã đếm mà khác tồn hiện tại được ghi bằng MỘT phiếu điều chỉnh (lý do `count_correction`, tăng/giảm theo dấu của chênh lệch,
     * theo từng lô). Không có chênh lệch thì không tạo phiếu. Dòng chưa đếm giữ nguyên. Đợt chuyển sang `completed` và kho được mở khóa trong cùng transaction.
     */
    public function complete(Stocktake $stocktake, ?User $by): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $by) {
            $locked = $this->lockCounting($stocktake);
            $warehouse = Warehouse::findOrFail($locked->warehouse_id);

            $balances = StockBalance::where('warehouse_id', $warehouse->id)->get()->keyBy(fn (StockBalance $b) => $b->product_id.':'.($b->batch_id ?? 0));
            $adjust = [];
            $counted = 0;

            // Sản phẩm bị xóa khỏi danh mục trong lúc đang đếm thì bỏ qua dòng đó (không điều chỉnh được nữa), tồn của nó giữ nguyên.
            foreach ($locked->lines()->whereNotNull('counted_quantity')->whereHas('product')->with(['product.baseUnit', 'batch'])->orderBy('id')->get() as $line) {
                $counted++;
                $current = (string) ($balances->get($line->product_id.':'.($line->batch_id ?? 0))?->quantity ?? '0');
                $difference = Decimal::sub((string) $line->counted_quantity, $current);

                if (Decimal::cmp($difference, '0') === 0) {
                    continue;
                }

                $adjust[] = [
                    'product_id' => $line->product->uuid,
                    'unit_id' => $line->product->baseUnit->uuid,
                    'quantity' => $difference,
                    'batch_id' => $line->batch?->uuid,
                ];
            }

            $document = $adjust === []
                ? null
                : $this->stock->post($warehouse, StockDocument::ADJUSTMENT, ['reason' => 'count_correction', 'note' => self::NOTE_PREFIX.$locked->code, 'lines' => $adjust], $by, $locked);

            $locked->forceFill([
                'status' => Stocktake::COMPLETED,
                'finished_by' => $by?->id,
                'finished_at' => now(),
                'adjustment_document_id' => $document?->id,
            ])->save();

            $this->audit->record('inventory', 'stocktake_completed', $locked, after: [
                'code' => $locked->code, 'warehouse' => $warehouse->code, 'counted' => $counted, 'adjusted' => count($adjust), 'document' => $document?->code,
            ], branchId: $locked->branch_id);

            return $locked->load(['warehouse', 'branch', 'starter', 'finisher', 'adjustmentDocument']);
        });
    }

    /** Hủy đợt đang đếm: tồn không đổi, kho được mở khóa. */
    public function cancel(Stocktake $stocktake, ?User $by): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $by) {
            $locked = $this->lockCounting($stocktake);

            $locked->forceFill(['status' => Stocktake::CANCELLED, 'finished_by' => $by?->id, 'finished_at' => now()])->save();

            $this->audit->record('inventory', 'stocktake_cancelled', $locked, after: ['code' => $locked->code], branchId: $locked->branch_id);

            return $locked->load(['warehouse', 'branch', 'starter', 'finisher']);
        });
    }

    /** Khóa dòng của đợt (FOR UPDATE) và bảo đảm đợt vẫn đang đếm; bên đến sau thấy đợt đã đóng. */
    private function lockCounting(Stocktake $stocktake): Stocktake
    {
        $locked = Stocktake::whereKey($stocktake->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== Stocktake::COUNTING) {
            throw ValidationException::withMessages(['stocktake' => [sprintf('Đợt kiểm kê %s đã %s, không thay đổi được nữa.', $locked->code, $locked->status === Stocktake::COMPLETED ? 'hoàn tất' : 'hủy')]]);
        }

        return $locked;
    }

    /** @param  array<string, list<string>>  $errors */
    private function failIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function blank(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
