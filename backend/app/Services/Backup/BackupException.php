<?php

namespace App\Services\Backup;

use RuntimeException;

/** File sao lưu không hợp lệ / hỏng / không thuộc Tenant này. Thông điệp an toàn để hiển thị cho người dùng. */
class BackupException extends RuntimeException {}
