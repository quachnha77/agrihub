<?php

namespace App\Support\Tenancy;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Xác thực phải tìm user trước khi biết Tenant, nên các thao tác tìm/cập nhật user chạy ở chế độ nền tảng.
 * Sau khi xác thực, middleware SetTenantContext đặt context về đúng Tenant của user.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return $this->asPlatform(fn () => parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return $this->asPlatform(fn () => parent::retrieveByToken($identifier, $token));
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        $this->asPlatform(fn () => parent::updateRememberToken($user, $token));
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        return $this->asPlatform(fn () => parent::retrieveByCredentials($credentials));
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
        $this->asPlatform(fn () => parent::rehashPasswordIfRequired($user, $credentials, $force));
    }

    private function asPlatform(\Closure $callback): mixed
    {
        return app(TenantContext::class)->runAsPlatform($callback);
    }
}
