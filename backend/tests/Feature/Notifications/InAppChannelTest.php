<?php

namespace Tests\Feature\Notifications;

use App\Models\Notice;
use App\Notifications\InAppChannel;
use App\Notifications\SystemAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class InAppChannelTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * Lỗi thật gặp ở queue worker: đối tượng kênh sống suốt tiến trình còn TenantContext (scoped) được tạo lại cho mỗi job.
     * Kênh giữ bản cũ thì từ job thứ hai trở đi không ghi được thông báo (bị nuốt, chỉ còn dòng log). Test chạy cùng một
     * tiến trình nên mô phỏng bằng cách xóa các đối tượng scoped giữa hai lần gửi.
     */
    public function test_it_keeps_working_after_the_scoped_context_is_recreated_like_between_two_queue_jobs(): void
    {
        $admin = $this->makePlatformAdmin();
        [, $owner] = $this->makeTenant();
        $channel = app(InAppChannel::class); // một đối tượng kênh dùng lại cho mọi job

        $channel->send($admin, new SystemAlert('test.first', 'info', 'Lần một', 'Nội dung'));

        app()->forgetScopedInstances(); // như giữa hai job của queue worker
        $channel->send($admin, new SystemAlert('test.second', 'info', 'Lần hai', 'Nội dung'));
        $channel->send($owner, new SystemAlert('test.third', 'info', 'Lần ba, cho người dùng doanh nghiệp', 'Nội dung'));

        $types = $this->platform(fn () => Notice::orderBy('id')->pluck('type')->all());
        $this->assertSame(['test.first', 'test.second', 'test.third'], $types);
    }
}
