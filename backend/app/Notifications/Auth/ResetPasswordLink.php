<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email có liên kết đặt lại mật khẩu. Liên kết dùng được một lần, hết hạn sau $minutes phút, và trỏ về ĐÚNG địa chỉ
 * đăng nhập của tài khoản (không bao giờ dựng từ header Host của request).
 *
 * Gửi đồng bộ (notifyNow) từ một job đã nằm trong hàng đợi: không tuần tự hóa model User ra hàng đợi (RLS sẽ che khi nạp lại).
 */
class ResetPasswordLink extends Notification
{
    public function __construct(public readonly string $url, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.config('app.name').'] Đặt lại mật khẩu')
            ->greeting('Xin chào '.$notifiable->name.',')
            ->line('Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn.')
            ->action('Đặt lại mật khẩu', $this->url)
            ->line("Liên kết có hiệu lực trong {$this->minutes} phút và chỉ dùng được một lần.")
            ->line('Nếu bạn không yêu cầu, hãy bỏ qua email này: mật khẩu hiện tại vẫn giữ nguyên.')
            ->salutation('AgriHub');
    }
}
