<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Báo cho chủ tài khoản biết mật khẩu vừa được đặt lại bằng liên kết (để phát hiện sớm nếu không phải họ làm). */
class PasswordWasReset extends Notification
{
    public function __construct(public readonly string $loginUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.config('app.name').'] Mật khẩu của bạn vừa được đặt lại')
            ->greeting('Xin chào '.$notifiable->name.',')
            ->line('Mật khẩu của tài khoản này vừa được đặt lại bằng liên kết gửi qua email. Mọi thiết bị đang đăng nhập đã bị đăng xuất.')
            ->line('Nếu không phải bạn thực hiện, hãy đặt lại mật khẩu ngay và báo cho quản trị viên của doanh nghiệp.')
            ->action('Mở trang đăng nhập', $this->loginUrl)
            ->salutation('AgriHub');
    }
}
