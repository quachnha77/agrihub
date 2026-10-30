<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecoveryRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Jobs\Auth\SendRecoveryEmail;
use App\Services\Auth\PasswordRecovery;
use App\Support\Auth\LoginScope;
use App\Support\Tenancy\HostTenant;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Quên mật khẩu / quên tài khoản (công khai, không cần đăng nhập). Hai API đầu LUÔN trả lời giống hệt nhau (202) dù tài khoản có
 * tồn tại hay không; việc tìm tài khoản và gửi email chạy trong hàng đợi (xem SendRecoveryEmail).
 */
class PasswordRecoveryController extends Controller
{
    public function __construct(private HostTenant $host, private PasswordRecovery $recovery) {}

    public function forgotPassword(RecoveryRequest $request): JsonResponse
    {
        return $this->accepted(
            SendRecoveryEmail::PASSWORD, $request,
            'Nếu thông tin khớp với một tài khoản, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu tới email của tài khoản đó. Hãy kiểm tra hộp thư (kể cả thư rác).',
        );
    }

    public function forgotAccount(RecoveryRequest $request): JsonResponse
    {
        return $this->accepted(
            SendRecoveryEmail::ACCOUNT, $request,
            'Nếu thông tin khớp với một tài khoản, chúng tôi đã gửi thông tin tài khoản (địa chỉ đăng nhập, email đăng nhập) tới email của tài khoản đó. Hãy kiểm tra hộp thư (kể cả thư rác).',
        );
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->recovery->reset($request->validated('email'), $request->validated('token'), $request->validated('password'));

        return response()->json(['message' => 'Đã đặt lại mật khẩu. Hãy đăng nhập bằng mật khẩu mới.']);
    }

    private function accepted(string $kind, RecoveryRequest $request, string $message): JsonResponse
    {
        try {
            dispatch(SendRecoveryEmail::for($kind, $request->identifier(), LoginScope::fromHost($this->host), $request->ip()));
        } catch (Throwable $e) {
            // Hàng đợi hỏng (hoặc chạy đồng bộ mà gửi thư lỗi) không được làm phản hồi khác đi: người gọi không biết tài khoản có tồn tại hay không.
            report($e);
        }

        return response()->json(['message' => $message], 202);
    }
}
