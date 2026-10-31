<?php

namespace App\Notifications;

use App\Models\Notice;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Kênh "trong ứng dụng": ghi một dòng vào `notices` cho người nhận (hiện khi họ đăng nhập).
 *
 * Không nhận TenantContext qua constructor: ChannelManager giữ nguyên một đối tượng kênh suốt vòng đời tiến trình (queue worker chạy
 * hàng giờ), trong khi TenantContext là "scoped" và được tạo lại cho mỗi job. Giữ bản cũ thì quyền nền tảng đặt trên bản cũ còn
 * model đọc bản mới => từ job thứ hai trở đi mọi thông báo đều lỗi "Không có Tenant context". Vì vậy luôn lấy bản hiện tại lúc gửi.
 */
class InAppChannel
{
    public function send(object $notifiable, SystemAlert $alert): void
    {
        if (! $notifiable instanceof User) {
            return; // địa chỉ email ngoài hệ thống không có hộp thông báo
        }

        app(TenantContext::class)->runAsPlatform(function () use ($notifiable, $alert) {
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
