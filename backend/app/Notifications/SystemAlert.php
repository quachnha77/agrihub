<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Thông báo hệ thống (cảnh báo sao lưu, ...). Một nội dung, nhiều kênh: trong ứng dụng (InAppChannel) và email.
 * Kênh Zalo OA / SMS sẽ thêm ở đây bằng một channel mới, không đổi nơi phát sinh thông báo.
 *
 * Không được đưa dữ liệu nghiệp vụ của khách hàng vào title/body: email đi ra ngoài hệ thống.
 */
class SystemAlert extends Notification
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly string $level,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly array $data = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [InAppChannel::class, 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('['.config('app.name').'] '.$this->title)
            ->line($this->title);

        foreach (array_filter(explode("\n", (string) $this->body)) as $line) {
            $mail->line($line);
        }

        if ($this->level === 'critical') {
            $mail->level('error');
        }

        return $mail->line('Đây là thông báo tự động của hệ thống '.config('app.name').'.');
    }
}
