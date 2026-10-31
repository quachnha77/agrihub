<?php

namespace App\Services\Purchase;

use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockService;
use App\Support\CodeSequence;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mua hàng (chủ sản phẩm chốt 2026-11-01): chỉ có PHIẾU NHẬP HÀNG, không cần duyệt, ghi nợ + trả tiền ngay tại phiếu, chi phí kèm theo tính vào giá vốn.
 *
 * - Nhập hàng (`receive`): MỘT transaction ghi phiếu nhập kho (qua StockService, cổng DUY NHẤT đổi tồn: tồn, lô, giá vốn bình quân, khóa kiểm kê đều do đó lo), phiếu nhập hàng, các dòng
 *   và lần trả đầu tiên (nếu có). Giá vốn mỗi dòng = (thành tiền dòng + phần chi phí kèm theo phân bổ theo giá trị) / số lượng, làm tròn 4 chữ số thập phân.
 * - Tiền hàng (`goods_total`) là nợ nhà cung cấp; chi phí kèm theo chỉ vào giá vốn (cửa hàng tự trả vận chuyển...), không cộng vào nợ.
 * - Trả tiền (`pay`) và hủy một lần trả (`voidPayment`): khóa dòng phiếu (FOR UPDATE) để hai người cùng trả không vượt số còn nợ; `paid_amount` của phiếu được cập nhật trong cùng transaction.
 * Mọi lỗi dữ liệu báo bằng ValidationException (422), theo ô (`supplier_id`, `paid_amount`, `lines.N.*`...).
 */
