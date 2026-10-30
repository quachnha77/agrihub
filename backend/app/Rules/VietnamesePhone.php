<?php

namespace App\Rules;

use App\Support\Auth\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Số điện thoại Việt Nam (10–11 chữ số; chấp nhận khoảng trắng, dấu chấm, gạch ngang, +84). */
class VietnamesePhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Phone::normalize(is_string($value) ? $value : null) === null) {
            $fail('Số điện thoại không hợp lệ (ví dụ 0912 345 678).');
        }
    }
}
