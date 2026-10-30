<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'agrihub:create-platform-admin {email : Email đăng nhập} {--name= : Tên hiển thị}';

    protected $description = 'Tạo tài khoản Platform Admin (Super Admin) không thuộc Tenant nào';

    public function handle(TenantContext $context): int
    {
        $password = $this->secret('Mật khẩu (tối thiểu 12 ký tự)');
        $confirm = $this->secret('Nhập lại mật khẩu');

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password, 'password_confirmation' => $confirm],
            ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'min:12', 'confirmed']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $email = mb_strtolower(trim($this->argument('email')));

        $created = $context->runAsPlatform(function () use ($email, $password) {
            if (User::withTrashed()->where('email', $email)->exists()) {
                return false;
            }

            $user = new User([
                'name' => $this->option('name') ?: 'Platform Admin',
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'email_verified_at' => now()])->save();

            return true;
        });

        if (! $created) {
            $this->error("Email {$email} đã tồn tại.");

            return self::FAILURE;
        }

        $this->info("Đã tạo Platform Admin {$email}.");

        return self::SUCCESS;
    }
}