class PurchaseService
{
    public function __construct(private StockService $stock, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  đã kiểm tra hình dạng: `received_on`, `supplier_ref`, `note`, `extra_cost`, `paid_amount`, `payment_method`, `lines` (product_id, unit_id, quantity, unit_cost, batch_no, manufactured_at, expires_at)
     */
    public function receive(Warehouse $warehouse, Supplier $supplier, array $data, ?User $by): PurchaseReceipt
    {
        return CodeSequence::retrying(PurchaseReceipt::class, 'NH', fn (string $code) => DB::transaction(fn () => $this->record($warehouse, $supplier, $data, $by, $code)));
    }

    /** @param  array<string, mixed>  $data */
    private function record(Warehouse $warehouse, Supplier $supplier, array $data, ?User $by, string $code): PurchaseReceipt
    {
        $errors = [];

        if ($supplier->status !== Supplier::STATUS_ACTIVE) {
            $errors['supplier_id'][] = 'Nhà cung cấp này đã ngừng giao dịch.';
        }

        $extra = Decimal::round(Decimal::from($data['extra_cost'] ?? 0), 2);
        $paid = Decimal::round(Decimal::from($data['paid_amount'] ?? 0), 2);
        $rawLines = array_values($data['lines']);

        $totals = [];
        $goods = '0';

        foreach ($rawLines as $i => $raw) {
            $totals[$i] = Decimal::round(Decimal::mul(Decimal::from($raw['quantity']), Decimal::from($raw['unit_cost']), 6), 2);
            $goods = Decimal::add($goods, $totals[$i]);
        }

        $goods = Decimal::round($goods, 2);

        if (Decimal::cmp($extra, '0') > 0 && Decimal::cmp($goods, '0') === 0) {
            $errors['extra_cost'][] = 'Không phân bổ được chi phí kèm theo khi tổng tiền hàng bằng 0.';
        }

        if (Decimal::cmp($paid, $goods) > 0) {
            $errors['paid_amount'][] = sprintf('Số tiền trả (%s) không được lớn hơn tổng tiền hàng (%s).', $this->vnd($paid), $this->vnd($goods));
        }

        $this->failIfAny($errors);

        $allocated = $this->allocate($extra, $totals, $goods);

        $stockLines = [];

        foreach ($rawLines as $i => $raw) {
            $stockLines[] = [
                'product_id' => $raw['product_id'],
                'unit_id' => $raw['unit_id'],
                'quantity' => $raw['quantity'],
                // Giá vốn mỗi đơn vị người dùng đã nhập, đã gồm phần chi phí kèm theo; StockService quy về đơn vị cơ sở.
                'unit_cost' => Decimal::div(Decimal::add($totals[$i], $allocated[$i]), Decimal::from($raw['quantity']), 4),
                'batch_no' => $raw['batch_no'] ?? null,
                'manufactured_at' => $raw['manufactured_at'] ?? null,
                'expires_at' => $raw['expires_at'] ?? null,
            ];
        }

        $document = $this->stock->post($warehouse, StockDocument::RECEIPT, ['note' => 'Nhập hàng '.$code.' - '.$supplier->name, 'lines' => $stockLines], $by);

        $today = Carbon::now(config('inventory.timezone'))->toDateString();
        $receipt = new PurchaseReceipt([
            'code' => $code,
            'supplier_ref' => $this->blank($data['supplier_ref'] ?? null),
            'received_on' => $data['received_on'] ?? $today,
            'note' => $this->blank($data['note'] ?? null),
            'goods_total' => $goods,
            'extra_cost' => $extra,
            'paid_amount' => 0,
        ]);
        $receipt->branch_id = $warehouse->branch_id;
        $receipt->warehouse_id = $warehouse->id;
        $receipt->supplier_id = $supplier->id;
        $receipt->stock_document_id = $document->id;
        $receipt->created_by = $by?->id;
        $receipt->save();

        // Phiếu nhập kho có đúng một dòng sổ kho cho mỗi dòng hàng, theo cùng thứ tự: lấy sản phẩm, đơn vị và lô thật từ đó.
        foreach ($document->movements as $i => $movement) {
            $line = new PurchaseReceiptLine([
                'quantity' => $rawLines[$i]['quantity'],
                'unit_cost' => Decimal::from($rawLines[$i]['unit_cost']),
                'line_total' => $totals[$i],
                'extra_allocated' => $allocated[$i],
                'sort_order' => $i,
            ]);
            $line->receipt_id = $receipt->id;
            $line->product_id = $movement->product_id;
            $line->unit_id = $movement->entered_unit_id;
            $line->batch_id = $movement->batch_id;
            $line->save();
        }

        if (Decimal::cmp($paid, '0') > 0) {
            $this->addPayment($receipt, $paid, $data['payment_method'] ?? PurchasePayment::CASH, $receipt->received_on->toDateString(), null, $by);
        }

        $this->audit->record('purchase', 'receipt_created', $receipt, after: [
            'code' => $code, 'supplier' => $supplier->code, 'warehouse' => $warehouse->code, 'lines' => count($rawLines),
            'goods_total' => $goods, 'extra_cost' => $extra, 'paid_amount' => $paid,
        ], branchId: $warehouse->branch_id);

        return $this->loaded($receipt);
    }

    /** Ghi thêm một lần trả tiền nhà cung cấp cho phiếu; không được vượt số còn nợ. */
    public function pay(PurchaseReceipt $receipt, string $amount, string $method, ?string $paidOn, ?string $note, ?User $by): PurchasePayment
    {
        return DB::transaction(function () use ($receipt, $amount, $method, $paidOn, $note, $by) {
            $locked = PurchaseReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $amount = Decimal::round(Decimal::from($amount), 2);

            if (Decimal::cmp($amount, $locked->balance()) > 0) {
                throw ValidationException::withMessages(['amount' => [sprintf('Số tiền trả (%s) vượt quá số còn nợ của phiếu (%s).', $this->vnd($amount), $this->vnd($locked->balance()))]]);
            }

            $payment = $this->addPayment($locked, $amount, $method, $paidOn ?? Carbon::now(config('inventory.timezone'))->toDateString(), $note, $by);

            $this->audit->record('purchase', 'payment_recorded', $locked, after: ['code' => $locked->code, 'amount' => $amount, 'method' => $method, 'balance' => $locked->balance()], branchId: $locked->branch_id);

            return $payment;
        });
    }

    /** Hủy một lần trả (trả nhầm): lần trả được đánh dấu hủy, số đã trả của phiếu giảm tương ứng. Không xóa dữ liệu. */
    public function voidPayment(PurchaseReceipt $receipt, PurchasePayment $payment, string $reason, ?User $by): PurchasePayment
    {
        return DB::transaction(function () use ($receipt, $payment, $reason, $by) {
            $locked = PurchaseReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $row = PurchasePayment::whereKey($payment->id)->where('receipt_id', $locked->id)->lockForUpdate()->firstOrFail();

            if ($row->voided_at !== null) {
                throw ValidationException::withMessages(['payment' => ['Lần trả này đã được hủy trước đó.']]);
            }

            $row->forceFill(['voided_at' => now(), 'voided_by' => $by?->id, 'void_reason' => trim($reason)])->save();
            $locked->forceFill(['paid_amount' => Decimal::sub((string) $locked->paid_amount, (string) $row->amount)])->save();

            $this->audit->record('purchase', 'payment_voided', $locked, after: ['code' => $locked->code, 'amount' => (string) $row->amount, 'reason' => trim($reason), 'balance' => $locked->balance()], branchId: $locked->branch_id);

            return $row;
        });
    }

    /** Nạp quan hệ để trả về chi tiết phiếu. */
    public function loaded(PurchaseReceipt $receipt): PurchaseReceipt
    {
        // `refresh` đọc lại số đã lưu (làm tròn theo cột database) để phản hồi trùng đúng với dữ liệu.
        return $receipt->refresh()->load(['supplier', 'warehouse', 'branch', 'creator', 'stockDocument', 'lines.product.baseUnit', 'lines.unit', 'lines.batch', 'payments.creator', 'payments.voider']);
    }

    private function addPayment(PurchaseReceipt $receipt, string $amount, string $method, string $paidOn, ?string $note, ?User $by): PurchasePayment
    {
        $payment = new PurchasePayment(['amount' => $amount, 'method' => $method, 'paid_on' => $paidOn, 'note' => $this->blank($note)]);
        $payment->receipt_id = $receipt->id;
        $payment->created_by = $by?->id;
        $payment->save();

        $receipt->forceFill(['paid_amount' => Decimal::add((string) $receipt->paid_amount, $amount)])->save();

        return $payment;
    }

    /**
     * Chia chi phí kèm theo cho các dòng theo tỉ lệ thành tiền, tới đồng; phần lẻ dồn vào dòng có thành tiền lớn nhất nên tổng phân bổ luôn đúng bằng chi phí.
     *
     * @param  array<int, string>  $totals  thành tiền từng dòng
     * @return array<int, string>
     */
    private function allocate(string $extra, array $totals, string $goods): array
    {
        $shares = array_fill_keys(array_keys($totals), '0');

        if (Decimal::cmp($extra, '0') === 0 || Decimal::cmp($goods, '0') === 0) {
            return $shares;
        }

        $sum = '0';
        $largest = array_key_first($totals);

        foreach ($totals as $i => $total) {
            $shares[$i] = Decimal::round(Decimal::div(Decimal::mul($extra, $total, 6), $goods, 6), 2);
            $sum = Decimal::add($sum, $shares[$i]);

            if (Decimal::cmp($total, $totals[$largest]) > 0) {
                $largest = $i;
            }
        }

        $shares[$largest] = Decimal::add($shares[$largest], Decimal::sub($extra, $sum));

        return $shares;
    }

    /** @param  array<string, list<string>>  $errors */
    private function failIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function blank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** "1250000.00" → "1.250.000 đ" cho câu thông báo. */
    private function vnd(string $value): string
    {
        return number_format((float) $value, 0, ',', '.').' đ';
    }
}
