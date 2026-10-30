<?php

namespace App\Http;

use RuntimeException;

/**
 * Lỗi nghiệp vụ trả về client theo RFC 7807 (xem Problem). Ném từ service/controller; bootstrap/app.php render thành JSON.
 * Nếu cần để lại dấu vết (vd. audit "denied") phải ghi TRƯỚC khi ném và NGOÀI transaction sẽ bị rollback.
 */
class ApiProblemException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        string $title,
        public readonly array $extra = [],
    ) {
        parent::__construct($title);
    }
}
