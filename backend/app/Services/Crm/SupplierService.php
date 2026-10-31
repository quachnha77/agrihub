<?php

namespace App\Services\Crm;

use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Services\Audit\AuditLogger;
use App\Support\CodeSequence;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Tạo / sửa / xóa nhà cung cấp (dùng chung toàn doanh nghiệp). Mã tự sinh khi không nhập; người liên hệ được thay nguyên khối và ghi audit. */
class SupplierService
{
    private const FIELDS = ['name', 'tax_code', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'status'];

    public function __construct(private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  đã kiểm tra */
    public function create(array $data): Supplier
    {
        $build = fn (string $code) => DB::transaction(function () use ($code, $data) {
            $supplier = new Supplier(Arr::only($data, self::FIELDS));
            $supplier->code = $code;
            $supplier->save();

            $this->syncContacts($supplier, $data['contacts'] ?? []);

            return $supplier->refresh()->load('contacts');
        });

        $code = $data['code'] ?? null;

        return $code !== null && $code !== '' ? $build($code) : CodeSequence::retrying(Supplier::class, 'NCC', $build);
    }

    /** @param  array<string, mixed>  $data  đã kiểm tra; chỉ các khóa được gửi mới đổi */
    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier->fill(Arr::only($data, [...self::FIELDS, 'code']))->save();

            if (array_key_exists('contacts', $data)) {
                $this->syncContacts($supplier, $data['contacts']);
            }

            return $supplier->refresh()->load('contacts');
        });
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncContacts(Supplier $supplier, array $rows): void
    {
        $snapshot = fn () => SupplierContact::where('supplier_id', $supplier->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SupplierContact $c) => ['name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'position' => $c->position])->all();
        $before = $snapshot();

        SupplierContact::where('supplier_id', $supplier->id)->delete();

        foreach (array_values($rows) as $position => $row) {
            $contact = new SupplierContact(Arr::only($row, ['name', 'phone', 'email', 'position']));
            $contact->supplier_id = $supplier->id;
            $contact->sort_order = $position;
            $contact->save();
        }

        $after = $snapshot();

        if ($before !== $after) {
            $this->audit->record('supplier', 'contacts_changed', $supplier, before: $before === [] ? null : ['contacts' => $before], after: ['contacts' => $after]);
        }
    }
}
