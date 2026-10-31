<?php

namespace App\Services\Crm;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\CustomerGroup;
use App\Services\Audit\AuditLogger;
use App\Support\CodeSequence;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Tạo / sửa / xóa khách hàng. Quyền (Policy) và kiểm tra dữ liệu (Request) đã làm ở lớp trên; lớp này lo tính nhất quán:
 * mọi thay đổi nằm trong MỘT transaction, mã tự sinh khi không nhập, danh sách địa chỉ / người liên hệ được thay nguyên khối
 * (có đúng một địa chỉ mặc định) và việc đổi chi nhánh phụ trách, hạn mức công nợ, địa chỉ, người liên hệ đều ghi audit.
 */
class CustomerService
{
    private const FIELDS = ['name', 'phone', 'email', 'tax_code', 'credit_limit', 'credit_days', 'notes', 'status'];

    public function __construct(private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  đã kiểm tra; các khóa `*_id` là uuid công khai */
    public function create(Branch $branch, array $data): Customer
    {
        $build = function (string $code) use ($branch, $data) {
            return DB::transaction(function () use ($code, $branch, $data) {
                $customer = new Customer(Arr::only($data, self::FIELDS));
                $customer->code = $code;
                $customer->type = $data['type'];
                $customer->branch_id = $branch->id;
                $customer->group_id = $this->groupId($data['group_id'] ?? null);
                $customer->save();

                $this->syncAddresses($customer, $data['addresses'] ?? []);
                $this->syncContacts($customer, $data['contacts'] ?? []);

                return $this->fresh($customer);
            });
        };

        $code = $data['code'] ?? null;

        return $code !== null && $code !== '' ? $build($code) : CodeSequence::retrying(Customer::class, 'KH', $build);
    }

    /**
     * @param  array<string, mixed>  $data  đã kiểm tra; chỉ các khóa được gửi mới đổi
     * @param  Branch|null  $newBranch  chi nhánh phụ trách mới (đã kiểm tra quyền ở controller) khi có đổi
     */
    public function update(Customer $customer, array $data, ?Branch $newBranch = null): Customer
    {
        return DB::transaction(function () use ($customer, $data, $newBranch) {
            $oldBranch = $customer->branch_id;
            $customer->fill(Arr::only($data, [...self::FIELDS, 'code']));

            if ($newBranch !== null) {
                $customer->branch_id = $newBranch->id;
            }

            if (array_key_exists('type', $data)) {
                $customer->type = $data['type'];
            }

            if (array_key_exists('group_id', $data)) {
                $customer->group_id = $this->groupId($data['group_id']);
            }

            $customer->save();

            if ($newBranch !== null && $newBranch->id !== $oldBranch) {
                $this->audit->record('customer', 'branch_changed', $customer, before: ['branch_id' => $oldBranch], after: ['branch_id' => $newBranch->id]);
            }

            if (array_key_exists('addresses', $data)) {
                $this->syncAddresses($customer, $data['addresses']);
            }

            if (array_key_exists('contacts', $data)) {
                $this->syncContacts($customer, $data['contacts']);
            }

            return $this->fresh($customer);
        });
    }

    /** Xóa mềm: mã được nhả ra (chỉ mục chỉ tính khách chưa xóa); lịch sử mua và công nợ về sau vẫn tham chiếu được. */
    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    /**
     * Thay nguyên danh sách địa chỉ. Luôn có đúng một địa chỉ mặc định (địa chỉ đầu tiên nếu không ai được đánh dấu).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncAddresses(Customer $customer, array $rows): void
    {
        $before = $this->addressSnapshot($customer);

        CustomerAddress::where('customer_id', $customer->id)->delete();

        $hasDefault = collect($rows)->contains(fn ($row) => ! empty($row['is_default']));

        foreach (array_values($rows) as $position => $row) {
            $address = new CustomerAddress(Arr::only($row, ['label', 'address', 'ward', 'district', 'province']));
            $address->customer_id = $customer->id;
            $address->sort_order = $position;
            $address->is_default = $hasDefault ? ! empty($row['is_default']) : $position === 0;
            $address->save();
        }

        $after = $this->addressSnapshot($customer);

        if ($before !== $after) {
            $this->audit->record('customer', 'addresses_changed', $customer, before: $before === [] ? null : ['addresses' => $before], after: ['addresses' => $after]);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncContacts(Customer $customer, array $rows): void
    {
        $before = $this->contactSnapshot($customer);

        CustomerContact::where('customer_id', $customer->id)->delete();

        foreach (array_values($rows) as $position => $row) {
            $contact = new CustomerContact(Arr::only($row, ['name', 'phone', 'position', 'note']));
            $contact->customer_id = $customer->id;
            $contact->sort_order = $position;
            $contact->save();
        }

        $after = $this->contactSnapshot($customer);

        if ($before !== $after) {
            $this->audit->record('customer', 'contacts_changed', $customer, before: $before === [] ? null : ['contacts' => $before], after: ['contacts' => $after]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function addressSnapshot(Customer $customer): array
    {
        return CustomerAddress::where('customer_id', $customer->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (CustomerAddress $a) => ['label' => $a->label, 'address' => $a->address, 'ward' => $a->ward, 'district' => $a->district, 'province' => $a->province, 'is_default' => $a->is_default])->all();
    }

    /** @return list<array<string, mixed>> */
    private function contactSnapshot(Customer $customer): array
    {
        return CustomerContact::where('customer_id', $customer->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (CustomerContact $c) => ['name' => $c->name, 'phone' => $c->phone, 'position' => $c->position, 'note' => $c->note])->all();
    }

    private function groupId(?string $uuid): ?int
    {
        return $uuid === null ? null : CustomerGroup::where('uuid', $uuid)->value('id');
    }

    private function fresh(Customer $customer): Customer
    {
        return $customer->refresh()->load(['branch', 'group', 'addresses', 'contacts']);
    }
}
