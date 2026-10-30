<?php

namespace App\Services\Backup;

use JsonException;

/** Đọc phần đầu của nội dung dump (tiêu đề + manifest) từ luồng các đoạn dữ liệu; phần còn lại là kho pg_dump. */
class DumpHeaderReader
{
    private string $buffer = '';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** Nạp một đoạn; trả về phần dữ liệu pg_dump đã nằm sau manifest (rỗng nếu manifest chưa đọc xong). */
    public function push(string $chunk): string
    {
        if ($this->manifest !== null) {
            return $chunk;
        }

        $this->buffer .= $chunk;
        $headerLength = strlen(PlatformDump::HEADER);

        if (strlen($this->buffer) >= $headerLength && ! str_starts_with($this->buffer, PlatformDump::HEADER)) {
            throw new BackupException('File này không phải bản sao lưu toàn hệ thống.');
        }

        $newline = strpos($this->buffer, "\n", $headerLength);

        if ($newline === false) {
            if (strlen($this->buffer) > PlatformDump::MAX_MANIFEST_BYTES) {
                throw new BackupException('Phần mô tả của bản sao lưu không hợp lệ.');
            }

            return '';
        }

        try {
            $decoded = json_decode(substr($this->buffer, $headerLength, $newline - $headerLength), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupException('Phần mô tả của bản sao lưu không hợp lệ.');
        }

        if (! is_array($decoded) || ($decoded['format'] ?? null) !== 1 || ! is_array($decoded['row_counts'] ?? null)) {
            throw new BackupException('Phiên bản bản sao lưu không được hỗ trợ.');
        }

        $this->manifest = $decoded;
        $rest = substr($this->buffer, $newline + 1);
        $this->buffer = '';

        return $rest;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest ?? throw new BackupException('File sao lưu không có phần mô tả.');
    }
}
