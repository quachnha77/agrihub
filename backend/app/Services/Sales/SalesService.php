<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesPayment;
use App\Models\StockDocument;
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
 * Bán hàng (quy trình đơn giản cho cửa hàng nhỏ): MỘT bước, không báo giá, không duyệt, không giao hàng riêng.
 *
 * - Bán (`sell`): MỘT transaction ghi phiếu xuất kho (qua StockService, cổng DUY NHẤT đổi tồn: tồn, lô, giá vốn, khóa kiểm kê), phiếu bán, các dòng và lần thu đầu tiên (nếu có).
 *   Sản phẩm theo dõi lô được TỰ CHỌN lô gần hết hạn trước (bỏ qua lô đã quá hạn) và chia thành nhiều dòng phiếu khi một lô không đủ; người bán không phải chọn lô.
 * - Khách trả đủ ngay thì không có nợ. Còn lại là NỢ của khách: phải có khách (khách lẻ chỉ mua trả đủ), khách đang giao dịch, có hạn mức > 0, không có phiếu quá hạn, và
 *   (tổng nợ hiện tại + nợ mới) ≤ hạn mức. Dòng khách được khóa (FOR UPDATE) để hai phiếu bán chịu cùng lúc không vượt hạn mức.
 * - Thu tiền (`collect`) và hủy một lần thu (`voidPayment`): khóa dòng phiếu để hai người cùng thu không vượt số còn nợ; `paid_amount` cập nhật trong cùng transaction.
 * Mọi lỗi dữ liệu báo bằng ValidationException (422), theo ô (`customer_id`, `paid_amount`, `lines.N.*`...).
 */
