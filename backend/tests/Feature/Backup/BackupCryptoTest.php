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
