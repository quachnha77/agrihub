<?php

namespace App\Notifications;

use App\Models\Notice;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/** Kênh "trong ứng dụng": ghi một dòng vào `notices` cho người nhận (hiện khi họ đăng nhập). */
class InAppChannel
{
    public function __construct(private TenantContext $context) {}

    public function send(object $notifiable, SystemAlert $alert): void
    {
        if (! $notifiable instanceof User) {
            return; // địa chỉ email ngoài hệ thống không có hộp thông báo
        }

        $this->context->runAsPlatform(function () use ($notifiable, $alert) {
            $notice = new Notice([
                'type' => $alert->type,
                'level' => $alert->level,
                'title' => mb_substr($alert->title, 0, 200),
                'body' => $alert->body,
                'data' => $alert->data === [] ? null : $alert->data,
            ]);
            $notice->tenant_id = $notifiable->tenant_id; // NULL với Platform Admin
            $notice->user_id = $notifiable->id;
            $notice->save();
        });
    }
}
