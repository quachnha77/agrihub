<?php

namespace App\Services\Inventory;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Support\CodeSequence;
use App\Support\Decimal;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cổng DUY NHẤT thay đổi tồn kho: ghi một phiếu kho (nhập, xuất, điều chỉnh, tồn đầu kỳ) gồm nhiều dòng trong MỘT transaction.
 * Quyền (Policy) và hình dạng dữ liệu (Request) đã kiểm tra ở lớp trên; lớp này lo tính đúng đắn của kho:
 *
 * - Chỉ GHI THÊM vào sổ kho (`stock_movements`) và cập nhật số dư dẫn xuất (`stock_balances`) trong cùng transaction.
 * - Tồn không bao giờ âm: kiểm tra trước khi ghi (báo rõ dòng nào thiếu bao nhiêu) và có CHECK trong database làm lớp cuối.
 * - Giá vốn bình quân gia quyền của sản phẩm (`product_costs`) tính lại khi nhập; xuất tính theo giá vốn hiện tại. Dòng `product_costs`
 *   được khóa (FOR UPDATE) theo thứ tự id tăng dần nên hai phiếu cùng chạm một sản phẩm luôn chạy tuần tự, không bế tắc và không sai giá vốn.
 * - Sản phẩm theo dõi lô bắt buộc khai lô: nhập khai số lô + hạn dùng (lô chưa có thì tạo), xuất/điều chỉnh giảm chọn lô đang có.
 *   Lô đã quá hạn chỉ xuất được để hủy (lý do "hết hạn").
 * - Số lượng, đơn giá quy về ĐƠN VỊ CƠ SỞ của sản phẩm bằng số thập phân chính xác (BCMath), người dùng nhập theo đơn vị nào cũng được.
 * - Kho đang kiểm kê (đợt `counting`) bị KHÓA: mọi phiếu bị từ chối, trừ phiếu điều chỉnh do chính đợt kiểm kê đó ghi khi hoàn tất (tham số `$stocktake`).
   Khóa chia sẻ trên dòng kho tuần tự hóa với `StocktakeService::start` (giữ khóa độc quyền cùng dòng) nên không phiếu nào lọt vào giữa lúc chụp tồn.
 * - Chuyển kho (`transfer`): MỘT bước, kho gửi trừ và kho nhận cộng trong cùng transaction qua hai phiếu kho đi cặp (`transfer_out` + `transfer_in`). Sản phẩm của
 *   chi nhánh nhận ghép theo mã (sku); hàng sang kho nhận theo giá vốn bình quân của kho gửi (và tính lại bình quân của sản phẩm bên nhận); lô giữ số lô + hạn dùng.
 * Mọi lỗi dữ liệu gom lại và báo một lần theo từng dòng (`lines.N.field`) bằng ValidationException (422).
 */