class SalesService
{
    public function __construct(private StockService $stock, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  đã kiểm tra hình dạng: `sold_on`, `note`, `discount`, `paid_amount`, `payment_method`, `lines` (product_id, unit_id, quantity, unit_price)
     */
    public function sell(Warehouse $warehouse, ?Customer $customer, array $data, ?User $by): SalesOrder
    {
        return CodeSequence::retrying(SalesOrder::class, 'BH', fn (string $code) => DB::transaction(fn () => $this->record($warehouse, $customer, $data, $by, $code)));
    }

    /** @param  array<string, mixed>  $data */
    private function record(Warehouse $warehouse, ?Customer $customer, array $data, ?User $by, string $code): SalesOrder
    {
        $errors = [];
        $today = Carbon::now(config('inventory.timezone'))->toDateString();
        $soldOn = $data['sold_on'] ?? $today;
        $rawLines = array_values($data['lines']);

        $totals = [];
        $goods = '0';

        foreach ($rawLines as $i => $raw) {
            $totals[$i] = Decimal::round(Decimal::mul(Decimal::from($raw['quantity']), Decimal::from($raw['unit_price']), 6), 2);
            $goods = Decimal::add($goods, $totals[$i]);
        }

        $goods = Decimal::round($goods, 2);
        $discount = Decimal::round(Decimal::from($data['discount'] ?? 0), 2);

        if (Decimal::cmp($discount, $goods) > 0) {
            $errors['discount'][] = sprintf('Số tiền bớt (%s) không được lớn hơn tổng tiền hàng (%s).', $this->vnd($discount), $this->vnd($goods));
        }

        $total = Decimal::cmp($discount, $goods) > 0 ? $goods : Decimal::sub($goods, $discount);
        $paid = Decimal::round(Decimal::from($data['paid_amount'] ?? $total), 2);

        if (Decimal::cmp($paid, $total) > 0) {
            $errors['paid_amount'][] = sprintf('Số tiền thu (%s) không được lớn hơn số tiền khách phải trả (%s).', $this->vnd($paid), $this->vnd($total));
        }

        $debt = Decimal::cmp($paid, $total) > 0 ? '0' : Decimal::sub($total, $paid);

        if ($customer !== null && $customer->status !== Customer::STATUS_ACTIVE) {
            $errors['customer_id'][] = 'Khách hàng này đã ngừng giao dịch.';
        }

        $this->failIfAny($errors);

        if (Decimal::cmp($debt, '0') > 0) {
            $this->guardCredit($customer, $debt, $today, $errors);
        }

        $this->failIfAny($errors);

        [$stockLines, $pieces, $origin] = $this->plan($warehouse, $rawLines, $totals, $errors);
        $this->failIfAny($errors);

        try {
            $document = $this->stock->post($warehouse, StockDocument::ISSUE, ['system_reason' => StockDocument::SALE_REASON, 'note' => 'Bán hàng '.$code, 'lines' => $stockLines], $by);
        } catch (ValidationException $e) {
            throw $this->remap($e, $origin);
        }

        $order = new SalesOrder([
            'code' => $code,
            'sold_on' => $soldOn,
            'due_on' => $customer?->credit_days !== null ? Carbon::parse($soldOn)->addDays((int) $customer->credit_days)->toDateString() : null,
            'note' => $this->blank($data['note'] ?? null),
            'goods_total' => $goods,
            'discount' => $discount,
            'total' => $total,
            // Số đã thu được ghi ngay lúc tạo (không cộng dồn sau) vì database chặn phiếu của khách lẻ chưa trả đủ; lần thu đầu tiên chỉ ghi lịch sử.
            'paid_amount' => $paid,
        ]);
        $order->branch_id = $warehouse->branch_id;
        $order->warehouse_id = $warehouse->id;
        $order->customer_id = $customer?->id;
        $order->stock_document_id = $document->id;
        $order->created_by = $by?->id;
        $order->save();

        // Phiếu xuất kho có đúng một dòng sổ kho cho mỗi dòng đã gửi, theo cùng thứ tự: lấy sản phẩm, đơn vị, lô và giá vốn thật từ đó.
        foreach ($document->movements as $i => $movement) {
            $line = new SalesOrderLine([
                'quantity' => $pieces[$i]['quantity'],
                'unit_price' => Decimal::from($pieces[$i]['unit_price']),
                'line_total' => $pieces[$i]['line_total'],
                'cost_amount' => Decimal::round(Decimal::mul(Decimal::abs((string) $movement->quantity_delta), (string) $movement->unit_cost, 6), 2),
                'sort_order' => $i,
            ]);
            $line->order_id = $order->id;
            $line->product_id = $movement->product_id;
            $line->unit_id = $movement->entered_unit_id;
            $line->batch_id = $movement->batch_id;
            $line->save();
        }

        if (Decimal::cmp($paid, '0') > 0) {
            $this->addPayment($order, $paid, $data['payment_method'] ?? SalesPayment::CASH, $soldOn, null, $by, false);
        }

        $this->audit->record('sales', 'order_created', $order, after: [
            'code' => $code, 'customer' => $customer?->code, 'warehouse' => $warehouse->code, 'lines' => count($stockLines),
            'goods_total' => $goods, 'discount' => $discount, 'total' => $total, 'paid_amount' => $paid,
        ], branchId: $warehouse->branch_id);

        return $this->loaded($order);
    }

    /** Thu thêm tiền của khách cho phiếu; không được vượt số còn nợ. */
    public function collect(SalesOrder $order, string $amount, string $method, ?string $paidOn, ?string $note, ?User $by): SalesPayment
    {
        return DB::transaction(function () use ($order, $amount, $method, $paidOn, $note, $by) {
            $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $amount = Decimal::round(Decimal::from($amount), 2);

            if (Decimal::cmp($amount, $locked->balance()) > 0) {
                throw ValidationException::withMessages(['amount' => [sprintf('Số tiền thu (%s) vượt quá số khách còn nợ của phiếu (%s).', $this->vnd($amount), $this->vnd($locked->balance()))]]);
            }

            $payment = $this->addPayment($locked, $amount, $method, $paidOn ?? Carbon::now(config('inventory.timezone'))->toDateString(), $note, $by);

            $this->audit->record('sales', 'payment_recorded', $locked, after: ['code' => $locked->code, 'amount' => $amount, 'method' => $method, 'balance' => $locked->balance()], branchId: $locked->branch_id);

            return $payment;
        });
    }

    /** Hủy một lần thu (thu nhầm): lần thu được đánh dấu hủy, số đã thu của phiếu giảm tương ứng (khách lại còn nợ). Không xóa dữ liệu. */
    public function voidPayment(SalesOrder $order, SalesPayment $payment, string $reason, ?User $by): SalesPayment
    {
        return DB::transaction(function () use ($order, $payment, $reason, $by) {
            $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $row = SalesPayment::whereKey($payment->id)->where('order_id', $locked->id)->lockForUpdate()->firstOrFail();

            if ($row->voided_at !== null) {
                throw ValidationException::withMessages(['payment' => ['Lần thu này đã được hủy trước đó.']]);
            }

            $row->forceFill(['voided_at' => now(), 'voided_by' => $by?->id, 'void_reason' => trim($reason)])->save();
            $locked->forceFill(['paid_amount' => Decimal::sub((string) $locked->paid_amount, (string) $row->amount)])->save();

            $this->audit->record('sales', 'payment_voided', $locked, after: ['code' => $locked->code, 'amount' => (string) $row->amount, 'reason' => trim($reason), 'balance' => $locked->balance()], branchId: $locked->branch_id);

            return $row;
        });
    }

    /** Nạp quan hệ để trả về chi tiết phiếu. */
    public function loaded(SalesOrder $order): SalesOrder
    {
        // `refresh` đọc lại số đã lưu (làm tròn theo cột database) để phản hồi trùng đúng với dữ liệu.
        return $order->refresh()->load(['customer', 'warehouse', 'branch', 'creator', 'stockDocument', 'lines.product.baseUnit', 'lines.unit', 'lines.batch', 'payments.creator', 'payments.voider']);
    }

    /**
     * Quy tắc bán chịu (chủ sản phẩm chốt: chặn ở máy chủ trong transaction). Khóa dòng khách rồi mới đọc nợ hiện tại.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function guardCredit(?Customer $customer, string $debt, string $today, array &$errors): void
    {
        if ($customer === null) {
            $errors['customer_id'][] = 'Bán chịu cần chọn khách hàng. Khách lẻ phải trả đủ tiền.';

            return;
        }

        $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
        $limit = (string) $locked->credit_limit;

        if (Decimal::cmp($limit, '0') === 0) {
            $errors['customer_id'][] = 'Khách này chưa được phép ghi nợ (hạn mức công nợ bằng 0): khách cần trả đủ tiền.';

            return;
        }

        $open = SalesOrder::where('customer_id', $locked->id)->whereColumn('paid_amount', '<', 'total');
        $owed = (string) (clone $open)->selectRaw('coalesce(sum(total - paid_amount), 0) as owed')->value('owed');
        $overdue = (clone $open)->whereDate('due_on', '<', $today)->orderBy('due_on')->first();

        if ($overdue !== null) {
            $errors['customer_id'][] = sprintf('Khách đang nợ quá hạn phiếu %s (hạn trả %s): thu nợ trước khi bán chịu tiếp.', $overdue->code, $overdue->due_on->format('d/m/Y'));

            return;
        }

        if (Decimal::cmp(Decimal::add($owed, $debt), $limit) > 0) {
            $errors['customer_id'][] = sprintf(
                'Vượt hạn mức công nợ: khách đang nợ %s, phiếu này ghi nợ thêm %s, hạn mức %s. Hãy thu thêm tiền ngay hoặc giảm số hàng.',
                $this->vnd(Decimal::round($owed, 2)),
                $this->vnd($debt),
                $this->vnd(Decimal::round($limit, 2)),
            );
        }
    }

    /**
     * Biến các dòng người bán nhập thành các dòng phiếu kho. Sản phẩm theo dõi lô chia theo lô gần hết hạn trước (lô đã quá hạn không bán). Trả về
     * [dòng gửi StockService, thông tin từng dòng phiếu bán (số lượng, đơn giá, thành tiền), vị trí dòng nhập gốc của từng dòng gửi].
     *
     * @param  list<array<string, mixed>>  $rawLines
     * @param  array<int, string>  $totals  thành tiền từng dòng nhập
     * @param  array<string, list<string>>  $errors
     * @return array{0: list<array<string, mixed>>, 1: list<array{quantity: string, unit_price: string, line_total: string}>, 2: list<int>}
     */
    private function plan(Warehouse $warehouse, array $rawLines, array $totals, array &$errors): array
    {
        $products = Product::with(['units.unit', 'baseUnit'])->where('branch_id', $warehouse->branch_id)
            ->whereIn('uuid', array_filter(array_column($rawLines, 'product_id')))->get()->keyBy('uuid');
        $today = Carbon::now(config('inventory.timezone'))->toDateString();

        $stockLines = [];
        $pieces = [];
        $origin = [];
        $taken = []; // [product id][batch uuid] => số lượng (đơn vị cơ sở) các dòng trước đã lấy

        foreach ($rawLines as $i => $raw) {
            $product = $products->get($raw['product_id'] ?? '');
            $row = $product?->units->first(fn ($u) => $u->unit?->uuid === ($raw['unit_id'] ?? null));
            $single = [['quantity' => (string) $raw['quantity'], 'batch' => null, 'total' => $totals[$i]]];

            // Sản phẩm / đơn vị sai, hoặc không theo dõi lô: một dòng nguyên vẹn, StockService kiểm tra và báo lỗi theo dòng.
            $split = $product !== null && $row !== null && $product->track_batch ? $this->fefo($warehouse, $product, (string) $row->factor, Decimal::from($raw['quantity']), $totals[$i], $taken, $today, $i, $errors) : $single;

            foreach ($split ?? [] as $piece) {
                $stockLines[] = array_filter(['product_id' => $raw['product_id'], 'unit_id' => $raw['unit_id'], 'quantity' => $piece['quantity'], 'batch_id' => $piece['batch']], fn ($v) => $v !== null);
                $pieces[] = ['quantity' => $piece['quantity'], 'unit_price' => (string) $raw['unit_price'], 'line_total' => $piece['total']];
                $origin[] = $i;
            }
        }

        return [$stockLines, $pieces, $origin];
    }

    /**
     * Chia số lượng cần bán của một dòng cho các lô còn hạn theo thứ tự hạn dùng tăng dần. Số lượng và thành tiền của dòng cuối nhận phần lẻ nên tổng các phần luôn khớp dòng gốc.
     *
     * @param  array<int, array<string, string>>  $taken
     * @param  array<string, list<string>>  $errors
     * @return list<array{quantity: string, batch: ?string, total: string}>|null null khi không đủ hàng (đã ghi lỗi)
     */
    private function fefo(Warehouse $warehouse, Product $product, string $factor, string $quantity, string $total, array &$taken, string $today, int $i, array &$errors): ?array
    {
        $batches = DB::table('stock_balances as b')
            ->join('batches as ba', 'ba.id', '=', 'b.batch_id')
            ->where('b.warehouse_id', $warehouse->id)->where('b.product_id', $product->id)->where('b.quantity', '>', 0)
            ->where(fn ($q) => $q->whereNull('ba.expires_at')->orWhere('ba.expires_at', '>=', $today))
            ->orderByRaw('ba.expires_at asc nulls last')->orderBy('ba.id')
            ->get(['ba.uuid', 'b.quantity']);

        $need = Decimal::mul($quantity, $factor, 3);
        $available = '0';
        $parts = [];

        foreach ($batches as $batch) {
            $free = Decimal::sub((string) $batch->quantity, $taken[$product->id][$batch->uuid] ?? '0');
            $available = Decimal::add($available, Decimal::cmp($free, '0') > 0 ? $free : '0');

            if (Decimal::cmp($need, '0') <= 0 || Decimal::cmp($free, '0') <= 0) {
                continue;
            }

            $take = Decimal::cmp($free, $need) < 0 ? $free : $need;
            $parts[] = ['batch' => $batch->uuid, 'base' => $take];
            $taken[$product->id][$batch->uuid] = Decimal::add($taken[$product->id][$batch->uuid] ?? '0', $take);
            $need = Decimal::sub($need, $take);
        }

        if (Decimal::cmp($need, '0') > 0) {
            $errors["lines.{$i}.quantity"][] = sprintf(
                'Không đủ tồn (không tính lô đã hết hạn): còn %s %s, cần %s %s.',
                $this->plain($available), $product->baseUnit->name, $this->plain(Decimal::mul($quantity, $factor, 3)), $product->baseUnit->name,
            );

            return null;
        }

        $result = [];
        $enteredLeft = $quantity;
        $totalLeft = $total;

        foreach ($parts as $n => $part) {
            $last = $n === count($parts) - 1;
            $entered = $last ? $enteredLeft : Decimal::div($part['base'], $factor, 3);
            $lineTotal = $last ? $totalLeft : Decimal::round(Decimal::mul(Decimal::div($total, $quantity, 6), $entered, 6), 2);
            $result[] = ['quantity' => $entered, 'batch' => $part['batch'], 'total' => $lineTotal];
            $enteredLeft = Decimal::sub($enteredLeft, $entered);
            $totalLeft = Decimal::sub($totalLeft, $lineTotal);
        }

        return $result;
    }

    /** Lỗi của StockService nói theo vị trí dòng gửi đi; đổi về vị trí dòng người bán đã nhập để giao diện hiện đúng ô. */
    private function remap(ValidationException $e, array $origin): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            if (preg_match('/^lines\.(\d+)\.(\w+)$/', $key, $m)) {
                $key = 'lines.'.($origin[(int) $m[1]] ?? $m[1]).'.'.$m[2];
            }

            foreach ($messages as $message) {
                if (! in_array($message, $mapped[$key] ?? [], true)) {
                    $mapped[$key][] = $message;
                }
            }
        }

        return ValidationException::withMessages($mapped);
    }

    /** @param  bool  $accumulate  false khi số đã thu của phiếu đã được ghi sẵn lúc tạo phiếu */
    private function addPayment(SalesOrder $order, string $amount, string $method, string $paidOn, ?string $note, ?User $by, bool $accumulate = true): SalesPayment
    {
        $payment = new SalesPayment(['amount' => $amount, 'method' => $method, 'paid_on' => $paidOn, 'note' => $this->blank($note)]);
        $payment->order_id = $order->id;
        $payment->created_by = $by?->id;
        $payment->save();

        if ($accumulate) {
            $order->forceFill(['paid_amount' => Decimal::add((string) $order->paid_amount, $amount)])->save();
        }

        return $payment;
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

    /** Số lượng bỏ số 0 thừa ở cuối: "12.500" → "12,5". */
    private function plain(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    }
}
