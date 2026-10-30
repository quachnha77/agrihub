<?php

namespace App\Jobs\Auth;

use App\Services\Auth\PasswordRecovery;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\LoginScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tìm tài khoản và gửi email khôi phục NGOÀI request. Request chỉ kiểm tra định dạng rồi trả lời chung, nên thời gian và nội dung phản hồi
 * giống hệt nhau dù tài khoản có tồn tại hay không (không dò được tài khoản). Chỉ mang dữ liệu thuần (không model) vì RLS che model khi nạp lại.
 */
class SendRecoveryEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const PASSWORD = 'password';

    public const ACCOUNT = 'account';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $kind,
        public readonly string $login,
        public readonly string $scopeKind,
        public readonly ?int $scopeTenantId,
        public readonly ?string $ip,
    ) {}

    public static function for(string $kind, LoginIdentifier $identifier, LoginScope $scope, ?string $ip): self
    {
        return new self($kind, $identifier->value, $scope->kind, $scope->tenantId, $ip);
    }

    public function handle(PasswordRecovery $recovery): void
    {
        $identifier = LoginIdentifier::parse($this->login);

        if ($identifier === null) {
            return;
        }

        $scope = new LoginScope($this->scopeKind, $this->scopeTenantId);

        match ($this->kind) {
            self::PASSWORD => $recovery->sendResetLink($identifier, $scope, $this->ip),
            self::ACCOUNT => $recovery->sendAccountReminder($identifier, $scope, $this->ip),
            default => null,
        };
    }
}