class StockService
{
    private const CODE_PREFIX = [
        StockDocument::OPENING => 'TD',
        StockDocument::RECEIPT => 'PN',
        StockDocument::ISSUE => 'PX',
        StockDocument::ADJUSTMENT => 'DC',
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{reason?: ?string, note?: ?string, lines: list<array<string, mixed>>}  $data  đã kiểm tra hình dạng; `product_id`, `unit_id`, `batch_id` là uuid công khai
     * @param  Stocktake|null  $stocktake  chỉ StocktakeService truyền vào khi hoàn tất đợt kiểm kê: được ghi vào kho đang bị khóa bởi CHÍNH đợt này
     */
    public function post(Warehouse $warehouse, string $type, array $data, ?User $by, ?Stocktake $stocktake = null): StockDocument
    {
        return CodeSequence::retrying(StockDocument::class, self::CODE_PREFIX[$type], fn (string $code) => DB::transaction(fn () => $this->record($warehouse, $type, $data, $by, $code, $stocktake)));
    }

    /** @param  array<string, mixed>  $data */
    private function record(Warehouse $warehouse, string $type, array $data, ?User $by, string $code, ?Stocktake $stocktake = null): StockDocument
    {
        $errors = [];

        $this->guardWarehouse($warehouse, 'warehouse_id', $stocktake, $errors);

        $reason = $data['reason'] ?? null;
        $note = isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null;
        $allowed = match ($type) {
            StockDocument::ISSUE => StockDocument::ISSUE_REASONS,
            StockDocument::ADJUSTMENT => StockDocument::ADJUSTMENT_REASONS,
            default => [],
        };

        if ($allowed !== [] && ! in_array($reason, $allowed, true)) {
            $errors['reason'][] = 'Vui lòng chọn lý do.';
        } elseif ($allowed === []) {
            $reason = null;
        }

        if ($reason === 'other' && $note === null) {
            $errors['note'][] = 'Vui lòng ghi chú cụ thể khi chọn lý do "Khác".';
        }

        $lines = $this->resolveLines($warehouse, $type, $data['lines'], $errors);
        $this->failIfAny($errors);

        // Khóa giá vốn của từng sản phẩm theo thứ tự id tăng dần (chống bế tắc) trước khi đọc bất kỳ số dư nào.
        $costs = $this->lockCosts(array_values(array_unique(array_map(fn (array $l) => $l['product']->id, $lines))), $warehouse->tenant_id);

        $document = $this->newDocument($warehouse, $type, $code, $reason, $note, null, $by, now());

        $state = [];
        $today = Carbon::now(config('inventory.timezone'))->toDateString();
        $value = '0';

        foreach ($lines as $i => $line) {
            $product = $line['product'];
            $this->primeState($state, $product, $costs);

            $batch = $this->resolveBatch($product, $type, $line, $reason, $today, $i, $errors);

            if ($batch === false) {
                continue;
            }

            // Nhập hàng: đơn giá nhập quyết định giá vốn bình quân mới; xuất / điều chỉnh đi theo giá vốn hiện tại.
            $inbound = in_array($type, [StockDocument::OPENING, StockDocument::RECEIPT], true) && ! Decimal::isNegative($line['delta']) ? $line['base_cost'] : null;
            $moved = $this->applyMovement($document, $warehouse, $product, $batch, $type, $line, $inbound, $state, $errors, "lines.{$i}.quantity");

            if ($moved !== null) {
                $value = Decimal::add($value, $moved);
            }
        }

        $this->failIfAny($errors);

        $this->saveCosts($costs, $state);

        $this->audit->record('inventory', 'stock_document_created', $document, after: [
            'code' => $code, 'type' => $type, 'warehouse' => $warehouse->code, 'reason' => $reason, 'lines' => count($lines), 'value' => Decimal::money($value),
        ], branchId: $warehouse->branch_id);

        return $document->load(['warehouse', 'branch', 'performer', 'movements.product.baseUnit', 'movements.batch', 'movements.enteredUnit']);
    }

    /**
     * Chuyển hàng từ kho này sang kho khác (cùng hoặc khác chi nhánh) trong MỘT bước: không có trạng thái "đang chuyển". Ghi hai phiếu kho đi cặp
     * (`CKxxxx-X` xuất ở kho gửi, `CKxxxx-N` nhập ở kho nhận) và một phiếu chuyển (`CKxxxx`), tất cả trong một transaction.
     *
     * - Sản phẩm trong `lines` là sản phẩm Ở KHO GỬI; khác chi nhánh thì sản phẩm bên nhận được ghép theo mã (sku), chưa có thì báo lỗi theo dòng.
     * - Hàng sang kho nhận với giá vốn bình quân hiện tại của kho gửi; bên nhận tính lại giá vốn bình quân như một lần nhập. Cùng một sản phẩm (cùng chi nhánh) thì giá vốn không đổi.
     * - Lô giữ nguyên số lô, ngày sản xuất, hạn dùng (tạo lô bên nhận nếu chưa có; lô đã có phải cùng hạn dùng). Lô quá hạn không chuyển được.
     * - Cả hai kho đều phải đang hoạt động và không bị khóa kiểm kê.
     *
     * @param  array{note?: ?string, lines: list<array<string, mixed>>}  $data  đã kiểm tra hình dạng; dòng: `product_id`, `unit_id`, `quantity`, `batch_id` (uuid công khai)
     */
    public function transfer(Warehouse $from, Warehouse $to, array $data, ?User $by): StockTransfer
    {
        return CodeSequence::retrying(StockTransfer::class, 'CK', fn (string $code) => DB::transaction(fn () => $this->recordTransfer($from, $to, $data, $by, $code)));
    }

    /** @param  array<string, mixed>  $data */
    private function recordTransfer(Warehouse $from, Warehouse $to, array $data, ?User $by, string $code): StockTransfer
    {
        $errors = [];

        $this->guardWarehouse($from, 'from_warehouse_id', null, $errors);
        $this->guardWarehouse($to, 'to_warehouse_id', null, $errors);

        if ($from->id === $to->id) {
            $errors['to_warehouse_id'][] = 'Kho nhận phải khác kho gửi.';
        }

        $note = isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null;
        $lines = $this->resolveLines($from, StockDocument::TRANSFER_OUT, $data['lines'], $errors);
        $targets = $this->resolveTargets($from, $to, $lines, $errors);
        $this->failIfAny($errors);

        // Khóa giá vốn của MỌI sản phẩm liên quan (cả hai bên) theo một thứ tự id chung: hai lần chuyển ngược chiều nhau không bao giờ bế tắc.
        $productIds = array_merge(array_map(fn (array $l) => $l['product']->id, $lines), array_map(fn (array $t) => $t['product']->id, $targets));
        $costs = $this->lockCosts(array_values(array_unique($productIds)), $from->tenant_id);

        $at = now();
        $transfer = new StockTransfer(['code' => $code, 'note' => $note, 'performed_at' => $at]);
        $transfer->from_branch_id = $from->branch_id;
        $transfer->from_warehouse_id = $from->id;
        $transfer->to_branch_id = $to->branch_id;
        $transfer->to_warehouse_id = $to->id;
        $transfer->performed_by = $by?->id;
        $transfer->save();

        $out = $this->newDocument($from, StockDocument::TRANSFER_OUT, $code.'-X', null, $note, $transfer, $by, $at);
        $in = $this->newDocument($to, StockDocument::TRANSFER_IN, $code.'-N', null, $note, $transfer, $by, $at);

        $state = [];
        $today = Carbon::now(config('inventory.timezone'))->toDateString();
        $value = '0';

        foreach ($lines as $i => $line) {
            $source = $line['product'];
            $target = $targets[$i];
            $dest = $target['product'];
            $this->primeState($state, $source, $costs);
            $this->primeState($state, $dest, $costs);

            $batch = $this->resolveBatch($source, StockDocument::TRANSFER_OUT, $line, null, $today, $i, $errors);

            if ($batch === false) {
                continue;
            }

            $moved = $this->applyMovement($out, $from, $source, $batch, StockDocument::TRANSFER_OUT, $line, null, $state, $errors, "lines.{$i}.quantity");

            if ($moved === null) {
                continue;
            }

            $cost = $state[$source->id]['avg'];
            $incoming = [
                'unit_id' => $target['unit_id'], 'factor' => $target['factor'], 'entered_quantity' => $target['entered_quantity'], 'delta' => Decimal::abs($line['delta']),
                'raw' => ['batch_no' => $batch?->batch_no, 'manufactured_at' => $batch?->manufactured_at?->toDateString(), 'expires_at' => $batch?->expires_at?->toDateString()],
            ];

            // Lỗi lô bên nhận (vd. lô đã có với hạn dùng khác) báo về ô "lô" của dòng, vì người dùng chỉ chọn lô ở kho gửi.
            $receiveErrors = [];
            $received = $this->resolveBatch($dest, StockDocument::TRANSFER_IN, $incoming, null, $today, $i, $receiveErrors);

            if ($received === false) {
                foreach ($receiveErrors as $messages) {
                    foreach ($messages as $message) {
                        $errors["lines.{$i}.batch_id"][] = 'Kho nhận: '.$message;
                    }
                }

                continue;
            }

            // Cùng một sản phẩm (chuyển giữa hai kho của một chi nhánh): giá vốn không đổi, chỉ chuyển chỗ. Khác chi nhánh: bên nhận nhập theo giá vốn của kho gửi.
            $this->applyMovement($in, $to, $dest, $received, StockDocument::TRANSFER_IN, $incoming, $dest->id === $source->id ? null : $cost, $state, $errors, "lines.{$i}.quantity");
            $value = Decimal::add($value, Decimal::abs($moved));
        }

        $this->failIfAny($errors);
        $this->saveCosts($costs, $state);

        $this->audit->record('inventory', 'stock_transfer_created', $transfer, after: [
            'code' => $code, 'from' => $from->code, 'to' => $to->code, 'to_branch' => $to->branch_id, 'lines' => count($lines), 'value' => Decimal::money($value),
        ], branchId: $from->branch_id);

        return $transfer->load([
            'fromWarehouse', 'toWarehouse', 'fromBranch', 'toBranch', 'performer', 'inDocument',
            'outDocument.movements.product.baseUnit', 'outDocument.movements.batch', 'outDocument.movements.enteredUnit',
        ]);
    }

    /**
     * Ghép từng dòng (sản phẩm ở kho gửi) với sản phẩm cùng mã ở chi nhánh nhận và chọn đơn vị ghi bên nhận: đúng đơn vị + hệ số người dùng đã nhập nếu bên nhận có,
     * không thì đơn vị cơ sở. Cùng chi nhánh thì chính sản phẩm đó.
     *
     * @param  array<int, array<string, mixed>>  $lines  kết quả của resolveLines
     * @param  array<string, list<string>>  $errors
     * @return array<int, array{product: Product, unit_id: int, factor: string, entered_quantity: string}>
     */
    private function resolveTargets(Warehouse $from, Warehouse $to, array $lines, array &$errors): array
    {
        $same = $from->branch_id === $to->branch_id;
        $skus = array_values(array_unique(array_map(fn (array $l) => mb_strtolower($l['product']->sku), $lines)));
        $found = $same || $skus === []
            ? collect()
            : Product::with(['units', 'baseUnit'])->where('branch_id', $to->branch_id)->whereIn(DB::raw('lower(sku)'), $skus)->get()->keyBy(fn (Product $p) => mb_strtolower($p->sku));
        $to->loadMissing('branch');

        $targets = [];

        foreach ($lines as $i => $line) {
            /** @var Product $source */
            $source = $line['product'];
            $dest = $same ? $source : $found->get(mb_strtolower($source->sku));

            if ($dest === null) {
                $errors["lines.{$i}.product_id"][] = sprintf('Chi nhánh nhận (%s) chưa có sản phẩm mã %s: hãy sao chép sản phẩm sang chi nhánh đó (Sản phẩm → Sao chép) rồi chuyển lại.', $to->branch->name, $source->sku);
            } elseif ($dest->base_unit_id !== $source->base_unit_id) {
                $errors["lines.{$i}.product_id"][] = sprintf('Sản phẩm %s dùng đơn vị nhỏ nhất khác nhau ở hai chi nhánh (%s và %s): không chuyển kho được.', $source->sku, $source->baseUnit->name, $dest->baseUnit->name);
            } elseif ($dest->track_batch !== $source->track_batch) {
                $errors["lines.{$i}.product_id"][] = sprintf('Sản phẩm %s theo dõi lô ở một chi nhánh nhưng không theo dõi ở chi nhánh kia: hãy đặt giống nhau ở cả hai chi nhánh rồi chuyển lại.', $source->sku);
            } else {
                $row = $dest->units->first(fn ($u) => $u->unit_id === $line['unit_id'] && Decimal::cmp((string) $u->factor, $line['factor']) === 0);
                $targets[$i] = [
                    'product' => $dest,
                    'unit_id' => $row ? $line['unit_id'] : $dest->base_unit_id,
                    'factor' => $row ? $line['factor'] : '1',
                    'entered_quantity' => $row ? $line['entered_quantity'] : Decimal::abs($line['delta']),
                ];
            }
        }

        return $targets;
    }

    /**
     * Kho phải đang hoạt động và không bị khóa bởi đợt kiểm kê nào (trừ chính đợt `$stocktake` đang hoàn tất). Lỗi ghi vào $errors[$key].
     *
     * @param  array<string, list<string>>  $errors
     */
    private function guardWarehouse(Warehouse $warehouse, string $key, ?Stocktake $stocktake, array &$errors): void
    {
        // Khóa chia sẻ dòng kho rồi mới đọc đợt kiểm kê: nếu một đợt đang bắt đầu (khóa độc quyền) thì chờ nó xong và thấy nó.
        Warehouse::whereKey($warehouse->id)->sharedLock()->first();
        $counting = Stocktake::where('warehouse_id', $warehouse->id)->where('status', Stocktake::COUNTING)->first();

        if ($counting !== null && $stocktake?->id !== $counting->id) {
            $errors[$key][] = sprintf('Kho này đang kiểm kê (%s): hoàn tất hoặc hủy đợt kiểm kê trước khi ghi phiếu kho.', $counting->code);
        }

        if ($warehouse->status !== Warehouse::STATUS_ACTIVE) {
            $errors[$key][] = 'Kho này đã ngừng hoạt động.';
        }
    }

    /** Tạo phiếu kho (chưa có dòng). `$transfer` chỉ có với hai phiếu của một lần chuyển kho. */
    private function newDocument(Warehouse $warehouse, string $type, string $code, ?string $reason, ?string $note, ?StockTransfer $transfer, ?User $by, DateTimeInterface $at): StockDocument
    {
        $document = new StockDocument(['code' => $code, 'type' => $type, 'reason' => $reason, 'note' => $note, 'performed_at' => $at]);
        $document->branch_id = $warehouse->branch_id;
        $document->warehouse_id = $warehouse->id;
        $document->transfer_id = $transfer?->id;
        $document->performed_by = $by?->id;
        $document->save();

        return $document;
    }

    /**
     * Ghi MỘT dòng: cập nhật số dư của kho + sản phẩm + lô và thêm dòng vào sổ kho. `$inboundCost` (giá vốn mỗi đơn vị cơ sở) có giá trị khi dòng là hàng NHẬP làm đổi
     * giá vốn bình quân (nhập kho, tồn đầu kỳ, chuyển kho khác sản phẩm); null thì dòng đi theo giá vốn hiện tại. Trả về giá trị dòng (số lượng có dấu × giá vốn),
     * hoặc null khi không đủ tồn (đã ghi lỗi vào $errors[$errorKey]).
     *
     * @param  array{unit_id: int, factor: string, entered_quantity: string, delta: string}  $line
     * @param  array<int, array{avg: string, total: string}>  $state  giá vốn bình quân và tổng tồn đang tính dở của từng sản phẩm
     * @param  array<string, list<string>>  $errors
     */
    private function applyMovement(StockDocument $document, Warehouse $warehouse, Product $product, ?Batch $batch, string $type, array $line, ?string $inboundCost, array &$state, array &$errors, string $errorKey): ?string
    {
        $delta = $line['delta'];
        $balance = $this->lockBalance($warehouse, $product, $batch);
        $after = Decimal::add((string) $balance->quantity, $delta);

        if (Decimal::isNegative($after)) {
            $errors[$errorKey][] = sprintf(
                'Không đủ tồn%s: còn %s %s, cần %s %s.',
                $batch ? ' của lô '.$batch->batch_no : '',
                $this->plain((string) $balance->quantity),
                $product->baseUnit->name,
                $this->plain(Decimal::abs($delta)),
                $product->baseUnit->name,
            );

            return null;
        }

        $unitCost = $state[$product->id]['avg'];

        if ($inboundCost !== null) {
            // Nhập hàng: giá vốn bình quân mới = (tồn cũ × giá cũ + nhập × giá nhập) / (tồn cũ + nhập).
            $unitCost = $inboundCost;
            $total = $state[$product->id]['total'];
            $newTotal = Decimal::add($total, $delta);
            $state[$product->id]['avg'] = Decimal::div(
                Decimal::add(Decimal::mul($total, $state[$product->id]['avg'], 6), Decimal::mul($delta, $unitCost, 6)),
                $newTotal,
                4,
            );
        }

        $state[$product->id]['total'] = Decimal::add($state[$product->id]['total'], $delta);

        $balance->forceFill(['quantity' => $after])->save();

        $movement = new StockMovement([
            'movement_type' => $type,
            'quantity_delta' => $delta,
            'balance_after' => $after,
            'unit_cost' => $unitCost,
            'entered_quantity' => $line['entered_quantity'],
            'entered_factor' => $line['factor'],
            'performed_at' => $document->performed_at,
        ]);
        $movement->branch_id = $warehouse->branch_id;
        $movement->document_id = $document->id;
        $movement->warehouse_id = $warehouse->id;
        $movement->product_id = $product->id;
        $movement->batch_id = $batch?->id;
        $movement->entered_unit_id = $line['unit_id'];
        $movement->save();

        return Decimal::mul($delta, $unitCost, 2);
    }

    /**
     * Lấy giá vốn bình quân và tổng tồn hiện có của sản phẩm làm điểm xuất phát (một lần cho mỗi sản phẩm trong một lần ghi).
     *
     * @param  array<int, array{avg: string, total: string}>  $state
     * @param  array<int, ProductCost>  $costs
     */
    private function primeState(array &$state, Product $product, array $costs): void
    {
        $state[$product->id] ??= ['avg' => (string) $costs[$product->id]->avg_cost, 'total' => $this->totalQuantity($product->id)];
    }

    /**
     * Lưu giá vốn bình quân mới của những sản phẩm có đổi.
     *
     * @param  array<int, ProductCost>  $costs
     * @param  array<int, array{avg: string, total: string}>  $state
     */
    private function saveCosts(array $costs, array $state): void
    {
        foreach ($state as $productId => $s) {
            if (Decimal::cmp((string) $costs[$productId]->avg_cost, $s['avg']) !== 0) {
                $costs[$productId]->forceFill(['avg_cost' => $s['avg']])->save();
            }
        }
    }

    /**
     * Tìm sản phẩm + đơn vị của từng dòng và quy số lượng / đơn giá về đơn vị cơ sở. Sản phẩm phải thuộc ĐÚNG chi nhánh của kho.
     *
     * @param  list<array<string, mixed>>  $rawLines
     * @param  array<string, list<string>>  $errors
     * @return array<int, array{product: Product, unit_id: int, factor: string, entered_quantity: string, delta: string, base_cost: string, raw: array<string, mixed>}>
     */
    private function resolveLines(Warehouse $warehouse, string $type, array $rawLines, array &$errors): array
    {
        $products = Product::with(['units.unit', 'baseUnit'])->where('branch_id', $warehouse->branch_id)
            ->whereIn('uuid', array_filter(array_column($rawLines, 'product_id')))->get()->keyBy('uuid');

        $lines = [];

        foreach (array_values($rawLines) as $i => $raw) {
            $product = $products->get($raw['product_id'] ?? '');

            if (! $product) {
                $errors["lines.{$i}.product_id"][] = 'Sản phẩm không có hoặc không thuộc chi nhánh của kho này.';

                continue;
            }

            $row = $product->units->first(fn ($u) => $u->unit?->uuid === ($raw['unit_id'] ?? null));

            if (! $row) {
                $errors["lines.{$i}.unit_id"][] = 'Đơn vị này không thuộc sản phẩm.';

                continue;
            }

            $factor = (string) $row->factor;
            $entered = Decimal::from($raw['quantity'] ?? 0);
            $delta = Decimal::mul($entered, $factor, 3);

            if (Decimal::cmp($delta, '0') === 0) {
                $errors["lines.{$i}.quantity"][] = 'Số lượng quá nhỏ (nhỏ hơn 0,001 đơn vị cơ sở).';

                continue;
            }

            // Phiếu xuất (và xuất chuyển kho) nhập số lượng dương nhưng làm GIẢM tồn; điều chỉnh đã mang dấu (âm = giảm).
            if (in_array($type, [StockDocument::ISSUE, StockDocument::TRANSFER_OUT], true)) {
                $delta = Decimal::negate($delta);
            }

            $baseCost = '0';

            if (in_array($type, [StockDocument::OPENING, StockDocument::RECEIPT], true)) {
                // Đơn giá nhập theo đơn vị người dùng chọn (vd. 750.000 đ/Bao) → giá vốn mỗi đơn vị cơ sở.
                $baseCost = Decimal::div(Decimal::from($raw['unit_cost'] ?? 0), $factor, 4);
            }

            $lines[$i] = [
                'product' => $product,
                'unit_id' => $row->unit_id,
                'factor' => $factor,
                'entered_quantity' => Decimal::abs($entered),
                'delta' => $delta,
                'base_cost' => $baseCost,
                'raw' => $raw,
            ];
        }

        return $lines;
    }

    /**
     * Xác định lô của dòng (tạo lô mới khi nhập). Trả về Batch, null (sản phẩm không theo dõi lô) hoặc false khi có lỗi (đã ghi vào $errors).
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, list<string>>  $errors
     */
    private function resolveBatch(Product $product, string $type, array $line, ?string $reason, string $today, int $i, array &$errors): Batch|null|false
    {
        $raw = $line['raw'];
        $uuid = $this->blank($raw['batch_id'] ?? null);
        $no = $this->blank($raw['batch_no'] ?? null);
        $expires = $this->blank($raw['expires_at'] ?? null);
        $makes = $this->blank($raw['manufactured_at'] ?? null);

        if (! $product->track_batch) {
            if ($uuid !== null || $no !== null || $expires !== null) {
                $errors["lines.{$i}.batch_id"][] = 'Sản phẩm này không theo dõi lô.';

                return false;
            }

            return null;
        }

        $incoming = ! Decimal::isNegative($line['delta']);

        if ($uuid !== null) {
            $batch = Batch::where('product_id', $product->id)->where('uuid', $uuid)->first();

            if (! $batch) {
                $errors["lines.{$i}.batch_id"][] = 'Lô không có hoặc không thuộc sản phẩm này.';

                return false;
            }
        } elseif ($incoming) {
            if ($no === null) {
                $errors["lines.{$i}.batch_no"][] = 'Sản phẩm này theo dõi lô: vui lòng nhập số lô.';
            }

            if ($expires === null) {
                $errors["lines.{$i}.expires_at"][] = 'Sản phẩm này theo dõi lô: vui lòng nhập hạn dùng.';
            }

            if ($no === null || $expires === null) {
                return false;
            }

            $batch = Batch::where('product_id', $product->id)->whereRaw('lower(batch_no) = ?', [mb_strtolower($no)])->first();

            if ($batch && $batch->expires_at?->toDateString() !== Carbon::parse($expires)->toDateString()) {
                $errors["lines.{$i}.expires_at"][] = sprintf('Lô %s đã có với hạn dùng %s: không nhập hạn dùng khác.', $batch->batch_no, $batch->expires_at?->format('d/m/Y') ?? 'không rõ');

                return false;
            }

            if (! $batch) {
                if ($makes !== null && Carbon::parse($makes)->gt(Carbon::parse($expires))) {
                    $errors["lines.{$i}.manufactured_at"][] = 'Ngày sản xuất phải trước hạn dùng.';

                    return false;
                }

                $batch = new Batch(['batch_no' => $no, 'manufactured_at' => $makes, 'expires_at' => $expires]);
                $batch->product_id = $product->id;
                $batch->save();
            }
        } else {
            $errors["lines.{$i}.batch_id"][] = 'Vui lòng chọn lô cần xuất.';

            return false;
        }

        // Lô quá hạn không chuyển kho được (chuyển hàng hỏng đi nơi khác là vô nghĩa): phải xuất để hủy trước.
        if ($type === StockDocument::TRANSFER_OUT && $batch->expires_at !== null && $batch->expires_at->toDateString() < $today) {
            $errors["lines.{$i}.batch_id"][] = sprintf('Lô %s đã hết hạn ngày %s: không chuyển kho được, hãy xuất kho để hủy (lý do "Hết hạn").', $batch->batch_no, $batch->expires_at->format('d/m/Y'));

            return false;
        }

        // Lô quá hạn chỉ được xuất để hủy; điều chỉnh giảm thì luôn được (để ghi giảm hàng hết hạn khỏi sổ).
        if ($type === StockDocument::ISSUE && $reason !== 'expired' && $batch->expires_at !== null && $batch->expires_at->toDateString() < $today) {
            $errors["lines.{$i}.batch_id"][] = sprintf('Lô %s đã hết hạn ngày %s: chỉ xuất được để hủy (chọn lý do "Hết hạn").', $batch->batch_no, $batch->expires_at->format('d/m/Y'));

            return false;
        }

        return $batch;
    }

    /**
     * Khóa dòng giá vốn của các sản phẩm (tạo nếu chưa có), theo thứ tự id tăng dần.
     *
     * @param  list<int>  $productIds
     * @return array<int, ProductCost>
     */
    private function lockCosts(array $productIds, int $tenantId): array
    {
        sort($productIds);
        $costs = [];

        foreach ($productIds as $id) {
            $cost = ProductCost::where('product_id', $id)->lockForUpdate()->first();

            if (! $cost) {
                // Hai phiếu đầu tiên của cùng sản phẩm có thể cùng tạo dòng này: bên thua bỏ qua rồi khóa dòng của bên thắng.
                DB::table('product_costs')->insertOrIgnore(['tenant_id' => $tenantId, 'product_id' => $id, 'avg_cost' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $cost = ProductCost::where('product_id', $id)->lockForUpdate()->firstOrFail();
            }

            $costs[$id] = $cost;
        }

        return $costs;
    }

    /** Khóa (và tạo nếu chưa có) dòng số dư của kho + sản phẩm + lô. */
    private function lockBalance(Warehouse $warehouse, Product $product, ?Batch $batch): StockBalance
    {
        $find = fn () => StockBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
            ->when($batch, fn ($q) => $q->where('batch_id', $batch->id), fn ($q) => $q->whereNull('batch_id'))->lockForUpdate()->first();

        $balance = $find();

        if (! $balance) {
            DB::table('stock_balances')->insertOrIgnore([
                'tenant_id' => $warehouse->tenant_id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'product_id' => $product->id, 'batch_id' => $batch?->id, 'quantity' => 0, 'updated_at' => now(),
            ]);
            $balance = $find();
        }

        return $balance;
    }

    /** Tổng tồn của sản phẩm ở mọi kho và lô (đơn vị cơ sở). */
    private function totalQuantity(int $productId): string
    {
        return (string) (StockBalance::where('product_id', $productId)->sum('quantity') ?: '0');
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

    /** "12.500" → "12,5": bỏ số 0 thừa cho câu thông báo dễ đọc. */
    private function plain(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return str_replace('.', ',', $value === '' ? '0' : $value);
    }
}
