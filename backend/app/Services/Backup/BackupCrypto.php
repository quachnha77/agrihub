<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Mã hóa + xác thực file sao lưu theo luồng (libsodium secretstream, XChaCha20-Poly1305).
 *
 * Khuôn dạng: MAGIC | header(24) | { độ dài khối (uint32 BE) | khối mã hóa }...  Khối cuối mang thẻ FINAL.
 * Mỗi khối được xác thực và gắn thứ tự: sửa/đảo/cắt bớt/ghép khối hoặc sai khóa đều bị phát hiện, nên một file
 * giải mã thành công chắc chắn do AgriHub (giữ khóa) tạo ra và còn nguyên vẹn.
 */
class BackupCrypto
{
    public const MAGIC = "AGRIHUB-BACKUP\x01\n";

    private const CHUNK = 65536;

    public function key(): string
    {
        $secret = config('backup.encryption_key') ?: config('app.key');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Chưa cấu hình khóa mã hóa sao lưu (BACKUP_ENCRYPTION_KEY hoặc APP_KEY).');
        }

        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7), true) ?: $secret;
        }

        return hash_hkdf('sha256', $secret, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'agrihub.backup.v1');
    }

    /**
     * Dấu vân tay của khóa hiện tại (16 ký tự hex): HMAC một chiều của một nhãn cố định, cắt ngắn. Không suy ngược ra khóa.
     * Dùng để ghi lại mỗi bản sao lưu được mã hóa bằng khóa nào và để người quản trị đối chiếu khóa họ đã cất.
     */
    public function keyFingerprint(): string
    {
        return substr(hash_hmac('sha256', 'agrihub.backup.key-id', $this->key()), 0, 16);
    }

    /** Khóa riêng cho sao lưu (BACKUP_ENCRYPTION_KEY) hay đang dùng chung APP_KEY. */
    public function hasDedicatedKey(): bool
    {
        return (string) config('backup.encryption_key') !== '';
    }

    /** Mã hóa $plainPath thành $destPath. */
    public function encryptFile(string $plainPath, string $destPath): void
    {
        $in = $this->open($plainPath, 'rb');

        try {
            $this->encryptStream($in, $destPath);
        } finally {
            fclose($in);
        }
    }

    /**
     * Mã hóa dữ liệu đọc từ $in (file hoặc pipe, vd. đầu ra của pg_dump) thành $destPath, không bao giờ ghi bản rõ ra đĩa.
     * $prefix được đặt TRƯỚC dữ liệu (vd. dòng mô tả). Không đóng $in. Dữ liệu được gom đủ từng khối nên pipe trả về
     * từng mẩu nhỏ cũng cho ra file giống file thường.
     *
     * @param  resource  $in
     */
    public function encryptStream($in, string $destPath, string $prefix = ''): void
    {
        $out = $this->open($destPath, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
            $this->write($out, self::MAGIC.$header);

            $pending = $prefix;
            $current = $this->readChunk($in, $pending);
            do {
                $next = $this->readChunk($in, $pending);
                $last = $next === '';
                $tag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, '', $tag);
                $this->write($out, pack('N', strlen($cipher)).$cipher);
                $current = $next;
            } while (! $last);
        } finally {
            fclose($out);
        }
    }

    /**
     * Lấy một khối đầy đủ từ $pending (bổ sung từ $in khi thiếu); chỉ trả chuỗi rỗng khi đã hết dữ liệu.
     *
     * @param  resource  $in
     */
    private function readChunk($in, string &$pending): string
    {
        while (strlen($pending) < self::CHUNK && ! feof($in)) {
            $piece = fread($in, self::CHUNK);

            if ($piece === false) {
                throw new RuntimeException('Không đọc được dữ liệu nguồn khi mã hóa.');
            }

            $pending .= $piece;
        }

        $chunk = substr($pending, 0, self::CHUNK);
        $pending = (string) substr($pending, self::CHUNK);

        return $chunk;
    }

    /**
     * Giải mã $srcPath ra $destPath. Ném BackupException nếu file không do AgriHub tạo, bị sửa, bị cắt bớt
     * hoặc vượt $maxBytes sau giải mã.
     */
    public function decryptFile(string $srcPath, string $destPath, int $maxBytes): void
    {
        $out = $this->open($destPath, 'wb');

        try {
            $this->decryptStream($srcPath, fn (string $plain) => $this->write($out, $plain), $maxBytes);
        } finally {
            fclose($out);
        }
    }

    /**
     * Giải mã theo luồng: gọi $sink với từng đoạn dữ liệu đã được xác thực. Đoạn nào chưa qua xác thực thì không bao giờ
     * được đưa ra; nhưng nếu file bị cắt/sửa ở phần sau thì lỗi chỉ xuất hiện khi tới đó — muốn chắc chắn trước khi dùng
     * thì chạy một lượt với $sink rỗng để xác thực toàn bộ file.
     *
     * @param  callable(string): void  $sink
     */
    public function decryptStream(string $srcPath, callable $sink, int $maxBytes): void
    {
        $in = $this->open($srcPath, 'rb');
        $invalid = new BackupException('File sao lưu không hợp lệ, đã bị chỉnh sửa hoặc không do hệ thống này tạo ra.');

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw $invalid;
            }

            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen((string) $header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw $invalid;
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key());
            $maxCipher = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            $written = 0;
            $final = false;

            while (! $final) {
                $lenBytes = fread($in, 4);
                if (strlen((string) $lenBytes) !== 4) {
                    throw $invalid; // cắt bớt: chưa gặp khối FINAL
                }

                $len = unpack('N', $lenBytes)[1];
                if ($len < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $len > $maxCipher) {
                    throw $invalid;
                }

                $cipher = fread($in, $len);
                if (strlen((string) $cipher) !== $len) {
                    throw $invalid;
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw $invalid;
                }

                [$plain, $tag] = $result;
                $written += strlen($plain);
                if ($written > $maxBytes) {
                    throw new BackupException('File sao lưu quá lớn sau khi giải nén.');
                }

                $sink($plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (fread($in, 1) !== '') {
                throw $invalid; // dữ liệu thừa sau khối FINAL
            }
        } finally {
            fclose($in);
        }
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Không mở được file: {$path}");
        }

        return $handle;
    }

    /** @param resource $handle */
    private function write($handle, string $data): void
    {
        if (fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('Không ghi được file (hết dung lượng?).');
        }
    }
}
