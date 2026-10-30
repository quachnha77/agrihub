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

    /** Mã hóa $plainPath thành $destPath. */
    public function encryptFile(string $plainPath, string $destPath): void
    {
        $in = $this->open($plainPath, 'rb');
        $out = $this->open($destPath, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
            $this->write($out, self::MAGIC.$header);

            $current = (string) fread($in, self::CHUNK);
            do {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
                $last = $next === '' && feof($in);
                $tag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, '', $tag);
                $this->write($out, pack('N', strlen($cipher)).$cipher);
                $current = $next;
            } while (! $last);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Giải mã $srcPath ra $destPath. Ném BackupException nếu file không do AgriHub tạo, bị sửa, bị cắt bớt
     * hoặc vượt $maxBytes sau giải mã.
     */
    public function decryptFile(string $srcPath, string $destPath, int $maxBytes): void
    {
        $in = $this->open($srcPath, 'rb');
        $out = $this->open($destPath, 'wb');
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

                $this->write($out, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (fread($in, 1) !== '') {
                throw $invalid; // dữ liệu thừa sau khối FINAL
            }
        } finally {
            fclose($in);
            fclose($out);
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
