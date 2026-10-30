<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupCrypto;
use App\Services\Backup\BackupException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupCryptoTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tmp(string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agbk');
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }

    private function encrypt(string $plain): string
    {
        $enc = $this->tmp();
        app(BackupCrypto::class)->encryptFile($this->tmp($plain), $enc);

        return $enc;
    }

    private function decrypt(string $encPath, int $max = 10_000_000): string
    {
        $out = $this->tmp();
        app(BackupCrypto::class)->decryptFile($encPath, $out, $max);

        return file_get_contents($out);
    }

    public static function sizes(): array
    {
        return ['empty' => [0], 'tiny' => [5], 'exactly one chunk' => [65536], 'one chunk + 1' => [65537], 'many chunks' => [65536 * 3 + 123]];
    }

    #[DataProvider('sizes')]
    public function test_round_trip_for_every_chunk_boundary(int $size): void
    {
        $plain = $size === 0 ? '' : random_bytes($size);

        $this->assertSame($plain, $this->decrypt($this->encrypt($plain)));
    }

    public function test_ciphertext_does_not_contain_the_plaintext(): void
    {
        $enc = $this->encrypt('Nguyễn Văn A nợ 5.000.000 đồng — tuyệt mật');

        $this->assertStringNotContainsString('Nguyễn', file_get_contents($enc));
        $this->assertStringStartsWith(BackupCrypto::MAGIC, file_get_contents($enc));
    }

    public function test_every_single_byte_flip_is_detected(): void
    {
        $enc = $this->encrypt(str_repeat('abc', 100));
        $original = file_get_contents($enc);
        $headerLen = strlen(BackupCrypto::MAGIC);

        foreach ([0, $headerLen + 3, $headerLen + 30, strlen($original) - 1] as $position) {
            $broken = $original;
            $broken[$position] = chr(ord($broken[$position]) ^ 0x01);

            $this->expectBackupException(fn () => $this->decrypt($this->tmp($broken)));
        }
    }

    public function test_truncated_file_is_rejected_even_at_a_chunk_boundary(): void
    {
        $plain = random_bytes(65536 * 2 + 10);
        $original = file_get_contents($this->encrypt($plain));

        // Cắt bỏ khối cuối (khối FINAL) -> phải bị phát hiện dù các khối còn lại hợp lệ.
        $cipherChunk = 65536 + 17 + 4;
        $cut = substr($original, 0, strlen(BackupCrypto::MAGIC) + 24 + 2 * $cipherChunk);

        $this->expectBackupException(fn () => $this->decrypt($this->tmp($cut)));
    }

    public function test_trailing_data_and_wrong_magic_are_rejected(): void
    {
        $original = file_get_contents($this->encrypt('hello'));

        $this->expectBackupException(fn () => $this->decrypt($this->tmp($original.'x')));
        $this->expectBackupException(fn () => $this->decrypt($this->tmp('not a backup at all')));
        $this->expectBackupException(fn () => $this->decrypt($this->tmp('')));
    }

    public function test_a_file_made_with_another_key_is_rejected(): void
    {
        $enc = $this->encrypt('secret');

        config(['backup.encryption_key' => 'a-completely-different-key-of-sufficient-length']);

        $this->expectBackupException(fn () => $this->decrypt($enc));
    }

    public function test_decrypted_size_is_capped(): void
    {
        $enc = $this->encrypt(random_bytes(200000));

        $this->expectBackupException(fn () => $this->decrypt($enc, 1000));
    }

    /** Luồng chỉ trả vài byte mỗi lần đọc, như pipe của pg_dump. @return resource */
    private function trickle(string $content, int $bytesPerRead = 7)
    {
        TrickleStream::$data = $content;
        TrickleStream::$step = $bytesPerRead;

        if (! in_array('trickle', stream_get_wrappers(), true)) {
            stream_wrapper_register('trickle', TrickleStream::class);
        }

        return fopen('trickle://data', 'rb');
    }

    #[DataProvider('sizes')]
    public function test_a_stream_read_in_tiny_pieces_encrypts_to_the_same_content(int $size): void
    {
        $plain = $size === 0 ? '' : random_bytes($size);
        $enc = $this->tmp();
        $in = $this->trickle($plain);

        app(BackupCrypto::class)->encryptStream($in, $enc);
        fclose($in);

        $this->assertSame($plain, $this->decrypt($enc));
    }

    public function test_a_prefix_is_encrypted_in_front_of_the_stream_even_when_larger_than_a_chunk(): void
    {
        $plain = random_bytes(1000);
        $prefix = str_repeat('mô tả ', 30000); // > 64 KiB
        $enc = $this->tmp();
        $in = $this->trickle($plain, 5000);

        app(BackupCrypto::class)->encryptStream($in, $enc, $prefix);
        fclose($in);

        $this->assertSame($prefix.$plain, $this->decrypt($enc));
    }

    public function test_stream_decryption_hands_out_the_content_in_order_and_stops_at_a_tampered_chunk(): void
    {
        $plain = random_bytes(65536 * 3);
        $enc = $this->encrypt($plain);
        $crypto = app(BackupCrypto::class);

        $got = '';
        $crypto->decryptStream($enc, function (string $part) use (&$got): void {
            $got .= $part;
        }, 10_000_000);
        $this->assertSame($plain, $got);

        $bytes = file_get_contents($enc);
        $bytes[strlen($bytes) - 40] = chr(ord($bytes[strlen($bytes) - 40]) ^ 1); // chunk cuối bị sửa
        file_put_contents($enc, $bytes);

        $got = '';
        $this->expectBackupException(function () use ($crypto, $enc, &$got) {
            $crypto->decryptStream($enc, function (string $part) use (&$got): void {
                $got .= $part;
            }, 10_000_000);
        });
        $this->assertSame(substr($plain, 0, 65536 * 2), $got, 'chỉ các khối đã xác thực mới được đưa ra');
    }

    private function expectBackupException(callable $fn): void
    {
        try {
            $fn();
        } catch (BackupException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Đáng lẽ phải ném BackupException.');
    }
}

/** Luồng giả lập đọc từng mẩu nhỏ (không bao giờ trả đủ số byte yêu cầu, trừ khi hết dữ liệu). */
class TrickleStream
{
    public static string $data = '';

    public static int $step = 7;

    private int $pos = 0;

    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $piece = substr(self::$data, $this->pos, min($count, self::$step));
        $this->pos += strlen($piece);

        return $piece;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$data);
    }

    public function stream_stat(): array|false
    {
        return [];
    }
}
