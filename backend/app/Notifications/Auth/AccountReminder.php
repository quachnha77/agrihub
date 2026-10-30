<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email "nhắc tài khoản" cho người quên mình đăng nhập ở đâu / bằng gì: địa chỉ đăng nhập của doanh nghiệp, email đăng nhập,
 * số điện thoại (che bớt). Chỉ gửi tới email của chính tài khoản đó nên không tiết lộ gì cho người khác.
 */
class AccountReminder extends Notification
{
    public function __construct(
        public readonly string $loginUrl,
        public readonly ?string $businessName,
        public readonly string $email,
        public readonly ?string $maskedPhone,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('['.config('app.name').'] Thông tin tài khoản của bạn')
            ->greeting('Xin chào '.$notifiable->name.',')
            ->line('Chúng tôi nhận được yêu cầu nhắc thông tin tài khoản gắn với email này. Đây là thông tin của bạn:');

        if ($this->businessName !== null) {
            $mail->line('Doanh nghiệp: '.$this->businessName);
        }

        $mail->line('Email đăng nhập: '.$this->email);

        if ($this->maskedPhone !== null) {
            $mail->line('Số điện thoại đăng nhập: '.$this->maskedPhone);
        }

        return $mail
            ->action('Mở trang đăng nhập', $this->loginUrl)
            ->line('Nếu bạn cũng quên mật khẩu, hãy chọn "Quên mật khẩu" ở trang đăng nhập.')
            ->line('Nếu bạn không yêu cầu email này, hãy bỏ qua: không có gì thay đổi với tài khoản của bạn.')
            ->salutation('AgriHub');
    }
}
