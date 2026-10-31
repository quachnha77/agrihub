<?php

namespace App\Support\Catalog;

use Closure;
use Illuminate\Validation\Rule;

/**
 * Loại hàng và thông tin đặc thù của từng loại (lưu trong `products.specs`). Đây là nguồn duy nhất: API kiểm tra dữ liệu theo
 * định nghĩa này và gửi nó cho giao diện (`GET /products/types`) để dựng đúng form cho từng loại.
 *
 * Trường: key => [label, type (text|integer|number|select), required, max (độ dài chuỗi), min/maxValue (giá trị số), options (select)].
 */
final class ProductType
{
    public const FERTILIZER = 'fertilizer';

    public const PESTICIDE = 'pesticide';

    public const SEED = 'seed';

    public const OTHER = 'other';

    /**
     * @return array<string, array{label: string, track_batch_required: bool, fields: array<string, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            self::FERTILIZER => [
                'label' => 'Phân bón',
                'track_batch_required' => false,
                'fields' => [
                    'composition' => ['label' => 'Thành phần / hàm lượng', 'type' => 'text', 'required' => false, 'max' => 255],
                    'publication_no' => ['label' => 'Số công bố / quyết định lưu hành', 'type' => 'text', 'required' => false, 'max' => 100],
                    'origin' => ['label' => 'Xuất xứ', 'type' => 'text', 'required' => false, 'max' => 100],
                ],
            ],
            self::PESTICIDE => [
                'label' => 'Thuốc bảo vệ thực vật',
                'track_batch_required' => true,
                'fields' => [
                    'registration_no' => ['label' => 'Số đăng ký lưu hành', 'type' => 'text', 'required' => true, 'max' => 100],
                    'active_ingredient' => ['label' => 'Hoạt chất', 'type' => 'text', 'required' => true, 'max' => 255],
                    'toxicity_group' => ['label' => 'Nhóm độc', 'type' => 'select', 'required' => false, 'options' => ['I', 'II', 'III', 'IV']],
                    'phi_days' => ['label' => 'Thời gian cách ly (ngày)', 'type' => 'integer', 'required' => false, 'min' => 0, 'maxValue' => 365],
                ],
            ],
            self::SEED => [
                'label' => 'Hạt giống',
                'track_batch_required' => true,
                'fields' => [
                    'variety' => ['label' => 'Tên giống', 'type' => 'text', 'required' => true, 'max' => 255],
                    'germination_rate' => ['label' => 'Tỷ lệ nảy mầm (%)', 'type' => 'number', 'required' => false, 'min' => 0, 'maxValue' => 100],
                    'origin' => ['label' => 'Nguồn gốc / xuất xứ', 'type' => 'text', 'required' => false, 'max' => 100],
                ],
            ],
            self::OTHER => [
                'label' => 'Vật tư khác',
                'track_batch_required' => false,
                'fields' => [],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? $type;
    }

    public static function requiresBatchTracking(string $type): bool
    {
        return self::all()[$type]['track_batch_required'] ?? false;
    }

    /**
     * Luật kiểm tra cho `specs` của một loại hàng: chỉ nhận đúng các khóa đã định nghĩa (khóa lạ bị từ chối).
     * Chỉ dùng khi `specs` đang được ghi (tạo mới, hoặc sửa mà có gửi `specs`): nó ghi đè nguyên khối nên các trường bắt buộc
     * phải có đủ, không được âm thầm làm mất số đăng ký đã lưu.
     *
     * @return array<string, list<mixed>> khóa dạng `specs.<tên>` (để gộp thẳng vào rules() của request)
     */
    public static function rules(string $type): array
    {
        $fields = self::all()[$type]['fields'] ?? [];
        $hasRequired = array_filter($fields, fn (array $field) => $field['required']) !== [];

        $rules = ['specs' => [$hasRequired ? 'required' : 'nullable', 'array']];
        $known = [];

        foreach ($fields as $key => $field) {
            $known[] = $key;
            $rule = [$field['required'] ? 'required' : 'nullable'];

            if ($field['required']) {
                $rule[] = 'filled';
            }

            $rule = array_merge($rule, match ($field['type']) {
                'text' => ['string', 'max:'.$field['max']],
                'integer' => ['integer', 'min:'.$field['min'], 'max:'.$field['maxValue']],
                'number' => ['numeric', 'min:'.$field['min'], 'max:'.$field['maxValue']],
                'select' => [Rule::in($field['options'])],
            });

            $rules["specs.{$key}"] = $rule;
        }

        // Khóa không thuộc định nghĩa của loại này không được lưu (tránh nhét dữ liệu tùy ý vào JSONB).
        $rules['specs'][] = function (string $attribute, mixed $value, Closure $fail) use ($known) {
            $unknown = is_array($value) ? array_diff(array_keys($value), $known) : [];

            if ($unknown !== []) {
                $fail('Thông tin không áp dụng cho loại hàng này: '.implode(', ', $unknown).'.');
            }
        };

        return $rules;
    }

    /**
     * Làm sạch `specs` đã kiểm tra: bỏ ô trống, cắt khoảng trắng, ép kiểu số. Chỉ giữ khóa đã định nghĩa.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(string $type, array $input): array
    {
        $clean = [];

        foreach (self::all()[$type]['fields'] ?? [] as $key => $field) {
            $value = $input[$key] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                continue;
            }

            $clean[$key] = match ($field['type']) {
                'integer' => (int) $value,
                'number' => $value + 0,
                default => $value,
            };
        }

        return $clean;
    }

    /** @return list<array<string, mixed>> mô tả gửi cho giao diện */
    public static function describe(): array
    {
        $out = [];

        foreach (self::all() as $key => $type) {
            $fields = [];

            foreach ($type['fields'] as $name => $field) {
                $fields[] = ['key' => $name] + $field;
            }

            $out[] = ['key' => $key, 'label' => $type['label'], 'track_batch_required' => $type['track_batch_required'], 'fields' => $fields];
        }

        return $out;
    }
}
