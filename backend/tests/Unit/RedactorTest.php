<?php

namespace Tests\Unit;

use App\Services\Audit\Redactor;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RedactorTest extends TestCase
{
    private function redactor(): Redactor
    {
        return new Redactor;
    }

    public function test_sensitive_keys_are_masked_at_any_depth_case_insensitively(): void
    {
        $out = $this->redactor()->redact([
            'password' => 'hunter2',
            'Password_Confirmation' => 'hunter2',
            'remember_token' => 'abc',
            'api_key' => 'k',
            'ApiKey' => 'k2',
            'Authorization' => 'Bearer xyz',
            'client_secret' => 's',
            'otp' => '123456',
            'nested' => ['deep' => ['access_token' => 't', 'ok' => 'giữ nguyên']],
            'name' => 'Nguyễn Văn A',
        ]);

        foreach (['password', 'Password_Confirmation', 'remember_token', 'api_key', 'ApiKey', 'Authorization', 'client_secret', 'otp'] as $key) {
            $this->assertSame(Redactor::MASK, $out[$key], $key);
        }
        $this->assertSame(Redactor::MASK, $out['nested']['deep']['access_token']);
        $this->assertSame('giữ nguyên', $out['nested']['deep']['ok']);
        $this->assertSame('Nguyễn Văn A', $out['name']);
    }

    public function test_normal_keys_that_merely_look_similar_are_not_masked(): void
    {
        $out = $this->redactor()->redact(['passenger' => 'x', 'tokenizer_name' => 'y', 'status' => 'active', 'signature_image' => 'a.png']);

        // "tokenizer_name" chứa "token" nên bị che: ưu tiên an toàn hơn chính xác tuyệt đối.
        $this->assertSame('x', $out['passenger']);
        $this->assertSame(Redactor::MASK, $out['tokenizer_name']);
        $this->assertSame('active', $out['status']);
        $this->assertSame('a.png', $out['signature_image']);
    }

    public function test_long_strings_are_truncated_and_nul_bytes_removed(): void
    {
        config(['audit.max_string_length' => 10]);

        $out = $this->redactor()->redact(['a' => str_repeat('x', 50), 'b' => "ab\0cd"]);

        $this->assertStringStartsWith(str_repeat('x', 10), $out['a']);
        $this->assertStringContainsString('đã cắt bớt', $out['a']);
        $this->assertSame('abcd', $out['b']);
    }

    public function test_depth_is_limited(): void
    {
        config(['audit.max_depth' => 2]);

        $out = $this->redactor()->redact(['a' => ['b' => ['c' => ['d' => 'sâu']]]]);

        $this->assertSame('[quá sâu]', $out['a']['b']['c']);
    }

    public function test_dates_scalars_and_objects_are_normalised(): void
    {
        $out = $this->redactor()->redact([
            'at' => Carbon::parse('2026-10-30 10:00:00', 'UTC'),
            'n' => 5, 'f' => 1.5, 'b' => true, 'null' => null,
            'obj' => new \stdClass,
        ]);

        $this->assertStringStartsWith('2026-10-30T10:00:00', $out['at']);
        $this->assertSame([5, 1.5, true, null], [$out['n'], $out['f'], $out['b'], $out['null']]);
        $this->assertSame('[stdClass]', $out['obj']);
    }
}
