<?php

namespace App\Services\Audit;

use DateTimeInterface;
use JsonSerializable;
use Stringable;

/**
 * Làm sạch dữ liệu trước khi ghi audit log: che mật khẩu/token/khóa bí mật, cắt chuỗi quá dài,
 * giới hạn độ sâu và loại ký tự NUL (PostgreSQL jsonb không chấp nhận).
 */
class Redactor
{
    public const MASK = '[REDACTED]';

    public function isSensitiveKey(string|int $key): bool
    {
        return is_string($key) && preg_match(config('audit.sensitive_key_pattern'), $key) === 1;
    }

    public function redact(mixed $value, string|int|null $key = null, int $depth = 0): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::MASK;
        }

        if ($depth > (int) config('audit.max_depth')) {
            return '[quá sâu]';
        }

        return match (true) {
            is_array($value) => collect($value)->map(fn ($v, $k) => $this->redact($v, $k, $depth + 1))->all(),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof JsonSerializable => $this->redact($value->jsonSerialize(), null, $depth + 1),
            $value instanceof Stringable => $this->string((string) $value),
            is_string($value) => $this->string($value),
            is_scalar($value) || $value === null => $value,
            is_object($value) => '['.class_basename($value).']',
            default => null,
        };
    }

    private function string(string $value): string
    {
        $value = str_replace("\0", '', $value);
        $max = (int) config('audit.max_string_length');

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…[đã cắt bớt]' : $value;
    }
}
